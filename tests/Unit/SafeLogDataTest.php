<?php

namespace Tests\Unit;

use App\Logging\MysqlLoggerHandler;
use App\Logging\SafeLogData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Tests\Support\AccountingDatabase;

class SafeLogDataTest extends TestCase
{
    private $app;
    private $db;

    protected function setUp(): void
    {
        [$this->app, $this->db] = AccountingDatabase::boot();
        AccountingDatabase::schema($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->disconnect();
        Facade::clearResolvedInstances();
    }

    private function write(Request $request, array $context = [], $message = 'Fixture warning')
    {
        $this->app->instance('request', $request);
        $handler = new class extends MysqlLoggerHandler {
            public function fixture($record) { $this->write($record); }
        };
        $handler->fixture(new \Monolog\LogRecord(new \DateTimeImmutable(), 'fixture', \Monolog\Level::Error, $message, $context));
        return $this->db->table('v2_log')->latest('id')->first();
    }

    public function testBodyQueryAndContextSecretsAreRedacted(): void
    {
        $request = Request::create('https://example.invalid/fixture?token=fixture-query-secret', 'POST',
            ['password' => 'fixture-password', 'email_code' => '123456', 'user_id' => 42]);
        $row = $this->write($request, ['api_key' => 'fixture-api-secret', 'nested' => ['Authorization' => 'fixture-auth']]);
        $this->assertNotNull($row);
        $json = json_encode($row);
        foreach (['fixture-query-secret', 'fixture-password', '123456', 'fixture-api-secret', 'fixture-auth'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame('/fixture', $row->uri);
        $this->assertSame(42, json_decode($row->data, true)['body']['user_id']);
    }

    public function testJsonBodySecretsAlsoDisappearFromMessage(): void
    {
        $request = Request::create('https://example.invalid/fixture', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['password' => 'fixture-json-secret']));
        $row = $this->write($request, [], 'Failure with fixture-json-secret');
        $this->assertStringNotContainsString('fixture-json-secret', json_encode($row));
    }

    public function testHeaderCredentialsAreRemovedFromExceptionMessages(): void
    {
        $request = Request::create('/fixture', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'opaque-fixture-credential',
            'HTTP_X_TG_SIGNATURE' => 'fixture-telegram-signature',
            'HTTP_COOKIE' => 'fixture-session-cookie',
        ]);
        $row = $this->write($request, ['exception' => new \RuntimeException('Failed opaque-fixture-credential')],
            'Upstream echoed opaque-fixture-credential fixture-telegram-signature fixture-session-cookie');
        foreach (['opaque-fixture-credential', 'fixture-telegram-signature', 'fixture-session-cookie'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($row));
        }
    }

    public function testExceptionKeepsLocationButNeverSerializesTraceArguments(): void
    {
        $exception = new \RuntimeException('SQLSTATE[23000]: query contains fixture-db-secret');
        $row = $this->write(Request::create('/fixture'), ['exception' => $exception]);
        $context = json_decode($row->context, true)['exception'];
        $this->assertSame(\RuntimeException::class, $context['class']);
        $this->assertArrayHasKey('file', $context);
        $this->assertStringContainsString('SQLSTATE[23000]', $context['message']);
        $this->assertStringNotContainsString('fixture-db-secret', json_encode($row));
        foreach ($context['trace'] as $frame) $this->assertArrayNotHasKey('args', $frame);
    }

    public function testPayloadSizeAndTraversalAreBounded(): void
    {
        $input = array_fill(0, 100, str_repeat('x', 100000));
        $input = array_fill(0, 100, array_fill(0, 100, $input));
        $safe = new SafeLogData();
        $result = $safe->json($safe->fields($input));
        $this->assertLessThanOrEqual(16384, strlen($result));
        $this->assertStringNotContainsString(str_repeat('x', 20), $result);
        $this->assertIsArray(json_decode($result, true));
    }

    public function testArbitraryLogObjectsAreNotExpanded(): void
    {
        $object = new class {
            public function __toString() { throw new \RuntimeException('must not serialize'); }
        };
        $safe = new SafeLogData();
        $this->assertSame(['payload' => '[redacted]'], $safe->fields(['payload' => $object]));
    }

    public function testDiagnosticMessagesMaskLinksAndCredentials(): void
    {
        $safe = new SafeLogData();
        $value = $safe->message('authorization: Bearer fixture-bearer password=fixture-pass https://u:fixture-pw@example.invalid/p?token=fixture-link somebody@example.invalid');
        foreach (['fixture-bearer', 'fixture-pass', 'fixture-pw', 'fixture-link', 'somebody@example.invalid'] as $secret) {
            $this->assertStringNotContainsString($secret, $value);
        }
    }
}

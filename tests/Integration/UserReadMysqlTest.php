<?php

namespace Tests\Integration;

use App\Models\User;
use App\Services\UserBatchService;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Tests\Support\UserReadDatabase;

class UserReadMysqlTest extends TestCase
{
    private $app;
    private $db;
    private $other;

    protected function setUp(): void
    {
        if (!getenv('ORDER_TEST_CONNECTION')) $this->markTestSkipped('Requires disposable MySQL');
        $config = json_decode(getenv('ORDER_TEST_CONNECTION'), true);
        if (!preg_match('~^/tmp/order-safety-test-[a-f0-9]+/test.sock$~D', $config['unix_socket'] ?? '')
            || $config['database'] !== 'order_safety_test' || ($config['host'] ?? '') !== '') {
            throw new \RuntimeException('Refusing a non-isolated database');
        }
        [$this->app, $this->db] = UserReadDatabase::boot($config);
        UserReadDatabase::seed($this->db, 1203);
        $this->other = new \PDO('mysql:unix_socket=' . $config['unix_socket'] . ';dbname=order_safety_test', 'root', '',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->other->exec('SET SESSION innodb_lock_wait_timeout=1');
        $this->db->getPdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }

    protected function tearDown(): void
    {
        $this->other = null;
        if ($this->db) $this->db->disconnect();
        Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); Container::setInstance(null);
        \Mockery::close();
    }

    public static function orders(): array { return [[true], [false]]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('orders')]
    public function testSnapshotDoesNotSkipDuplicateOrChangeRowsDuringConcurrentEdits(bool $byId): void
    {
        $query = User::where('banned', 1)->orderBy('created_at', 'DESC');
        $expectedQuery = clone $query;
        if ($byId) $expectedQuery->reorder()->orderBy('id');
        else $expectedQuery->orderBy('id');
        $expected = $expectedQuery->get(['id', 'email'])->toArray();
        $actual = [];
        UserBatchService::each($query, ['id', 'email'], function ($user) use (&$actual) {
            $actual[] = $user->toArray();
            if (count($actual) !== 1) return;
            $this->other->exec("DELETE FROM v2_user WHERE id IN (1, 1001)");
            $this->other->exec("UPDATE v2_user SET email='changed@example.invalid', banned=0, created_at=100 WHERE id=1201");
            $this->other->exec("INSERT INTO v2_user(id,email,banned,created_at) VALUES(2001,'new@example.invalid',1,100)");
        }, $byId);
        $this->assertSame($expected, $actual);
        $this->assertSame(0, $this->db->transactionLevel());
        $this->assertSame('READ-COMMITTED', $this->db->selectOne('SELECT @@session.tx_isolation AS isolation')->isolation);
        $this->assertSame('changed@example.invalid', User::find(1201)->email);
    }

    public function testPhpRedisManyMatchesIndividualReadsIncludingMissingAndSerializedValues(): void
    {
        $socket = getenv('ACCOUNTING_TEST_REDIS');
        $this->assertMatchesRegularExpression('~^/tmp/order-safety-test-[a-f0-9]+/redis.sock$~D', $socket);
        $redis = new RedisManager($this->app, 'phpredis', ['default' => ['host' => $socket, 'port' => 0, 'database' => 0]]);
        $cache = new Repository(new RedisStore($redis, 'fixture:user-read:'));
        $cache->put('one', ['alive_ip' => 2, 'hy2_1' => ['aliveips' => ['2001:db8::1_node']]], 30);
        $cache->put('two', ['alive_ip' => 0], 30);
        $expected = [];
        foreach (['one', 'two', 'missing'] as $key) $expected[$key] = $cache->get($key);
        $this->assertSame($expected, $cache->many(['one', 'two', 'missing']));
        $cache->forget('one');
        $this->assertNull($cache->many(['one'])['one']);
        $redis->connection()->disconnect();
    }
}

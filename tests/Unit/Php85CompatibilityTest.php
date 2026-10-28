<?php

namespace Tests\Unit;

use Curl\Curl;
use Tests\TestCase;

class Php85CompatibilityTest extends TestCase
{
    public function testCurlClientRetainsJsonResponsesAndFormRequestWithoutExternalNetwork(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($server, $error);
        $address = stream_socket_get_name($server, false);
        $pid = pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            $connection = stream_socket_accept($server, 10);
            if (!$connection) exit(2);
            stream_set_timeout($connection, 5);
            $headers = '';
            while (($line = fgets($connection)) !== false && $line !== "\r\n") $headers .= $line;
            preg_match('/Content-Length:\s*(\d+)/i', $headers, $matches);
            $length = (int)($matches[1] ?? 0);
            $body = '';
            while (strlen($body) < $length) {
                $chunk = fread($connection, $length - strlen($body));
                if ($chunk === '' || $chunk === false) break;
                $body .= $chunk;
            }
            $response = json_encode(['ok' => true, 'data' => ['trade_no' => 'fixture',
                'pay_url' => 'https://example.invalid/pay'], 'body' => $body,
                'agent_present' => str_contains($headers, 'User-Agent: MGate')]);
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: "
                . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
            fclose($connection);
            fclose($server);
            exit(0);
        }
        fclose($server);
        $curl = new Curl();
        try {
            $curl->setUserAgent('MGate');
            $curl->setOpt(CURLOPT_TIMEOUT, 5);
            $curl->post('http://' . $address . '/fixture', 'amount=100&order_id=fixture');
            $result = $curl->response;
            $this->assertFalse($curl->error);
            $this->assertSame('fixture', $result->data->trade_no);
            $this->assertSame('https://example.invalid/pay', $result->data->pay_url);
            $this->assertSame('amount=100&order_id=fixture', $result->body);
            $this->assertTrue($result->agent_present);
            $curl->close();
            $this->assertNull($curl->curl);
            $this->assertSame('fixture', $result->data->trade_no);
        } finally {
            $curl->close();
            pcntl_waitpid($pid, $status);
        }
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    public function testDatabaseSslOptionAndBinaryCacheSerializationRemainAvailable(): void
    {
        $this->assertIsInt(\Pdo\Mysql::ATTR_SSL_CA);
        $value = ['email' => 'fixture@example.invalid', 'bytes' => "\0\xff", 'quota' => 10737418240];
        $this->assertSame($value, igbinary_unserialize(igbinary_serialize($value)));
        $this->assertSame($value, unserialize(serialize($value), ['allowed_classes' => false]));
    }

    public function testHorizonRetainsTheExistingMemoryFormulaAndWorkerCap(): void
    {
        $configuration = require config_path('horizon.php');
        $supervisor = $configuration['environments']['local']['V2board'];
        preg_match('/^MemTotal:\s+(\d+)\s+kB$/m', file_get_contents('/proc/meminfo'), $memory);
        $expected = max(1, min((int)ceil((int)$memory[1] / 1024 / 1024 * 6), (int)env('HORIZON_MAX_PROCESSES', 19)));
        $this->assertSame($expected, $supervisor['maxProcesses']);
        $this->assertSame(1, $supervisor['minProcesses']);
        $this->assertSame(['order_handle', 'traffic_fetch', 'stat', 'send_email', 'send_email_mass'], $supervisor['queue']);
    }
}

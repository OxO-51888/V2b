<?php

namespace Tests\Integration;

use Illuminate\Cache\RedisStore;
use Illuminate\Redis\RedisManager;
use Tests\Support\ReadPerformanceTestCase;

class ReadPerformanceMysqlTest extends ReadPerformanceTestCase
{
    protected function connection(): ?array
    {
        $config = json_decode(getenv('ORDER_TEST_CONNECTION') ?: '', true);
        if (!$config) $this->markTestSkipped('Requires disposable MySQL');
        if (($config['driver'] ?? '') !== 'mysql' || ($config['database'] ?? '') !== 'order_safety_test'
            || ($config['host'] ?? '') !== '' || !preg_match('#^/tmp/order-safety-test-[a-f0-9]+/test.sock$#', $config['unix_socket'] ?? '')) {
            throw new \RuntimeException('Refusing non-isolated MySQL');
        }
        return $config;
    }

    protected function cacheStore()
    {
        $socket = getenv('ACCOUNTING_TEST_REDIS');
        $this->assertMatchesRegularExpression('#^/tmp/order-safety-test-[a-f0-9]+/redis.sock$#', $socket);
        $this->redis = new RedisManager($this->app, 'phpredis', ['default' => ['host' => $socket, 'port' => 0, 'database' => 0]]);
        return new RedisStore($this->redis, 'fixture:read-performance:' . bin2hex(random_bytes(8)) . ':');
    }
}

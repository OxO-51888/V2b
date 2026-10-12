<?php

namespace Tests\Support;

use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Redis;

class AccountingRedis
{
    public static function connect($app): void
    {
        $socket = getenv('ACCOUNTING_TEST_REDIS');
        if (!preg_match('~^/tmp/order-safety-test-[a-f0-9]+/redis\.sock$~D', $socket ?: '')) {
            throw new \RuntimeException('Refusing a non-isolated Redis');
        }
        $driver = extension_loaded('redis') ? 'phpredis' : 'predis';
        $connection = $driver === 'phpredis'
            ? ['host' => $socket, 'port' => 0, 'database' => 0, 'timeout' => 3, 'read_timeout' => 10]
            : ['scheme' => 'unix', 'path' => $socket, 'database' => 0, 'read_write_timeout' => 10];
        Redis::swap(new RedisManager($app, $driver, ['default' => $connection]));
    }
}

<?php

namespace Tests\Integration;

use Tests\Support\OrderEmailFilterTestCase;

class OrderEmailFilterMysqlTest extends OrderEmailFilterTestCase
{
    protected function connection(): ?array
    {
        $connection = json_decode(getenv('ORDER_TEST_CONNECTION') ?: '', true);
        if (!$connection) $this->markTestSkipped('Isolated MySQL fixture is not configured');
        if (($connection['driver'] ?? null) !== 'mysql'
            || ($connection['database'] ?? null) !== 'order_safety_test'
            || ($connection['host'] ?? null) !== ''
            || !preg_match('#^/tmp/order-safety-test-[a-f0-9]+/test.sock$#', $connection['unix_socket'] ?? '')) {
            throw new \RuntimeException('Refusing non-isolated MySQL database');
        }
        return $connection;
    }

    public function testEmailUsesDatabaseCollation(): void
    {
        $response = $this->fetch([['key' => 'email', 'condition' => '=', 'value' => 'ALPHA@EXAMPLE.INVALID']]);
        $this->assertSame(12, $response['total']);
        $this->assertSame(range(12, 1), array_column($response['data'], 'id'));
    }
}

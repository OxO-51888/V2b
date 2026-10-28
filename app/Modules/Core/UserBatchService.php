<?php

namespace App\Modules\Core;

use Illuminate\Database\Eloquent\Builder;

class UserBatchService
{
    public const BATCH_SIZE = 500;

    public static function each(Builder $query, array $columns, callable $callback, bool $byId = true): void
    {
        $query = clone $query;
        $query->select($columns);
        $connection = $query->getConnection();
        // Keep all pages in one snapshot, even when users are edited during a scan.
        if ($connection->getDriverName() === 'mysql' && $connection->transactionLevel() === 0) {
            $connection->getPdo()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        // Never retry this transaction: callbacks may already have queued email.
        $connection->transaction(function () use ($query, $callback, $byId) {
            $consume = function ($users) use ($callback) {
                foreach ($users as $user) $callback($user);
            };
            if ($byId) {
                $query->reorder()->chunkById(self::BATCH_SIZE, $consume);
            } else {
                $query->orderBy('id')->chunk(self::BATCH_SIZE, $consume);
            }
        }, 1);
    }
}

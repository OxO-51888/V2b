<?php

namespace Tests\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Response;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserReadDatabase
{
    public static function boot(?array $connection = null): array
    {
        [$app, $db] = OrderDatabase::boot($connection);
        foreach (['v2_order', 'v2_user', 'v2_plan'] as $table) $db->getSchemaBuilder()->dropIfExists($table);
        OrderDatabase::schema($db);
        $db->getSchemaBuilder()->table('v2_user', function ($table) {
            foreach (['email', 'token', 'uuid', 'remarks'] as $field) $table->text($field)->nullable();
            foreach (['commission_balance', 'remind_expire', 'remind_traffic', 'banned', 'is_admin'] as $field) {
                $table->integer($field)->default(0);
            }
            $table->integer('invite_user_id')->nullable();
        });
        $db->getSchemaBuilder()->table('v2_plan', function ($table) { $table->string('name')->nullable(); });
        $app['config']->set('v2board', ['app_name' => 'Fixture', 'app_url' => 'https://fixture.invalid',
            'subscribe_url' => 'https://fixture.invalid']);
        $app->instance('cache', new Repository(new ArrayStore()));
        $app->instance('translator', new Translator(new ArrayLoader(), 'en'));
        $factory = \Mockery::mock(ResponseFactory::class);
        $factory->shouldReceive('make')->andReturnUsing(function ($data, $status = 200, $headers = []) {
            return new Response($data, $status, $headers);
        });
        $factory->shouldReceive('stream')->andReturnUsing(function ($callback, $status = 200, $headers = []) {
            return new StreamedResponse($callback, $status, $headers);
        });
        $app->instance(ResponseFactory::class, $factory);
        return [$app, $db];
    }

    public static function seed($db, int $count): void
    {
        $db->table('v2_plan')->insert([['id' => 1, 'name' => 'Plan A'], ['id' => 2, 'name' => 'Plan B']]);
        for ($start = 1; $start <= $count; $start += 100) {
            $rows = [];
            for ($id = $start; $id < min($start + 100, $count + 1); $id++) {
                $rows[] = ['id' => $id, 'email' => 'fixture' . $id . '@example.invalid', 'token' => 'token-' . $id,
                    'uuid' => 'uuid-' . $id, 'plan_id' => $id % 3 ?: null, 'invite_user_id' => $id % 4 ? 1 : null,
                    'transfer_enable' => 10 * 1073741824, 'u' => 1073741824, 'd' => ($id % 12) * 1073741824,
                    'device_limit' => 3, 'balance' => $id, 'commission_balance' => 10,
                    'created_at' => $id % 7, 'expired_at' => $id % 2 ? null : 1790000000,
                    'remarks' => 'fixture-only', 'banned' => $id % 2];
            }
            $db->table('v2_user')->insert($rows);
        }
    }
}

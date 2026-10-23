<?php

namespace Tests\Support;

use App\Http\Controllers\V1\Admin\OrderController;
use App\Http\Requests\Admin\OrderFetch;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

abstract class OrderEmailFilterTestCase extends TestCase
{
    protected $app;
    protected $db;
    private $snapshot;

    abstract protected function connection(): ?array;

    protected function setUp(): void
    {
        [$this->app, $this->db] = UserReadDatabase::boot($this->connection());
        $this->db->getSchemaBuilder()->table('v2_order', function ($table) {
            $table->integer('invite_user_id')->nullable();
            $table->integer('commission_status')->default(0);
            $table->integer('commission_balance')->default(0);
        });
        $this->db->table('v2_plan')->insert([['id' => 1, 'name' => 'Plan A'], ['id' => 2, 'name' => 'Plan B']]);
        $emails = ['alpha@example.invalid', 'alphabeta@example.invalid', 'beta@example.invalid',
            'x%_literal@example.invalid', 'xZZliteral@example.invalid', "o'brien@example.invalid"];
        foreach ($emails as $offset => $email) {
            $this->db->table('v2_user')->insert(['id' => $offset + 1, 'email' => $email]);
        }
        for ($id = 1; $id <= 32; $id++) {
            $user = $id <= 12 ? 1 : ($id <= 24 ? 2 : ($id <= 28 ? 3 : $id - 25));
            $this->db->table('v2_order')->insert([
                'id' => $id, 'user_id' => $id === 32 ? 999 : $user,
                'plan_id' => $id === 32 ? null : ($id % 2 ? 1 : 2), 'trade_no' => 'fixture-' . $id,
                'status' => $id % 4, 'invite_user_id' => $id % 2 ? 1 : null,
                'commission_status' => $id % 3, 'commission_balance' => $id % 2 ? 10 : 0,
                'created_at' => 1700000000 + $id,
            ]);
        }
        $this->snapshot = $this->rows();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->db) {
                $this->assertSame($this->snapshot, $this->rows(), 'Filtering must not modify fixture data');
                Bus::assertNothingDispatched();
            }
        } finally {
            if ($this->db) $this->db->disconnect();
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            Container::setInstance(null);
            \Mockery::close();
        }
    }

    private function rows(): array
    {
        $rows = [];
        foreach (['v2_user', 'v2_order', 'v2_plan'] as $table) {
            $rows[$table] = json_encode($this->db->table($table)->orderBy('id')->get());
        }
        return $rows;
    }

    protected function fetch(array $filters = [], array $options = []): array
    {
        return json_decode((new OrderController())->fetch(new OrderFetch(array_merge([
            'filter' => $filters, 'pageSize' => 100,
        ], $options)))->getContent(), true);
    }

    private function email(string $condition, $value): array
    {
        return ['key' => 'email', 'condition' => $condition, 'value' => $value];
    }

    private function assertIds(array $expected, array $response): void
    {
        $this->assertSame(count($expected), $response['total']);
        $this->assertSame($expected, array_column($response['data'], 'id'));
    }

    public static function filters(): array
    {
        return [
            'exact email' => ['=', 'alpha@example.invalid', range(12, 1)],
            'exact partial must not match' => ['=', 'alpha', []],
            'missing email' => ['=', 'missing@example.invalid', []],
            'missing fuzzy email' => ['模糊', 'missing@example.invalid', []],
            'fuzzy matches all users' => ['模糊', 'alpha', range(24, 1)],
            'fuzzy middle substring' => ['模糊', 'ha@', range(12, 1)],
            'not equal' => ['!=', 'alpha@example.invalid', range(31, 13)],
            'less than' => ['<', 'beta@example.invalid', range(24, 1)],
            'less than or equal' => ['<=', 'beta@example.invalid', range(28, 1)],
            'greater than' => ['>', 'beta@example.invalid', [31, 30, 29]],
            'greater than or equal' => ['>=', 'beta@example.invalid', range(31, 25)],
            'literal percent and underscore' => ['=', 'x%_literal@example.invalid', [29]],
            'fuzzy wildcard semantics' => ['模糊', 'x%_literal', [30, 29]],
            'literal apostrophe' => ['=', "o'brien@example.invalid", [31]],
            'injection value is not SQL' => ['=', "' OR 1=1 --", []],
            'fuzzy injection value is not SQL' => ['模糊', "' OR 1=1 --", []],
            'empty exact' => ['=', '', []],
            'null exact' => ['=', null, []],
            'empty fuzzy keeps registered users' => ['模糊', '', range(31, 1)],
        ];
    }

    /** @dataProvider filters */
    public function testEmailConditions(string $condition, $value, array $expected): void
    {
        $this->assertIds($expected, $this->fetch([$this->email($condition, $value)]));
    }

    public function testUnfilteredOrdersKeepSortFieldsAndMissingUsers(): void
    {
        $response = $this->fetch();
        $this->assertIds(range(32, 1), $response);
        $this->assertArrayNotHasKey('plan_name', $response['data'][0]);
        foreach (array_slice($response['data'], 1) as $row) {
            $this->assertSame($row['id'] % 2 ? 'Plan A' : 'Plan B', $row['plan_name']);
            $this->assertArrayHasKey('total_amount', $row);
            $this->assertArrayHasKey('status', $row);
        }
    }

    public function testPaginationKeepsFilteredTotal(): void
    {
        foreach ([1 => range(24, 15), 2 => range(14, 5), 3 => range(4, 1), 4 => []] as $page => $ids) {
            $result = $this->fetch([$this->email('模糊', 'alpha')], ['current' => $page, 'pageSize' => 10]);
            $this->assertSame(24, $result['total']);
            $this->assertSame($ids, array_column($result['data'], 'id'));
        }
    }

    public function testEmailAndOtherFiltersRemainConjoined(): void
    {
        $email = $this->email('模糊', 'alpha');
        $status = ['key' => 'status', 'condition' => '=', 'value' => 1];
        $trade = ['key' => 'trade_no', 'condition' => '模糊', 'value' => 'fixture-1'];
        $this->assertIds([21, 17, 13, 9, 5, 1], $this->fetch([$email, $status]));
        $this->assertIds([17, 13, 1], $this->fetch([$email, $status, $trade]));
        $this->assertIds([17, 13, 1], $this->fetch([$trade, $status, $email]));
        $this->assertIds([], $this->fetch([$this->email('=', 'missing@example.invalid'), $status]));
        $this->assertIds([1], $this->fetch([$status, ['key' => 'trade_no', 'condition' => '=', 'value' => 'fixture-1']]));
    }

    public function testMultipleEmailConditionsRemainConjoined(): void
    {
        $this->assertIds(range(24, 13), $this->fetch([$this->email('模糊', 'alpha'), $this->email('!=', 'alpha@example.invalid')]));
        $this->assertIds([], $this->fetch([$this->email('=', 'alpha@example.invalid'), $this->email('=', 'beta@example.invalid')]));
    }

    public function testCommissionSelectionKeepsExistingConditions(): void
    {
        $this->assertIds(range(23, 1, 2), $this->fetch([$this->email('模糊', 'alpha')], ['is_commission' => 1]));
        $this->assertIds([], $this->fetch([$this->email('=', 'missing@example.invalid')], ['is_commission' => 1]));
    }

    public function testEmailUsesBoundSubqueryWithoutLoadingUserIds(): void
    {
        $this->db->enableQueryLog();
        $this->fetch([$this->email('=', "o'brien@example.invalid")]);
        $queries = $this->db->getQueryLog();
        $this->db->disableQueryLog();
        $this->assertCount(3, $queries);
        foreach (array_slice($queries, 0, 2) as $query) {
            $sql = str_replace(['`', '"'], '', $query['query']);
            $this->assertStringContainsString('user_id in (select id from v2_user where email = ?)', $sql);
            $this->assertSame(["o'brien@example.invalid"], $query['bindings']);
            $this->assertStringNotContainsString("o'brien", $sql);
        }
    }
}

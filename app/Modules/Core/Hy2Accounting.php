<?php
namespace App\Modules\Core;

use App\Models\StatServer;
use App\Models\StatUser;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Hy2Accounting
{
    public const MAX_ROWS = 500;

    public function validate(array $batch): array
    {
        if (!is_int($batch['sequence'] ?? null) || $batch['sequence'] < 1
            || !is_array($batch['rows'] ?? null) || !array_is_list($batch['rows'])
            || count($batch['rows']) < 1 || count($batch['rows']) > self::MAX_ROWS) {
            throw new HttpException(422, 'Invalid traffic batch');
        }
        $seen = [];
        foreach ($batch['rows'] as $row) {
            if (!is_array($row) || !is_int($row['uid'] ?? null) || $row['uid'] < 1
                || isset($seen[$row['uid']]) || !is_int($row['u'] ?? null) || !is_int($row['d'] ?? null)
                || $row['u'] < 0 || $row['d'] < 0 || $row['u'] > 1000000000000000 || $row['d'] > 1000000000000000) {
                throw new HttpException(422, 'Invalid traffic row');
            }
            $seen[$row['uid']] = true;
        }
        usort($batch['rows'], fn ($a, $b) => $a['uid'] <=> $b['uid']);
        return $batch;
    }

    public static function replay(int $stored, string $digest, int $sequence, string $incoming): bool
    {
        if ($sequence === $stored && hash_equals($digest, $incoming)) return true;
        if ($sequence !== $stored + 1) throw new HttpException(409, 'Report sequence or digest conflict');
        return false;
    }

    public function next(int $node): int
    {
        return (int) DB::table('v2_hy2_report_cursor')->where('node_id', $node)->value('sequence') + 1;
    }

    public function cursor(int $node): array
    {
        $row = DB::table('v2_hy2_report_cursor')->where('node_id', $node)->first(['sequence', 'digest']);
        $sequence = (int) ($row->sequence ?? 0);
        return ['sequence' => $sequence, 'digest' => $row->digest ?? '', 'next' => $sequence + 1];
    }

    public function apply(array $node, array $input, string $digest): array
    {
        $batch = $this->validate($input);
        $rate = (float) $node['rate'];
        if (!is_finite($rate) || $rate < 0 || $rate > 10000) throw new HttpException(422, 'Invalid node rate');
        $ids = array_column($batch['rows'], 'uid');
        DB::transaction(function () use ($node, $batch, $digest, $rate, $ids) {
            // Acquire the cursor's write lock without concurrent duplicate-insert lock upgrades.
            DB::table('v2_hy2_report_cursor')->upsert([
                'node_id' => $node['id'], 'sequence' => 0, 'digest' => '', 'updated_at' => time(),
            ], ['node_id'], ['node_id']);
            $cursor = DB::table('v2_hy2_report_cursor')->where('node_id', $node['id'])->lockForUpdate()->first();
            if (self::replay((int) $cursor->sequence, $cursor->digest, $batch['sequence'], $digest)) return;
            $users = DB::table('v2_user')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $stats = [];
            $uploads = $downloads = [];
            $upload = $download = 0;
            $day = strtotime(date('Y-m-d'));
            foreach ($batch['rows'] as $row) {
                $uid = $row['uid'];
                $upload += $row['u']; $download += $row['d'];
                if (!isset($users[$uid])) continue;
                $u = $row['u'] * $rate; $d = $row['d'] * $rate;
                if (!is_finite($u + $d) || $u + $d > PHP_INT_MAX - (int) $users[$uid]->u - (int) $users[$uid]->d) {
                    throw new HttpException(422, 'Traffic counter overflow');
                }
                $uploads[] = 'WHEN ' . $uid . ' THEN ' . (int) $u;
                $downloads[] = 'WHEN ' . $uid . ' THEN ' . (int) $d;
                $stats[] = ['user_id' => $uid, 'server_rate' => $node['rate'], 'u' => $row['u'], 'd' => $row['d'],
                    'record_type' => 'd', 'record_at' => $day];
            }
            if ($uploads) DB::table('v2_user')->whereIn('id', $ids)->update([
                'u' => DB::raw('u + CASE id ' . implode(' ', $uploads) . ' ELSE 0 END'),
                'd' => DB::raw('d + CASE id ' . implode(' ', $downloads) . ' ELSE 0 END'),
                't' => time(), 'updated_at' => time(),
            ]);
            $mysql = DB::connection()->getDriverName() === 'mysql';
            if ($stats) StatUser::upsert($stats, ['user_id', 'server_rate', 'record_at'], [
                'u' => DB::raw('v2_stat_user.u + ' . ($mysql ? 'VALUES(u)' : 'excluded.u')),
                'd' => DB::raw('v2_stat_user.d + ' . ($mysql ? 'VALUES(d)' : 'excluded.d')), 'updated_at',
            ]);
            $query = StatServer::where('server_id', $node['id'])->where('server_type', 'v2node')->where('record_at', $day);
            $stat = $query->lockForUpdate()->first();
            if ($stat) {
                $stat->update(['u' => DB::raw('u + ' . $upload), 'd' => DB::raw('d + ' . $download)]);
            } else {
                StatServer::create(['server_id' => $node['id'], 'server_type' => 'v2node',
                    'u' => $upload, 'd' => $download, 'record_type' => 'd', 'record_at' => $day]);
            }
            // Billing, statistics, and the acknowledgement cursor commit together, without queue gaps.
            DB::table('v2_hy2_report_cursor')->where('node_id', $node['id'])->update([
                'sequence' => $batch['sequence'], 'digest' => $digest, 'updated_at' => time(),
            ]);
        }, 3);
        $allowed = DB::table('v2_user')->whereIn('id', $ids)->whereIn('group_id', $node['group_id'] ?? [])
            ->where('banned', 0)->whereRaw('u + d < transfer_enable')
            ->where(fn ($q) => $q->whereNull('expired_at')->orWhere('expired_at', '>=', time()))->pluck('id')->all();
        return ['accepted' => true, 'sequence' => $batch['sequence'], 'digest' => $digest,
            'revoked' => array_values(array_diff($ids, array_map('intval', $allowed)))];
    }
}

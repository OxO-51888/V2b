<?php

namespace Tests\Support;

use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LegacyReadQueries
{
    public static function overview(int $now, array $devices): array
    {
        $month = strtotime(date('Y-m-1', $now));
        $day = strtotime(date('Y-m-d', $now));
        $lastMonth = strtotime('-1 month', $month);
        return ['data' => [
            'online_device' => $devices['count'],
            'online_device_window_seconds' => $devices['window_seconds'],
            'online_user' => User::where('t', '>=', $now - 600)->count(),
            'month_income' => Order::where('created_at', '>=', $month)->where('created_at', '<', $now)->whereNotIn('status', [0, 2])->sum('total_amount'),
            'month_register_total' => User::where('created_at', '>=', $month)->where('created_at', '<', $now)->count(),
            'day_register_total' => User::where('created_at', '>=', $day)->where('created_at', '<', $now)->count(),
            'ticket_pending_total' => Ticket::where('status', 0)->where('reply_status', 0)->count(),
            'commission_pending_total' => Order::where('commission_status', 0)->where('invite_user_id', '!=', null)
                ->whereNotIn('status', [0, 2])->where('commission_balance', '>', 0)->count(),
            'day_income' => Order::where('created_at', '>=', $day)->where('created_at', '<', $now)->whereNotIn('status', [0, 2])->sum('total_amount'),
            'last_month_income' => Order::where('created_at', '>=', $lastMonth)->where('created_at', '<', $month)->whereNotIn('status', [0, 2])->sum('total_amount'),
            'commission_month_payout' => CommissionLog::where('created_at', '>=', $month)->where('created_at', '<', $now)->sum('get_amount'),
            'commission_last_month_payout' => CommissionLog::where('created_at', '>=', $lastMonth)->where('created_at', '<', $month)->sum('get_amount'),
        ]];
    }

    public static function userRank(int $start, int $end): array
    {
        $statistics = StatUser::select(['user_id', 'server_rate', 'u', 'd', DB::raw('(u+d) as total')])
            ->where('record_at', '>=', $start)->where('record_at', '<', $end)->where('record_type', 'd')
            ->limit(30)->orderBy('total', 'DESC')->get()->toArray();
        $data = [];
        $idIndexMap = [];
        foreach ($statistics as $k => $v) {
            $id = $statistics[$k]['user_id'];
            $user = User::where('id', $id)->first();
            $statistics[$k]['email'] = empty($user) ? 'null' : $user['email'];
            $statistics[$k]['total'] = $statistics[$k]['total'] * $statistics[$k]['server_rate'] / 1073741824;
            if (isset($idIndexMap[$id])) {
                $data[$idIndexMap[$id]]['total'] += $statistics[$k]['total'];
            } else {
                unset($statistics[$k]['server_rate']);
                $data[] = $statistics[$k];
                $idIndexMap[$id] = count($data) - 1;
            }
        }
        array_multisort(array_column($data, 'total'), SORT_DESC, $data);
        return ['data' => array_slice($data, 0, 15)];
    }

    public static function serverStatus(array $servers, int $now): array
    {
        foreach ($servers as &$server) {
            $type = strtoupper($server['type']);
            $id = $server['parent_id'] ?? $server['id'];
            $server['online'] = Cache::get(CacheKey::get("SERVER_{$type}_ONLINE_USER", $id));
            $server['last_check_at'] = Cache::get(CacheKey::get("SERVER_{$type}_LAST_CHECK_AT", $id));
            $server['last_push_at'] = Cache::get(CacheKey::get("SERVER_{$type}_LAST_PUSH_AT", $id));
            $server['available_status'] = ($now - 300) >= $server['last_check_at'] ? 0
                : (($now - 300) >= $server['last_push_at'] ? 1 : 2);
        }
        return $servers;
    }
}

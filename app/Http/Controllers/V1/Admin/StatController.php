<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\ServerHysteria;
use App\Models\ServerTuic;
use App\Models\ServerShadowsocks;
use App\Models\ServerTrojan;
use App\Models\ServerVmess;
use App\Models\ServerVless;
use App\Models\ServerAnytls;
use App\Models\ServerV2node;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ClientGateway\OnlineDeviceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatController extends Controller
{
    public function getOverride(Request $request)
    {
        $onlineDevices = app(OnlineDeviceService::class)->snapshot();
        $now = time();
        $monthStart = strtotime(date('Y-m-1', $now));
        $dayStart = strtotime(date('Y-m-d', $now));
        $lastMonthStart = strtotime('-1 month', $monthStart);
        $users = User::selectRaw('COUNT(CASE WHEN t >= ? THEN 1 END) AS online_user,
                COUNT(CASE WHEN created_at >= ? AND created_at < ? THEN 1 END) AS month_register_total,
                COUNT(CASE WHEN created_at >= ? AND created_at < ? THEN 1 END) AS day_register_total',
                [$now - 600, $monthStart, $now, $dayStart, $now])->first();
        $orders = Order::whereNotIn('status', [0, 2])
            ->selectRaw('SUM(CASE WHEN created_at >= ? AND created_at < ? THEN total_amount ELSE 0 END) AS month_income,
                SUM(CASE WHEN created_at >= ? AND created_at < ? THEN total_amount ELSE 0 END) AS day_income,
                SUM(CASE WHEN created_at >= ? AND created_at < ? THEN total_amount ELSE 0 END) AS last_month_income,
                COUNT(CASE WHEN commission_status = 0 AND invite_user_id IS NOT NULL AND commission_balance > 0 THEN 1 END) AS commission_pending_total',
                [$monthStart, $now, $dayStart, $now, $lastMonthStart, $monthStart])->first();
        $commissions = CommissionLog::where('created_at', '>=', $lastMonthStart)->where('created_at', '<', $now)
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN get_amount ELSE 0 END) AS month_payout,
                SUM(CASE WHEN created_at < ? THEN get_amount ELSE 0 END) AS last_month_payout',
                [$monthStart, $monthStart])->first();
        return [
            'data' => [
                'online_device' => $onlineDevices['count'],
                'online_device_window_seconds' => $onlineDevices['window_seconds'],
                'online_user' => (int) $users->online_user,
                'month_income' => $orders->month_income ?: 0,
                'month_register_total' => (int) $users->month_register_total,
                'day_register_total' => (int) $users->day_register_total,
                'ticket_pending_total' => Ticket::where('status', 0)
                    ->where('reply_status', 0)
                    ->count(),
                'commission_pending_total' => (int) $orders->commission_pending_total,
                'day_income' => $orders->day_income ?: 0,
                'last_month_income' => $orders->last_month_income ?: 0,
                'commission_month_payout' => $commissions->month_payout ?: 0,
                'commission_last_month_payout' => $commissions->last_month_payout ?: 0,
            ]
        ];
    }

    public function getOrder(Request $request)
    {
        $statistics = Stat::where('record_type', 'd')
            ->limit(31)
            ->orderBy('record_at', 'DESC')
            ->get()
            ->toArray();
        $result = [];
        foreach ($statistics as $statistic) {
            $date = date('m-d', $statistic['record_at']);
            $result[] = [
                'type' => '注册人数',
                'date' => $date,
                'value' => $statistic['register_count']
            ];
            $result[] = [
                'type' => '收款金额',
                'date' => $date,
                'value' => $statistic['paid_total'] / 100
            ];
            $result[] = [
                'type' => '收款笔数',
                'date' => $date,
                'value' => $statistic['paid_count']
            ];
            $result[] = [
                'type' => '佣金金额(已发放)',
                'date' => $date,
                'value' => $statistic['commission_total'] / 100
            ];
            $result[] = [
                'type' => '佣金笔数(已发放)',
                'date' => $date,
                'value' => $statistic['commission_count']
            ];
        }
        $result = array_reverse($result);
        return [
            'data' => $result
        ];
    }

    public function getServerLastRank()
    {
        $servers = [
            'shadowsocks' => ServerShadowsocks::where('parent_id', null)->get()->toArray(),
            'v2ray' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'trojan' => ServerTrojan::where('parent_id', null)->get()->toArray(),
            'vmess' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'vless' => ServerVless::where('parent_id', null)->get()->toArray(),
            'tuic' => ServerTuic::where('parent_id', null)->get()->toArray(),
            'hysteria'=> ServerHysteria::where('parent_id', null)->get()->toArray(),
            'anytls' => ServerAnytls::where('parent_id', null)->get()->toArray(),
            'v2node' => ServerV2node::where('parent_id', null)->get()->toArray()
        ];
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
        $statistics = StatServer::select([
            'server_id',
            'server_type',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(15)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        foreach ($statistics as $k => $v) {
            foreach ($servers[$v['server_type']] as $server) {
                if ($server['id'] === $v['server_id']) {
                    $statistics[$k]['server_name'] = $server['name'];
                }
            }
            $statistics[$k]['total'] = $statistics[$k]['total'] / 1073741824;
        }
        array_multisort(array_column($statistics, 'total'), SORT_DESC, $statistics);
        return [
            'data' => $statistics
        ];
    }

    public function getServerTodayRank()
    {
        $servers = [
            'shadowsocks' => ServerShadowsocks::where('parent_id', null)->get()->toArray(),
            'v2ray' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'trojan' => ServerTrojan::where('parent_id', null)->get()->toArray(),
            'vmess' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'vless' => ServerVless::where('parent_id', null)->get()->toArray(),
            'tuic' => ServerTuic::where('parent_id', null)->get()->toArray(),
            'hysteria'=> ServerHysteria::where('parent_id', null)->get()->toArray(),
            'anytls' => ServerAnytls::where('parent_id', null)->get()->toArray(),
            'v2node' => ServerV2node::where('parent_id', null)->get()->toArray()
        ];
        $startAt = strtotime(date('Y-m-d'));
        $endAt = time();
        $statistics = StatServer::select([
            'server_id',
            'server_type',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(15)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        foreach ($statistics as $k => $v) {
            foreach ($servers[$v['server_type']] as $server) {
                if ($server['id'] === $v['server_id']) {
                    $statistics[$k]['server_name'] = $server['name'];
                }
            }
            $statistics[$k]['total'] = $statistics[$k]['total'] / 1073741824;
        }
        array_multisort(array_column($statistics, 'total'), SORT_DESC, $statistics);
        return [
            'data' => $statistics
        ];
    }

    public function getUserTodayRank()
    {
        $startAt = strtotime(date('Y-m-d'));
        $endAt = time();
        $statistics = StatUser::select([
            'user_id',
            'server_rate',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(30)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        $emails = $statistics ? User::whereIn('id', array_unique(array_column($statistics, 'user_id')))
            ->pluck('email', 'id')->all() : [];
        $data = [];
        $idIndexMap = [];
        foreach ($statistics as $k => $v) {
            $id = $statistics[$k]['user_id'];
            $statistics[$k]['email'] = array_key_exists($id, $emails) ? $emails[$id] : "null";
            $statistics[$k]['total'] = $statistics[$k]['total'] * $statistics[$k]['server_rate'] / 1073741824;
            if (isset($idIndexMap[$id])) {
                $index = $idIndexMap[$id];
                $data[$index]['total'] += $statistics[$k]['total'];
            } else {
                unset($statistics[$k]['server_rate']);
                $data[] = $statistics[$k];
                $idIndexMap[$id] = count($data) - 1;
            }
        }
        array_multisort(array_column($data, 'total'), SORT_DESC, $data);
        return [
            'data' => array_slice($data, 0, 15)
        ];
    }

    public function getUserLastRank()
    {
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
        $statistics = StatUser::select([
            'user_id',
            'server_rate',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(30)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        $emails = $statistics ? User::whereIn('id', array_unique(array_column($statistics, 'user_id')))
            ->pluck('email', 'id')->all() : [];
        $data = [];
        $idIndexMap = [];
        foreach ($statistics as $k => $v) {
            $id = $statistics[$k]['user_id'];
            $statistics[$k]['email'] = array_key_exists($id, $emails) ? $emails[$id] : "null";
            $statistics[$k]['total'] = $statistics[$k]['total'] * $statistics[$k]['server_rate'] / 1073741824;
            if (isset($idIndexMap[$id])) {

                $index = $idIndexMap[$id];
                $data[$index]['total'] += $statistics[$k]['total'];
            } else {
                unset($statistics[$k]['server_rate']);
                $data[] = $statistics[$k];
                $idIndexMap[$id] = count($data) - 1;
            }
        }
        array_multisort(array_column($data, 'total'), SORT_DESC, $data);
        return [
            'data' => array_slice($data, 0, 15)
        ];
    }

    public function getStatUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer'
        ]);
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = min(150, max(10, (int) $request->input('pageSize', 10)));
        $builder = StatUser::orderBy('record_at', 'DESC')->where('user_id', $request->input('user_id'));

        $total = $builder->count();
        $records = $builder->forPage($current, $pageSize)
            ->get();
        return [
            'data' => $records,
            'total' => $total
        ];
    }

}


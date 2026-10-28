<?php
namespace App\Modules\Core;

use App\Modules\Security\SubscriptionRuleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Hy2Online
{
    public function replace(Request $request, int $node, array $data): void
    {
        if (count($data) > 10000) throw new HttpException(413, 'Too many online users');
        $rows = [];
        $interval=(int) config('v2board.server_push_interval',60);
        $interval=$interval<=0 ? 60 : max(5,$interval);
        $observed=now()->timestamp;
        $ttl=max(120,$interval*3+30);
        foreach ($data as $uid => $ips) {
            if (!ctype_digit((string) $uid) || (int) $uid < 1 || !is_array($ips) || count($ips) > 256) {
                throw new HttpException(422, 'Invalid online user');
            }
            $normalized = [];
            foreach ($ips as $ip) {
                if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) throw new HttpException(422, 'Invalid online IP');
                $ip = inet_ntop(inet_pton($ip));
                if (str_starts_with($ip, '::ffff:')) $ip = substr($ip, 7);
                $normalized[$ip] = true;
            }
            $rows[] = ['node_id' => $node, 'user_id' => (int) $uid,
                'ips' => json_encode(array_keys($normalized)), 'observed_at'=>$observed, 'expires_at' => $observed + $ttl];
        }
        DB::transaction(function () use ($node, $rows, $request) {
            // Serialize full replacement for one node; different nodes never overwrite each other.
            DB::table('v2_server_v2node')->where('id', $node)->lockForUpdate()->first();
            DB::table('v2_hy2_online')->where('node_id', $node)->delete();
            foreach (array_chunk($rows, 250) as $chunk) DB::table('v2_hy2_online')->insert($chunk);
            // Cleanup is scoped to this node; avoid locking other nodes on every heartbeat.
        }, 3);
        $expired=DB::table('v2_hy2_online')->where('expires_at','<',$observed)->limit(250)->get(['node_id','user_id']);
        if ($expired->isNotEmpty()) {
            DB::table('v2_hy2_online')->where('expires_at','<',$observed)->where(function ($q) use ($expired) {
                foreach ($expired as $row) $q->orWhere(fn ($pair) => $pair->where('node_id',$row->node_id)->where('user_id',$row->user_id));
            })->delete();
        }
        if (!DB::table('v2_subscription_rule')->where('type', 'node_alive_ip_over_limit')->where('enabled', 1)->exists()) return;
        $rules=app(SubscriptionRuleService::class);
        foreach (array_chunk(array_column($rows,'user_id'),500) as $ids) {
            $states=$this->states($ids);
            foreach ($ids as $id) {
                $state=$states[$id];
                $rules->guardNodeAliveIp($request,$id,$state,$state['alive_ip'],'v2node',$node);
            }
        }
    }

    public function states(array $ids): array
    {
        $states = []; $ips = [];
        foreach (DB::table('v2_hy2_online')->whereIn('user_id', $ids)->where('expires_at', '>=', now()->timestamp)->cursor() as $row) {
            $list = json_decode($row->ips, true, 4, JSON_THROW_ON_ERROR);
            foreach ($list as $ip) $ips[$row->user_id][$ip] = true;
            $states[$row->user_id]['v2node' . $row->node_id] = ['aliveips' => $list, 'lastupdateAt' => $row->observed_at];
        }
        foreach ($ids as $id) $states[$id]['alive_ip'] = count($ips[$id] ?? []);
        return $states;
    }

    private function userState(int $uid): array
    {
        return $this->states([$uid])[$uid];
    }

    public function counts(array $groups): array
    {
        $users = DB::table('v2_user')->whereIn('group_id', $groups)->where('device_limit', '>', 0)->select('id');
        $ips = [];
        foreach (DB::table('v2_hy2_online')->whereIn('user_id', $users)->where('expires_at', '>=', now()->timestamp)->cursor() as $row) {
            foreach (json_decode($row->ips, true, 4, JSON_THROW_ON_ERROR) as $ip) $ips[$row->user_id][$ip] = true;
        }
        return array_map('count', $ips);
    }
}

<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\ClashMeta;
use App\Services\ServerService;
use App\Services\SubscriptionRuleService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $ruleResponse = (new SubscriptionRuleService())->guardSubscribe($request, $request->user);
        if ($ruleResponse) {
            return $ruleResponse;
        }

        return $this->buildSubscribeResponse($request);
    }

    private function buildSubscribeResponse(Request $request)
    {
        $flag = strtolower(trim((string)$request->input('flag', '')));
        $userAgent = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($flag === '') {
            $flag = $userAgent;
        }
        $flag = $this->normalizeClientFlag($flag, $userAgent);
        $user = $request->user;

        // account not expired and is not banned.
        $userService = new UserService();
        if (!$userService->isAvailable($user)) {
            return response('', 403);
        }

        $serverService = new ServerService();
        $servers = $serverService->getAvailableServers($user);
        if($flag) {
            if (!strpos($flag, 'sing')) {
                $this->setSubscribeInfoToServers($servers, $user);
                foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                    $file = 'App\\Protocols\\' . basename($file, '.php');
                    $class = new $file($user, $servers);
                    if (strpos($flag, $class->flag) !== false) {
                        return $class->handle();
                    }
                }
            }
            if (strpos($flag, 'sing') !== false) {
                $version = null;
                if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                    $version = $matches[1];
                }
                if (!is_null($version) && version_compare($version, '1.12.0', '>=')) {
                    $class = new Singbox($user, $servers);
                } else {
                    $class = new SingboxOld($user, $servers);
                }
                return $class->handle();
            }
        }
        $class = new General($user, $servers);
        return $class->handle();
    }

    private function normalizeClientFlag($flag, $userAgent = '')
    {
        $detected = $this->detectClientFlag($userAgent);
        if ($detected) return $detected;

        $detected = $this->detectClientFlag($flag);
        if ($detected) return $detected;

        if (strpos($flag, 'go-http-client') !== false) {
            return 'meta';
        }
        return $flag;
    }

    private function detectClientFlag($text)
    {
        $text = strtolower((string)$text);
        if ($text === '') return null;

        if (strpos($text, 'v2rayng') !== false) return 'v2rayng';
        if (strpos($text, 'v2rayn') !== false) return 'v2rayn';
        if (strpos($text, 'shadowrocket') !== false) return 'shadowrocket';
        if (strpos($text, 'quantumult%20x') !== false || strpos($text, 'quantumult x') !== false || strpos($text, 'quantumultx') !== false) return 'quantumult%20x';
        if (strpos($text, 'hiddify') !== false) return 'sing-box 1.11.0';
        if (preg_match('/\b(?:sfa|sfi)\/?([0-9.]+)?\b/i', $text, $matches)) {
            return !empty($matches[1]) ? 'sing-box ' . $matches[1] : 'sing-box 1.12.0';
        }
        if (preg_match('/sing[- ]?box[\/\s]+([0-9.]+)/i', $text, $matches)) return 'sing-box ' . $matches[1];
        if (strpos($text, 'sing-box') !== false || strpos($text, 'singbox') !== false) return 'sing-box 1.12.0';
        if (strpos($text, 'stash') !== false) return 'stash';
        if (strpos($text, 'surge') !== false) return 'surge';
        if (strpos($text, 'loon') !== false) return 'loon';
        if (strpos($text, 'surfboard') !== false) return 'surfboard';
        if (strpos($text, 'v2raytun') !== false) return 'v2raytun';
        if (strpos($text, 'passwall') !== false) return 'passwall';
        if (strpos($text, 'ssrplus') !== false) return 'ssrplus';
        if (strpos($text, 'sagernet') !== false) return 'sagernet';
        if (strpos($text, 'nekobox') !== false || strpos($text, 'nekoray') !== false) return 'meta';
        if (strpos($text, 'flclash') !== false
            || strpos($text, 'clashmeta') !== false
            || strpos($text, 'clash meta') !== false
            || strpos($text, 'clash.meta') !== false
            || strpos($text, 'clash-meta') !== false
            || strpos($text, 'mihomo') !== false) return 'meta';
        if (strpos($text, 'nyanpasu') !== false) return 'nyanpasu';
        if (strpos($text, 'clash-verge') !== false || strpos($text, 'clash verge') !== false || strpos($text, 'verge') !== false) return 'verge';
        if (strpos($text, 'clash') !== false) return 'clash';

        return null;
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }
}

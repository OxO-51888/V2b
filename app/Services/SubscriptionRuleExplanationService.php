<?php

namespace App\Services;

use App\Models\SubscriptionRuleLog;

class SubscriptionRuleExplanationService
{
    public function recentForUser($userId, $limit = 5)
    {
        $limit = max(1, min((int)$limit, 20));

        return SubscriptionRuleLog::with(['rule:id,name,type'])
            ->where('user_id', (int)$userId)
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()
            ->map(function ($log) {
                return $this->explain($log);
            })
            ->values();
    }

    public function recentAdminPreview($limit = 5)
    {
        $limit = max(1, min((int)$limit, 20));

        return SubscriptionRuleLog::with(['rule:id,name,type'])
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()
            ->map(function ($log) {
                $explanation = $this->explain($log);
                $explanation['admin_preview'] = true;
                $explanation['status_text'] = '管理员预览';
                return $explanation;
            })
            ->values();
    }

    public function explain(SubscriptionRuleLog $log)
    {
        $ruleType = (string)($log->rule_type ?: ($log->rule ? $log->rule->type : ''));
        $action = $this->normalizeAction((string)$log->action, (string)$log->ai_decision);
        $copy = $this->copyFor($log, $ruleType, (string)$log->reason, (string)$log->matched_value);

        return [
            'id' => $log->id,
            'created_at' => $this->timestamp($log),
            'status' => $action['status'],
            'status_text' => $action['text'],
            'severity' => $action['severity'],
            'title' => $copy['title'],
            'summary' => $copy['summary'],
            'advice' => $copy['advice'],
            'rule_type' => $ruleType,
            'rule_name' => $log->rule ? $log->rule->name : '',
            'client' => $this->clientName((string)$log->user_agent, (string)$log->flag),
            'risk_score' => $log->ai_score !== null ? (int)$log->ai_score : null,
            'ai_decision' => $log->ai_decision,
            'ai_reason' => $this->safeAiReason((string)$log->ai_reason),
        ];
    }

    private function normalizeAction($action, $aiDecision)
    {
        if ($aiDecision === 'block') {
            return [
                'status' => 'blocked',
                'text' => '已保护订阅',
                'severity' => 'danger'
            ];
        }

        if ($aiDecision === 'allow') {
            return [
                'status' => 'allowed',
                'text' => '已放行',
                'severity' => 'success'
            ];
        }

        if (in_array($action, ['reset_subscribe'], true)) {
            return [
                'status' => 'reset',
                'text' => '已重置订阅',
                'severity' => 'danger'
            ];
        }

        if (in_array($action, ['no_nodes', 'block', 'empty_subscription', 'rate_limit'], true)) {
            return [
                'status' => 'blocked',
                'text' => '未下发节点',
                'severity' => 'danger'
            ];
        }

        if ($action === 'ai_review') {
            return [
                'status' => 'reviewed',
                'text' => '已审查',
                'severity' => 'warning'
            ];
        }

        return [
            'status' => 'recorded',
            'text' => '已记录',
            'severity' => 'info'
        ];
    }

    private function copyFor(SubscriptionRuleLog $log, $ruleType, $reason, $matchedValue)
    {
        $context = $this->requestContext($log);
        $copyKey = $reason === 'node_exit_ip_bypass' ? $reason : ($ruleType ?: $reason);

        switch ($copyKey) {
            case 'header_browser_context':
            case 'ua_browser':
            case 'ua_social':
            case 'ua_vendor':
                if ($context['type'] === 'lark') {
                    return [
                        'title' => '飞书好像悄悄点开了订阅',
                        'summary' => 'AI小助手看到飞书内置浏览器或链接预览的痕迹，这通常不是代理客户端在更新订阅，而是飞书帮你偷偷看了一眼链接。',
                        'advice' => '不要在飞书里点开或转发订阅链接哦。请回到面板复制完整订阅，再到代理客户端里添加。'
                    ];
                }
                if ($context['type'] === 'wechat_qq') {
                    return [
                        'title' => '微信或 QQ 好像碰到了订阅',
                        'summary' => 'AI小助手看到微信、QQ 或内置浏览器的访问特征，这类软件可能会自动预览链接，让订阅地址不小心跑出去。',
                        'advice' => '请不要在聊天软件里打开订阅链接。复制完整链接后，直接粘贴到代理客户端的订阅导入位置。'
                    ];
                }
                if ($context['type'] === 'telegram') {
                    return [
                        'title' => 'Telegram 好像预览了订阅',
                        'summary' => 'AI小助手看到 Telegram 或链接预览的访问特征，这通常不是客户端在认真更新订阅。',
                        'advice' => '建议关闭聊天软件的链接预览，不要把订阅链接发到群聊或频道里。需要使用时，请在代理客户端里导入。'
                    ];
                }
                if ($context['type'] === 'browser') {
                    return [
                        'title' => '浏览器把订阅当网页打开啦',
                        'summary' => 'AI小助手看到 Chrome、Safari、Firefox、Edge 等浏览器特征。订阅链接不是网页，直接打开时系统会先把节点藏起来保护你。',
                        'advice' => '不要在浏览器地址栏打开订阅链接哦。复制完整订阅地址后，到代理客户端里选择“添加订阅”或“从 URL 导入”。'
                    ];
                }
                return [
                    'title' => '订阅好像被网页预览碰了一下',
                    'summary' => '这次请求带有网页或内置浏览器才会出现的特征，AI小助手判断它不像正常代理客户端在更新订阅。',
                    'advice' => '请回到面板复制订阅链接，并在代理客户端内导入。不要通过聊天软件、网页预览或第三方页面打开它。'
                ];

            case 'direct_ip_host':
                return [
                    'title' => '订阅入口好像绕路了',
                    'summary' => 'AI小助手发现这次不是通过正常订阅域名访问，可能用了服务器 IP、本地 Host，或者链接入口被改过。',
                    'advice' => '请使用面板里复制的完整订阅链接重新导入客户端，不要手动改域名、Host，也不要直接访问服务器 IP。'
                ];

            case 'ua_scanner':
            case 'head_method_probe':
                return [
                    'title' => '有个小工具在试探订阅',
                    'summary' => 'AI小助手看到这次请求不像正常订阅更新，更像检测工具在试探链接能不能访问。',
                    'advice' => '请确认没有把订阅链接放进检测网站、监控工具或安全扫描工具里。需要使用时，请在正规代理客户端中重新导入。'
                ];

            case 'ua_cli_fetch':
            case 'ua_api_fetch':
                return [
                    'title' => '订阅像被脚本工具拉走了',
                    'summary' => 'AI小助手看到命令行、脚本或接口测试工具的访问特征，这类工具容易让订阅泄露或反复刷新。',
                    'advice' => '请不要用脚本、curl、Postman 或未知程序拉取订阅。建议重置订阅后，只在自己的代理客户端里使用。'
                ];

            case 'empty_user_agent':
                return [
                    'title' => '客户端名字没带上',
                    'summary' => '这次订阅请求没有带上正常客户端标识，AI小助手暂时认不出它是不是可靠来源。',
                    'advice' => '请更新代理客户端后重新导入订阅。如果还失败，请提交工单，并告诉我们客户端名称和版本。'
                ];

            case 'converter_query':
            case 'ua_converter':
                return [
                    'title' => '订阅像被转换工具加工过',
                    'summary' => 'AI小助手看到订阅转换器常见的参数或客户端特征。公开转换服务可能保存或暴露你的订阅地址。',
                    'advice' => '建议优先使用客户端自带的订阅功能。如必须转换，请使用可信的自建转换服务，不要把订阅链接交给陌生网站。'
                ];

            case 'flag_ua_mismatch':
                return [
                    'title' => '客户端身份有点对不上',
                    'summary' => '链接参数里写的客户端和真实访问特征对不上，可能是手动改过链接、复制错了链接，或被第三方工具代拉取。',
                    'advice' => '请在客户端里删除旧订阅后重新添加，不要手动拼接 flag 参数。'
                ];

            case 'untrusted_proxy_header':
                return [
                    'title' => '转发来源暂时认不准',
                    'summary' => '这次请求带了转发来源信息，但来源不在可信代理范围内，AI小助手暂时确认不了真实访问来源。',
                    'advice' => '请直接使用本站订阅地址，或联系管理员确认当前网络、加速入口是否可信。'
                ];

            case 'pull_frequency':
                return [
                    'title' => '订阅刷新有点太勤快啦',
                    'summary' => '同一个订阅短时间内反复刷新，常见原因是客户端更新失败后一直重试，或者多个设备一起自动更新。',
                    'advice' => '先休息 5 到 10 分钟再更新订阅吧，也可以关闭多个客户端的高频自动刷新。'
                ];

            case 'ip_spread':
                return [
                    'title' => '订阅在多个网络位置出现',
                    'summary' => '同一个订阅短时间内从多个不同网络位置访问，AI小助手会先帮你做安全保护。',
                    'advice' => '请不要开着代理导入订阅哦。先关闭代理连接，再回到客户端重新添加订阅，这样系统才能认准是你本人在使用。'
                ];

            case 'ip_multi_user':
                return [
                    'title' => '同一网络在拉多个订阅',
                    'summary' => '同一个网络出口短时间内拉取了多个用户订阅，AI小助手会做一次风险审查。',
                    'advice' => '如果你在公司、学校、机场或公共网络中使用，建议稍后重试；如果是家庭软路由，请联系管理员确认。'
                ];

            case 'node_alive_ip_over_limit':
                return [
                    'title' => '同账号在线设备有点多',
                    'summary' => '同一个账号在节点上出现了过多在线 IP，可能是订阅或节点信息被分享出去了。',
                    'advice' => '建议立即重置订阅，并只在自己的设备上使用。如果是家庭多设备，请先关闭代理连接，再重新导入订阅，不要挂着节点添加订阅。'
                ];

            case 'node_exit_ip_bypass':
                return [
                    'title' => '刚才是节点自己来确认订阅啦',
                    'summary' => 'AI小助手认出这是本站可信节点的出口 IP，不是别人拿着你的订阅乱用。',
                    'advice' => '这类记录不用处理，系统已经自动放行。如果你还是更新失败，把客户端名称发给客服就好。'
                ];

            default:
                return [
                    'title' => '订阅触发了安全保护',
                    'summary' => 'AI小助手检测到这次订阅请求有点异常，系统已经先按安全策略保护你的订阅。',
                    'advice' => '请在正规代理客户端中重新导入订阅。若仍无法使用，请提交工单，并说明客户端名称、版本和操作时间。'
                ];
        }
    }

    private function clientName($userAgent, $flag)
    {
        $uaValue = strtolower($userAgent);
        if ($this->containsAny($uaValue, ['larkurl', 'lark', 'feishu'])) {
            return '飞书预览';
        }
        if ($this->containsAny($uaValue, ['micromessenger', 'wechat', 'weixin', ' qq/', 'mqqbrowser', 'qqbrowser'])) {
            return '微信或QQ预览';
        }
        if ($this->containsAny($uaValue, ['telegrambot', 'telegram'])) {
            return 'Telegram预览';
        }
        if ($this->containsAny($uaValue, ['陌生工具', 'wget', 'httpie', 'powershell', 'python-requests', 'go-http-client', 'postman', 'axios', 'undici', 'node-fetch'])) {
            return '陌生工具';
        }
        if ($this->containsAny($uaValue, ['chrome', 'safari', 'firefox', 'edge', 'edg/', 'mozilla', 'sec-fetch-site', 'sec-fetch-mode'])) {
            return '浏览器或内置浏览器';
        }

        $value = strtolower($userAgent . ' ' . $flag);
        $clients = [
            'Shadowrocket' => ['shadowrocket'],
            'Clash' => ['clash', 'mihomo', 'metacubex'],
            'Sing-box' => ['sing-box', 'singbox', 'sfa', 'sfi'],
            'Stash' => ['stash'],
            'Surge' => ['surge'],
            'Loon' => ['loon'],
            'Quantumult X' => ['quantumult', 'quanx'],
            'V2Ray' => ['v2ray', 'v2rayn', 'v2rayng'],
            'Hiddify' => ['hiddify'],
            '浏览器或内置浏览器' => ['mozilla', 'chrome', 'safari', 'firefox', 'edge', 'larkurl', 'micromessenger', 'telegram'],
            '陌生工具' => ['陌生工具', 'wget', 'postman', 'python-requests', 'go-http-client', 'axios'],
        ];

        foreach ($clients as $name => $needles) {
            foreach ($needles as $needle) {
                if (strpos($value, $needle) !== false) {
                    return $name;
                }
            }
        }

        return $userAgent || $flag ? '未知软件' : '未识别';
    }

    private function requestContext(SubscriptionRuleLog $log)
    {
        $ua = strtolower((string)$log->user_agent);
        $flag = strtolower((string)$log->flag);
        $matched = strtolower((string)$log->matched_value);
        $reason = strtolower((string)$log->reason);
        $referer = strtolower((string)$log->referer);
        $accept = strtolower((string)$log->accept);
        $value = implode(' ', [$ua, $flag, $matched, $reason, $referer, $accept]);

        if ($this->containsAny($value, ['larkurl', 'lark', 'feishu', '飞书'])) {
            return [
                'type' => 'lark',
                'label' => '飞书预览'
            ];
        }

        if ($this->containsAny($value, ['micromessenger', 'wechat', 'weixin', ' qq/', 'mqqbrowser', 'qqbrowser'])) {
            return [
                'type' => 'wechat_qq',
                'label' => '微信或QQ预览'
            ];
        }

        if ($this->containsAny($value, ['telegrambot', 'telegram', 'tg://'])) {
            return [
                'type' => 'telegram',
                'label' => 'Telegram预览'
            ];
        }

        if ($this->containsAny($value, ['陌生工具', 'wget', 'httpie', 'powershell', 'python-requests', 'go-http-client', 'postman', 'axios', 'undici', 'node-fetch'])) {
            return [
                'type' => 'script',
                'label' => '脚本工具'
            ];
        }

        if ($this->containsAny($value, ['chrome', 'safari', 'firefox', 'edge', 'edg/', 'mozilla', 'sec-fetch-site', 'sec-fetch-mode'])) {
            return [
                'type' => 'browser',
                'label' => '浏览器'
            ];
        }

        return [
            'type' => 'unknown',
            'label' => ''
        ];
    }

    private function containsAny($value, array $needles)
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($value, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function safeAiReason($reason)
    {
        $reason = trim($reason);
        if ($reason === '') {
            return '';
        }

        $reason = preg_replace('/([0-9]{1,3}\.){3}[0-9]{1,3}/', '已隐藏IP', $reason);
        $reason = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '已隐藏账号', $reason);

        return mb_substr($reason, 0, 160, 'UTF-8');
    }

    private function timestamp(SubscriptionRuleLog $log)
    {
        $raw = $log->getRawOriginal('created_at');
        if (is_numeric($raw)) {
            return (int)$raw;
        }
        if (is_numeric($log->created_at)) {
            return (int)$log->created_at;
        }
        return strtotime((string)$log->created_at) ?: time();
    }
}

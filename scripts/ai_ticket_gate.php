<?php

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '-1');
ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\AiRiskService;

$mode = (string)($argv[1] ?? 'core');
$service = new AiRiskService();
$config = (array)config('v2board', []);
$batch = 'gate_' . date('Ymd_His');
$log = '/tmp/v2b_ticket_gate_' . $batch . '.jsonl';

function gate_write(array $record): void
{
    global $log;
    file_put_contents($log, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
}

function gate_contains($text, $needle): bool
{
    return mb_stripos((string)$text, (string)$needle) !== false;
}

function gate_has_any($text, array $needles): bool
{
    foreach ($needles as $needle) {
        if ($needle !== '' && gate_contains($text, $needle)) {
            return true;
        }
    }
    return false;
}

function gate_context(array $case): array
{
    return [
        'question' => $case['question'],
        'source' => 'codex_gate',
        'ticket' => [
            'id' => 'gate_' . preg_replace('/\W+/u', '_', $case['name']),
            'subject' => $case['subject'] ?? $case['name'],
            'level' => 0,
            'status' => 0,
            'user_email' => '',
            'messages' => [[
                'from' => 'user',
                'message' => $case['question'],
                'created_at' => date('Y-m-d H:i:s')
            ]]
        ]
    ];
}

$cases = [
    [
        'name' => '付款未到账必须跳过',
        'question' => '充值未到账。订单2026060623061668473377102充值未到账',
        'expect_skip' => 'payment_order'
    ],
    [
        'name' => '买套餐但没节点不能误判付款',
        'question' => '订阅后无法使用。我6.8购买了季付订阅，但是没有节点可用。',
        'require_groups' => [['订阅', '链接', 'URL'], ['客户端', '代理软件'], ['重新添加', '重新导入', '添加订阅']],
        'forbid' => ['付款', '支付', '订单', '充值未到账']
    ],
    [
        'name' => '只说订阅报错要追问具体报错',
        'question' => '新的订阅链接报错',
        'require_groups' => [['报错文字', '具体提示', '提示文字', '文字提示', '什么内容', '弹出'], ['客户端', '代理软件']],
        'forbid' => ['网站订阅入口', '订阅地址更新', '已经换新', '光猫']
    ],
    [
        'name' => 'Clash自动选择组缺失',
        'question' => 'ClashMeta 提示 proxy group[0]: 自动选择 not found，节点全是 timeout。',
        'require_groups' => [['客户端', '版本', '教程页', '新版'], ['更新', '下载', '重新导入']],
        'forbid' => ['重启光猫', '飞行模式', '付款', '支付']
    ],
    [
        'name' => 'Hiddify domain_resolver',
        'question' => 'Hiddify 2.5.7 导入失败，提示 unknown field domain_resolver。',
        'require_groups' => [['Hiddify'], ['教程页', '新版', '更新'], ['重新导入', '重新添加']],
        'forbid' => ['重启光猫', '飞行模式', '付款', '订单']
    ],
    [
        'name' => 'x509低版本客户端',
        'question' => 'Meta 导入时报 java.security.cert.CertPathValidatorException: Trust anchor for certification path not found.',
        'require_groups' => [['版本', '新版', '教程页'], ['客户端']],
        'forbid' => ['服务器证书危险', '伪装服务器', '付款', '订单']
    ],
    [
        'name' => '全部节点超时',
        'question' => '订阅能更新，但是所有节点都 timeout，全红。',
        'require_groups' => [['光猫', 'ONT', '飞行模式'], ['本地网络', '宽带', '手机流量', '网络']],
        'forbid' => ['付款', '订单', '充值']
    ],
    [
        'name' => 'Loon不显示节点',
        'question' => 'Loon 导入订阅后不显示节点，列表是空的。',
        'require_groups' => [['Loon'], ['右上角', 'URL', '链接'], ['完整订阅', '订阅链接']],
        'forbid' => ['付款', '订单', '光猫']
    ],
    [
        'name' => '流量消耗疑问',
        'question' => '我的流量怎么用得这么快，客户端里面显示和面板不一样。',
        'require_groups' => [['流量明细', '仪表盘'], ['实际经过节点', '实际使用', '面板']],
        'forbid' => ['重启光猫', '飞行模式', '付款']
    ],
    [
        'name' => '网站打不开要说发布页',
        'question' => '网站打不开，之前收藏的那个地址进不去。',
        'require_groups' => [['发布页'], ['国内站', '海外站']],
        'forbid' => ['付款', '订单', '重置订阅']
    ],
    [
        'name' => '小火箭WiFi跳节点',
        'question' => '小火箭用流量正常，切到 WiFi 就一直跳节点，加载不出来。',
        'require_groups' => [['WiFi', '宽带', '光猫', 'ONT'], ['iOS', 'Loon', 'Surge', 'Stash']],
        'forbid' => ['安卓', '付款', '流量明细']
    ],
    [
        'name' => '工单不能发图片',
        'question' => '工单这里怎么发图片，我想发报错截图。',
        'require_groups' => [['文字', '报错文字'], ['售后群', '工单']],
        'forbid' => ['上传图片按钮', '付款', '订单']
    ]
];

if ($mode === 'quick') {
    $cases = array_slice($cases, 0, 4);
} elseif ($mode === 'history-tags') {
    $examplesPath = resource_path('ai/ticket_reply_examples.generated.json');
    $rows = json_decode((string)file_get_contents($examplesPath), true);
    if (!is_array($rows)) {
        throw new RuntimeException('examples json invalid');
    }
    $seenTags = [];
    $cases = [];
    foreach ($rows as $row) {
        $tag = (string)($row['tags'][0] ?? 'untagged');
        if (isset($seenTags[$tag])) {
            continue;
        }
        $seenTags[$tag] = true;
        $question = trim((string)($row['user_message'] ?? ''));
        if ($question === '') {
            continue;
        }
        $case = [
            'name' => '历史样本：' . $tag,
            'subject' => (string)($row['title'] ?? $tag),
            'question' => $question,
            'tag' => $tag,
            'history_assert' => true,
            'forbid' => ['您好', '帮你草拟', '内核', '核心', '数据库', 'token', '后台服务器']
        ];
        if (preg_match('/付款|支付|订单|充值|未到账|没到账|扣款|余额/u', $tag . "\n" . $question)) {
            $case['expect_skip'] = 'payment_order';
        }
        if (preg_match('/HY2|Hysteria2|版本不支持/u', $tag . "\n" . $question)) {
            $case['require_groups'] = [['客户端', '版本', '教程页', '新版', 'HY2']];
        } elseif (preg_match('/小火箭|Shadowrocket|证书/u', $tag . "\n" . $question)) {
            $case['require_groups'] = [['iOS', 'Loon', 'Surge', 'Stash', '教程页', '客户端', 'WiFi', '宽带', '光猫', 'ONT']];
            $case['forbid'][] = '安卓';
        } elseif (preg_match('/流量/u', $tag . "\n" . $question)) {
            $case['require_groups'] = [['流量明细', '仪表盘', '面板'], ['实际经过节点', '实际使用', '统计']];
        } elseif (preg_match('/网站打不开|网站|地址|打不开/u', $tag . "\n" . $question)) {
            $case['require_groups'] = [['发布页', '国内站', '海外站', '入口']];
        } elseif (preg_match('/Loon/u', $question)) {
            $case['require_groups'] = [['Loon'], ['URL', '链接', '右上角', '完整订阅']];
        } elseif (preg_match('/导入|订阅|节点|Clash|Hiddify|v2ray|Meta/u', $tag . "\n" . $question)) {
            $case['require_groups'] = [['订阅', '客户端', '节点', '导入', '报错', '教程页', '版本']];
        } elseif (preg_match('/登录|账号|密码|邮箱/u', $tag . "\n" . $question)) {
            $case['require_groups'] = [['登录', '邮箱', '账号', '验证码', '密码']];
            $case['forbid'][] = '节点';
        }
        $cases[] = $case;
    }
}

gate_write([
    'type' => 'meta',
    'batch' => $batch,
    'mode' => $mode,
    'count' => count($cases),
    'model' => $config['ticket_ai_model'] ?? '',
    'base_url' => $config['ticket_ai_base_url'] ?? '',
    'log' => $log
]);
echo $log . "\n";

$failures = 0;
foreach ($cases as $index => $case) {
    $context = gate_context($case);
    $start = microtime(true);
    $skipReason = $service->ticketAutoReplySkipReason($context);
    $reply = '';
    $error = '';
    $blockReason = '';
    $reasons = [];

    try {
        if (!$skipReason) {
            $reply = $service->generateTicketReplyDraft($context, $config);
            $blockReason = $service->ticketAutoPublishBlockReason($reply, $context);
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $reasons[] = 'exception';
    }

    if (($case['expect_skip'] ?? '') !== '') {
        if ($skipReason !== $case['expect_skip']) {
            $reasons[] = 'expected_skip_' . $case['expect_skip'];
        }
    } else {
        if ($skipReason !== '') {
            $reasons[] = 'unexpected_skip_' . $skipReason;
        }
        if ($blockReason !== '') {
            $reasons[] = 'blocked_' . $blockReason;
        }
        foreach (($case['require_groups'] ?? []) as $groupIndex => $needles) {
            if (!gate_has_any($reply, $needles)) {
                $reasons[] = 'missing_group_' . ($groupIndex + 1) . ':' . implode('|', $needles);
            }
        }
        foreach (($case['forbid'] ?? []) as $needle) {
            if ($needle !== '' && gate_contains($reply, $needle)) {
                $reasons[] = 'forbidden:' . $needle;
            }
        }
        if (!empty($case['history_assert'])) {
            if (mb_strlen(trim($reply)) < 40) {
                $reasons[] = 'history_too_short';
            }
            if (gate_contains($reply, '随时联系') || gate_contains($reply, '如有任何问题')) {
                $reasons[] = 'history_filler';
            }
            if (!preg_match('/付款|支付|订单|充值|未到账|没到账|扣款|余额/u', $case['tag'] . "\n" . $case['question'])
                && preg_match('/付款|支付|订单|充值|未到账|没到账|扣款|余额/u', $reply)) {
                $reasons[] = 'history_unrelated_payment';
            }
        }
    }

    $ok = empty($reasons);
    if (!$ok) {
        $failures++;
    }
    gate_write([
        'type' => 'case',
        'index' => $index + 1,
        'name' => $case['name'],
        'ok' => $ok,
        'elapsed' => round(microtime(true) - $start, 2),
        'skip_reason' => $skipReason,
        'block_reason' => $blockReason,
        'reasons' => $reasons,
        'question' => $case['question'],
        'reply' => $reply,
        'error' => $error
    ]);
}

gate_write([
    'type' => 'done',
    'batch' => $batch,
    'count' => count($cases),
    'failures' => $failures,
    'passed' => count($cases) - $failures
]);
echo 'failures=' . $failures . "\n";
exit($failures > 0 ? 2 : 0);

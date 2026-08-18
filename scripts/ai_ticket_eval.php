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

$limit = max(1, (int)($argv[1] ?? 14));
$mode = (string)($argv[2] ?? 'tag');
$examplesPath = resource_path('ai/ticket_reply_examples.generated.json');
$rows = json_decode((string)file_get_contents($examplesPath), true);
if (!is_array($rows)) {
    throw new RuntimeException('examples json invalid');
}

$selected = [];
if ($mode === 'lastfail') {
    $selected[] = [
        'title' => '付款后未到账',
        'tags' => ['付款后未到账'],
        'user_message' => '充值未到账。订单2026060623061668473377102充值未到账',
        'staff_reply' => '不能承诺已经核实或即将通知；只能说明已看到订单信息，客服会按订单继续核对。'
    ];
    $selected[] = [
        'title' => '小火箭 WiFi 下节点跳动',
        'tags' => ['小火箭证书提示'],
        'user_message' => '节点一直刷新加载不出来。用的小火箭，用流量开就会稳定一点不会刷新节点，切wifi就一直跳节点然后加载不出来东西，之前用wifi还好好的',
        'staff_reply' => '不能误判成流量明细；应按 WiFi/宽带网络不稳定或换 iOS 推荐客户端处理。'
    ];
} elseif ($mode === 'paymentskip') {
    $selected[] = [
        'title' => '付款后未到账',
        'tags' => ['付款后未到账'],
        'user_message' => '充值未到账。订单2026060623061668473377102充值未到账',
        'staff_reply' => '订单付款类工单应该跳过 AI 自动回复，交给人工核对。'
    ];
} elseif ($mode === 'noclient') {
    $selected[] = [
        'title' => '无客户端信息：订阅后无节点',
        'tags' => ['无客户端信息'],
        'user_message' => '订阅后无法使用。我6.8购买了季付的订阅，但无法使用，没有节点可用',
        'staff_reply' => '不要误判成付款未到账；没有客户端名称时用通用添加订阅/URL 导入步骤。'
    ];
} elseif ($mode === 'focus') {
    $focusTags = [
        '客户端版本不支持 HY2',
        '流量显示或消耗疑问',
        '节点地区显示疑问',
        '付款后未到账',
        'Loon 不显示节点'
    ];
    foreach ($focusTags as $focusTag) {
        foreach ($rows as $row) {
            $tag = (string)(($row['tags'][0] ?? 'untagged'));
            if ($tag === $focusTag) {
                $selected[] = $row;
                break;
            }
        }
    }
} elseif ($mode === 'tag') {
    $seen = [];
    foreach ($rows as $row) {
        $tag = (string)(($row['tags'][0] ?? 'untagged'));
        if (isset($seen[$tag])) {
            continue;
        }
        $seen[$tag] = true;
        $selected[] = $row;
        if (count($selected) >= $limit) {
            break;
        }
    }
} else {
    $selected = array_slice($rows, 0, $limit);
}

$config = (array)config('v2board', []);
$service = new AiRiskService();
$batch = 'eval_' . date('Ymd_His');
$log = '/tmp/v2b_ticket_eval_' . $batch . '.jsonl';
$progress = '/tmp/v2b_ticket_eval_' . $batch . '.progress';

function eval_write_jsonl($path, array $record): void
{
    file_put_contents($path, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
}

function eval_contains($text, $needle): bool
{
    return mb_stripos((string)$text, (string)$needle) !== false;
}

eval_write_jsonl($log, [
    'type' => 'meta',
    'batch' => $batch,
    'count' => count($selected),
    'mode' => $mode,
    'model' => $config['ticket_ai_model'] ?? '',
    'base_url' => $config['ticket_ai_base_url'] ?? ''
]);
file_put_contents($progress, "0/" . count($selected) . " " . $log . "\n");
echo $log . "\n";

foreach ($selected as $i => $row) {
    $tag = (string)(($row['tags'][0] ?? 'untagged'));
    $question = (string)($row['user_message'] ?? '');
    file_put_contents($progress, ($i + 1) . "/" . count($selected) . " running " . $tag . "\n");

    $context = [
        'question' => $question,
        'source' => 'codex_eval_history',
        'ticket' => [
            'id' => 'eval_' . ($i + 1),
            'subject' => (string)($row['title'] ?? $tag),
            'level' => 0,
            'status' => 0,
            'user_email' => '',
            'messages' => [[
                'from' => 'user',
                'message' => $question,
                'created_at' => date('Y-m-d H:i:s')
            ]]
        ]
    ];

    $start = microtime(true);
    $ok = true;
    $reply = '';
    $error = '';
    $skipReason = $service->ticketAutoReplySkipReason($context);
    try {
        if (!$skipReason) {
            $reply = $service->generateTicketReplyDraft($context, $config);
        }
    } catch (Throwable $exception) {
        $ok = false;
        $error = $exception->getMessage();
    }
    $elapsed = round(microtime(true) - $start, 2);

    $risk = [];
    foreach (['截图', '内核', '核心', '您好', '帮你草拟', '已收到您的付款信息', '已经收到您的付款信息', '正在处理中', '正在核实', '正在核对中', '第一时间给您回复', '第一时间通知', 'Shadowrocket'] as $needle) {
        if ($reply !== '' && eval_contains($reply, $needle) && !eval_contains($question, $needle)) {
            $risk[] = $needle;
        }
    }
    if ($reply !== '' && preg_match('/已(经)?收到.*订单信息/u', $reply)) {
        $risk[] = 'claim_received_order_info';
    }
    if ($reply !== '' && mb_strlen($reply) < 35) {
        $risk[] = 'too_short';
    }
    if ($reply !== '' && preg_match('/订阅可能遇到了一些问题|信息还不太清楚/u', $reply)) {
        $risk[] = 'vague';
    }
    if ($reply !== '' && !preg_match('/AI\s*小助手/u', $reply)) {
        $risk[] = 'no_ai_identity';
    }

    eval_write_jsonl($log, [
        'type' => 'case',
        'index' => $i + 1,
        'tag' => $tag,
        'ok' => $ok,
        'elapsed' => $elapsed,
        'risk' => $risk,
        'question' => $question,
        'expected' => (string)($row['staff_reply'] ?? ''),
        'reply' => $reply,
        'skip_reason' => $skipReason,
        'error' => $error
    ]);
    file_put_contents($progress, ($i + 1) . "/" . count($selected) . " done " . $tag . " risk=" . json_encode($risk, JSON_UNESCAPED_UNICODE) . "\n");
}

file_put_contents($progress, "done " . count($selected) . "/" . count($selected) . " " . $log . "\n");
eval_write_jsonl($log, [
    'type' => 'done',
    'batch' => $batch,
    'count' => count($selected)
]);

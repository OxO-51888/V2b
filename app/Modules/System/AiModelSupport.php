<?php

namespace App\Modules\System;

use App\Models\SubscriptionRule;
use App\Models\SubscriptionRuleLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

trait AiModelSupport
{
    private const OPENAI_BASE_URL = 'https://api.openai.com/v1';
    private const OPENAI_MODEL = 'gpt-5-nano';
    private const RUNTIME_CACHE_KEY = 'SUBSCRIPTION_RULE_AI_RUNTIME_STATUS';

    private function shouldRetryWithLegacyMaxTokens($data)
    {
        $message = strtolower((string)($data['error']['message'] ?? ''));
        return strpos($message, 'max_completion_tokens') !== false
            && (strpos($message, 'unsupported') !== false || strpos($message, 'unrecognized') !== false);
    }

    private function trimText($text, $length)
    {
        $text = trim($text);
        return mb_substr($text, 0, $length);
    }

    private function markRuntimeStatus($running, $reason)
    {
        Cache::put(self::RUNTIME_CACHE_KEY, [
            'running' => (bool)$running,
            'reason' => (string)$reason,
            'checked_at' => time(),
            'source' => 'openai'
        ], 86400);
    }
}

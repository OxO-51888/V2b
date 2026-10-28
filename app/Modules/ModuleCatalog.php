<?php

namespace App\Modules;

final class ModuleCatalog
{
    public const NAMES = [
        'core' => '核心业务',
        'client_gateway' => '客户端网关',
        'telegram' => 'Telegram',
        'support' => '客服中心',
        'security' => '安全风控',
        'system' => '系统运行',
    ];

    private const CHECKS = [
        'core_services' => 'core', 'payment' => 'core',
        'gateway' => 'client_gateway', 'telegram' => 'telegram',
        'support_services' => 'support', 'community' => 'support', 'ticket_ai' => 'support',
        'security_services' => 'security',
        'runtime' => 'system', 'database' => 'system', 'cache' => 'system',
        'scheduler' => 'system', 'horizon' => 'system', 'storage' => 'system', 'mail' => 'system',
    ];

    public static function queueModule(string $name): string
    {
        return match ($name) {
            'order_handle', 'traffic_fetch', 'stat' => 'core',
            'send_telegram' => 'telegram',
            default => 'system',
        };
    }

    public static function taskModule(string $command): string
    {
        return match ($command) {
            'traffic:update', 'check:order', 'check:commission', 'reset:traffic',
            'v2board:statistics', 'check:renewal' => 'core',
            'check:ticket' => 'support',
            default => 'system',
        };
    }

    public static function sortRows(array $rows): array
    {
        $order = array_flip(array_keys(self::NAMES));
        usort($rows, fn($a, $b) => ($order[$a['module']] ?? 99) <=> ($order[$b['module']] ?? 99));
        return $rows;
    }

    public static function groupChecks(array $checks): array
    {
        $groups = [];
        foreach (self::NAMES as $id => $name) {
            $groups[$id] = ['id' => $id, 'name' => $name, 'status' => 'unknown', 'checks' => []];
        }
        foreach ($checks as $check) {
            $id = self::CHECKS[$check['id']] ?? 'system';
            $groups[$id]['checks'][] = $check;
        }
        foreach ($groups as &$group) {
            $states = array_column($group['checks'], 'status');
            // Configuration is not a successful end-to-end health check.
            foreach (['error', 'warning', 'unknown', 'unconfigured', 'configured', 'ok', 'disabled'] as $state) {
                if (in_array($state, $states, true)) {
                    $group['status'] = $state;
                    break;
                }
            }
        }
        unset($group);
        return array_values($groups);
    }
}

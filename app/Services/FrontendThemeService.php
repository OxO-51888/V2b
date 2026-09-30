<?php

namespace App\Services;

use Illuminate\Http\Request;

class FrontendThemeService
{
    public function domesticEntryReady(): bool
    {
        return count($this->domesticHosts()) > 0
            && is_file(public_path('theme/default/config.json'))
            && is_file(public_path('theme/default/dashboard.blade.php'))
            && is_file(public_path('theme/NINI/config.json'))
            && is_file(public_path('theme/NINI/dashboard.blade.php'));
    }

    public function activeTheme(): string
    {
        if (array_key_exists('frontend_domestic_default_theme', config('v2board', []))) {
            return (int) config('v2board.frontend_domestic_default_theme') === 1 ? 'NINI' : 'default';
        }

        return (string) config('v2board.frontend_theme', 'default');
    }

    public function usesDomesticDefault(Request $request): bool
    {
        if ((int) config('v2board.frontend_domestic_default_theme', 0) !== 1) {
            return false;
        }

        // Use the Host received by this site, never a browser-supplied forwarding header.
        $host = $this->normalizeHost($request->server('HTTP_HOST', ''));
        return $host !== '' && in_array($host, $this->domesticHosts(), true);
    }

    public function resolve(Request $request): string
    {
        return $this->usesDomesticDefault($request)
            ? 'default'
            : $this->activeTheme();
    }

    private function domesticHosts(): array
    {
        $hosts = config('frontend.domestic_hosts', []);
        if (!is_array($hosts)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(function ($host) {
            return is_string($host) ? $this->normalizeHost($host) : '';
        }, $hosts))));
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (!preg_match('/^([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.?)(?::([0-9]{1,5}))?$/D', $host, $matches)) {
            return '';
        }
        if (isset($matches[2]) && (int) $matches[2] > 65535) {
            return '';
        }

        return rtrim($matches[1], '.');
    }
}

<?php

namespace App\Logging;

use Throwable;

class SafeLogData
{
    private $secrets = [];
    private $visited = 0;
    private $remainingFields = 128;

    public function rememberSecrets(array $input, int $depth = 0): void
    {
        if ($depth > 4) return;
        foreach ($input as $key => $value) {
            if (++$this->visited > 200) break;
            if (is_array($value)) {
                $this->rememberSecrets($value, $depth + 1);
            } elseif (is_scalar($value) && $this->sensitive((string) $key) && strlen((string) $value) > 0) {
                $secret = substr((string) $value, 0, 2048);
                $this->secrets[] = $secret;
                $this->secrets[] = rawurlencode($secret);
            }
        }
        usort($this->secrets, function ($a, $b) { return strlen($b) <=> strlen($a); });
    }

    public function message($value): string
    {
        $text = is_string($value) || is_numeric($value) ? substr((string) $value, 0, 2048) : '[non-scalar message]';
        if (preg_match('/SQLSTATE\[[A-Z0-9]+\]/', $text, $match)) return 'Database operation failed (' . $match[0] . ')';
        if ($this->secrets) $text = str_replace($this->secrets, '[redacted]', $text);
        $text = preg_replace('~(https?://[^\s?]+)\?[^\s]*~i', '$1?[redacted]', $text);
        $text = preg_replace('~(https?://)[^/@\s]+:[^/@\s]+@~i', '$1[redacted]@', $text);
        $text = preg_replace('~(authorization|password|passwd|secret|token|api[_-]?key|email[_-]?code|code)(["\s:=]+)(?:Bearer\s+)?[^\s,;"}]+~i', '$1$2[redacted]', $text);
        $text = preg_replace('~\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b~i', '[email]', $text);
        $text = preg_replace('~\b\d{6,}:[A-Za-z0-9_-]{20,}\b|\beyJ[A-Za-z0-9_.-]{20,}\b~', '[token]', $text);
        return mb_strcut($text, 0, 2048, 'UTF-8');
    }

    public function uri($request): string
    {
        $route = $request->route();
        $path = is_object($route) && method_exists($route, 'uri') ? '/' . ltrim($route->uri(), '/') : $request->getPathInfo();
        $path = preg_replace('~[A-Za-z0-9_-]{24,}~', '[id]', $this->message($path));
        return mb_strcut($path, 0, 255, 'UTF-8');
    }

    public function fields(array $input, int $depth = 0): array
    {
        $safe = [];
        if ($depth > 3) return ['_truncated' => true];
        $count = 0;
        foreach ($input as $key => $value) {
            if (++$count > 24 || --$this->remainingFields < 0) { $safe['_truncated'] = true; break; }
            $key = mb_strcut((string) $key, 0, 64, 'UTF-8');
            if ($this->sensitive($key)) {
                $safe[$key] = '[redacted]';
            } elseif ($value instanceof Throwable) {
                $trace = [];
                foreach (array_slice($value->getTrace(), 0, 8) as $frame) {
                    $trace[] = array_intersect_key($frame, array_flip(['file', 'line', 'class', 'function']));
                }
                $safe[$key] = ['class' => get_class($value), 'message' => $this->message($value->getMessage()),
                    'code' => $value->getCode(), 'file' => $value->getFile(), 'line' => $value->getLine(), 'trace' => $trace];
            } elseif (is_array($value)) {
                $safe[$key] = $this->fields($value, $depth + 1);
            } elseif (preg_match('/^(user_id|plan_id|server_id|order_id|status|page|limit|count|duration|attempt|retry|line)$/D', $key)
                && is_numeric($value)) {
                $safe[$key] = $value;
            } elseif (is_bool($value) || $value === null) {
                $safe[$key] = $value;
            } else {
                $safe[$key] = '[redacted]';
            }
        }
        return $safe;
    }

    public function json(array $value): string
    {
        $json = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return strlen($json) <= 16384 ? $json : '{"_truncated":true}';
    }

    private function sensitive(string $key): bool
    {
        return (bool) preg_match('/pass|secret|token|auth|cookie|session|credential|api.?key|code|subscribe|private.?key/i', $key);
    }
}

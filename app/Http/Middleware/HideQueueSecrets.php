<?php

namespace App\Http\Middleware;

use App\Jobs\SendEmailJob;
use App\Jobs\SendTelegramBindingEmail;
use Closure;

class HideQueueSecrets
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);
        $path = trim((string) config('horizon.path', 'horizon'), '/');
        $prefix = ($path === '' ? '' : $path . '/') . 'api/';
        if (!$request->is($prefix . '*')
            || stripos((string) $response->headers->get('Content-Type'), 'application/json') === false) {
            return $response;
        }

        $data = json_decode($response->getContent());
        if (json_last_error() !== JSON_ERROR_NONE) return $response;
        $data = $this->redact($data);
        $safe = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($safe === false) abort(500, 'Unable to render queue diagnostics');
        if ($response instanceof \Symfony\Component\HttpFoundation\JsonResponse) $response->setData($data);
        else $response->setContent($safe);
        $response->headers->remove('Content-Length');
        $response->headers->remove('ETag');
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }

    private function redact($value)
    {
        if (!is_object($value) && !is_array($value)) return $value;

        if (is_object($value)) {
            $payload = $value->payload ?? null;
            $encoded = is_string($payload);
            if ($encoded) $payload = json_decode($payload);
            $mail = in_array($value->name ?? null, [SendEmailJob::class, SendTelegramBindingEmail::class], true)
                || in_array($payload->displayName ?? null, [SendEmailJob::class, SendTelegramBindingEmail::class], true)
                || in_array($payload->data->commandName ?? null, [SendEmailJob::class, SendTelegramBindingEmail::class], true);

            if ($mail) {
                // Redact the response only: legacy stored jobs must remain executable/retryable.
                if (is_object($payload)) {
                    $payload->data = (object) ['commandName' => $payload->data->commandName ?? $value->name ?? $payload->displayName,
                        'command' => '[redacted]'];
                    $value->payload = $encoded ? json_encode($payload) : $payload;
                } elseif (property_exists($value, 'payload')) {
                    $value->payload = '[redacted]';
                }
                foreach (['exception', 'context'] as $field) {
                    if (isset($value->$field)) $value->$field = $field === 'context' ? new \stdClass() : '[redacted]';
                }
            }
        }
        foreach ($value as $key => $item) {
            if (is_array($value)) $value[$key] = $this->redact($item);
            else $value->$key = $this->redact($item);
        }
        return $value;
    }
}

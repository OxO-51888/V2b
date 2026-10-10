<?php

namespace App\Services\ClientGateway;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

class Entry
{
    private $http;
    public function __construct(?ClientInterface $http = null) { $this->http = $http ?? new Client(); }

    public static function domain(string $value): string
    {
        $value = strtolower(trim($value));
        if (strpos($value, 'https://') === 0) $value = substr($value, 8);
        $value = rtrim($value, '/');
        abort_unless(strlen($value) <= 253 && strpos($value, '.') !== false
            && preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $value)
            && !preg_match('/\.(?:localhost|local|internal|invalid|test)\z/', $value), 422, '请输入有效的 HTTPS 域名，不要填写路径或端口');
        return $value;
    }

    protected function provision(string $domain): void
    {
        $site = (string) config('panel_modules.site');
        abort_unless(preg_match('/\A[a-z0-9_-]{1,32}\z/', $site), 503, '本站入口尚未部署');
        $process = new Process(['/usr/bin/sudo', '-n', '/usr/local/sbin/panel-gateway-entry', $site, $domain]);
        $process->setTimeout(20);
        try { $process->run(); } catch (\Throwable $e) { abort(503, '入口配置超时，原设置未保存'); }
        $result = json_decode($process->getOutput(), true);
        $errors = ['HOST_NOT_READY' => '请先在宝塔为该域名建立 HTTPS 站点',
            'HOST_CONFLICT' => '该域名存在不兼容的入口配置，请先检查',
            'NGINX_CHECK_FAILED' => '入口配置检查失败，原配置已保留'];
        abort_unless($process->isSuccessful() && ($result['ok'] ?? false), 422,
            $errors[$result['code'] ?? ''] ?? '入口配置失败，原设置未保存');
    }

    protected function addresses(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            abort_unless($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE), 422, '域名必须解析到公网地址');
            $addresses[] = $ip;
        }
        abort_unless($addresses, 422, '域名尚未解析，原设置未保存');
        return array_values(array_unique($addresses));
    }

    public function configure(Gateway $gateway, string $input, string $revision): array
    {
        $domain = self::domain($input);
        $addresses = $this->addresses($domain);
        return $gateway->configureEntry($domain, $revision, function (string $public) use ($domain, $addresses) {
            abort_unless(hash_equals((string) config('panel_modules.gateway.client_key_hash'), hash('sha256', trim($public))),
                503, '授权公钥与客户端不匹配，不能启用新入口');
            $this->provision($domain);
            $challenge = bin2hex(random_bytes(32));
            $path = '/.well-known/panel-gateway/' . config('panel_modules.gateway.panel_id');
            foreach ($addresses as $ip) {
                try {
                    $response = $this->http->request('GET', 'https://' . $domain . $path, [
                        'query' => ['challenge' => $challenge], 'headers' => ['Accept' => 'application/json', 'User-Agent' => 'Mozilla/5.0 PanelGatewayCheck/1.0'],
                        'connect_timeout' => 3, 'timeout' => 6, 'allow_redirects' => false, 'http_errors' => false,
                        'verify' => true, 'proxy' => '', 'stream' => true,
                        'curl' => [CURLOPT_RESOLVE => [$domain . ':443:' . (strpos($ip, ':') === false ? $ip : '[' . $ip . ']')]],
                    ]);
                    $raw = $response->getBody()->read(16385);
                    $response->getBody()->close();
                    $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
                } catch (\Throwable $e) { abort(422, 'HTTPS 或反代链路验证失败，原设置未保存'); }
                $claim = $data['claim'] ?? [];
                $signature = base64_decode(strtr($data['signature'] ?? '', '-_', '+/'), true);
                $key = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $public), true);
                abort_unless($response->getStatusCode() === 200 && strlen($raw) <= 16384
                    && ($claim['challenge'] ?? '') === $challenge
                    && ($claim['panelId'] ?? '') === config('panel_modules.gateway.panel_id')
                    && ($claim['service'] ?? '') === 'panel-client-gateway'
                    && ($claim['publicKeyHash'] ?? '') === hash('sha256', trim($public))
                    && strlen($key) === 44 && strlen($signature ?: '') === 64
                    && sodium_crypto_sign_verify_detached($signature, Crypto::canonical($claim), substr($key, 12)),
                    422, '域名未正确连接本站网关或授权公钥不匹配，原设置未保存');
            }
        });
    }

}

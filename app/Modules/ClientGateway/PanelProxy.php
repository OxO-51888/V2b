<?php

namespace App\Modules\ClientGateway;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Http\Request;

class PanelProxy
{
    private $http;
    private const GET = ['/user/getSubscribe', '/user/plan/fetch', '/user/info', '/user/server/fetch',
        '/user/order/fetch', '/user/comm/config', '/user/order/detail', '/user/order/getPaymentMethod',
        '/user/order/check', '/user/ticket/fetch', '/user/stat/getTrafficLog', '/user/client/state'];
    private const POST = ['/passport/auth/login', '/user/coupon/check', '/user/order/save',
        '/user/order/checkout', '/user/order/cancel', '/user/ticket/save', '/user/ticket/reply', '/user/ticket/close'];
    private const PERIODS = ['month_price', 'quarter_price', 'half_year_price', 'year_price', 'two_year_price',
        'three_year_price', 'onetime_price', 'reset_price'];

    public function __construct(?ClientInterface $http = null) { $this->http = $http ?? new Client(); }
    public function allowed(string $method, string $endpoint): bool
    {
        return ($method === 'GET' && in_array($endpoint, self::GET, true))
            || ($method === 'POST' && in_array($endpoint, self::POST, true));
    }

    public function handle(Request $request, string $endpoint, array $panel): array
    {
        if (!$this->allowed($request->method(), $endpoint)) $this->fail('PANEL_GATEWAY_ENDPOINT_DENIED', 403);
        $authorization = $request->header('X-Panel-Authorization', '');
        if (!is_string($authorization) || strlen($authorization) > 500 || preg_match('/[\x00-\x1f\x7f]/', $authorization)) $this->fail('PANEL_GATEWAY_BODY_INVALID');
        $query = [];
        if (in_array($endpoint, ['/user/order/detail', '/user/order/check'], true)) $query['trade_no'] = $this->trade($request->query('trade_no'));
        if ($endpoint === '/user/ticket/fetch' && $request->query('id') !== null) $query['id'] = $this->integer($request->query('id'));
        if ($endpoint === '/user/client/state') {
            $scope = $request->query('scope', 'full');
            if (!is_string($scope) || !in_array($scope, ['full', 'account'], true)) $this->fail('PANEL_GATEWAY_QUERY_INVALID');
            $query['scope'] = $scope;
        }
        $payload = null;
        if ($request->isMethod('POST')) {
            try { $body = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { $this->fail('PANEL_GATEWAY_BODY_INVALID'); }
            if (!is_array($body)) $this->fail('PANEL_GATEWAY_BODY_INVALID');
            $payload = $this->sanitize($endpoint, $body);
        }
        // Business eligibility belongs to the authenticated panel controllers. Public
        // plan visibility is not purchase permission, and PaymentAttemptService
        // already validates ownership/status while safely switching payment methods.
        return $this->send($request->method(), $endpoint, $panel, $authorization, $query, $payload, true);
    }

    private function send(string $method, string $endpoint, array $panel, string $authorization, array $query = [], ?array $payload = null, bool $preserveObjects = false): array
    {
        $base = rtrim($panel['panelUrl'], '/');
        $url = parse_url($base);
        if (($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass']) || isset($url['query']) || !empty($url['path'])) {
            $this->fail('PANEL_GATEWAY_NOT_CONFIGURED', 503);
        }
        $sign = $panel['apiSign'] ?? [];
        if (empty($sign['clientId']) || empty($sign['clientVersion']) || empty($sign['signSecret'])) $this->fail('PANEL_GATEWAY_NOT_CONFIGURED', 503);
        $path = '/api/v1' . $endpoint;
        $time = (string) time(); $nonce = bin2hex(random_bytes(16));
        $headers = ['Accept' => 'application/json', 'User-Agent' => 'AppGateAuthGateway/' . $sign['clientVersion'],
            'X-Xiao-Client' => $sign['clientId'], 'X-Xiao-Version' => $sign['clientVersion'], 'X-Xiao-Timestamp' => $time,
            'X-Xiao-Nonce' => $nonce, 'X-Xiao-Sign' => hash_hmac('sha256', implode("\n", [$method, $path, $time, $nonce, $sign['clientId'], $sign['clientVersion']]), $sign['signSecret'])];
        if ($authorization !== '') $headers['Authorization'] = $authorization;
        $options = ['headers' => $headers, 'query' => $query, 'connect_timeout' => 3, 'timeout' => 30,
            'allow_redirects' => false, 'http_errors' => false];
        if ($payload !== null) $options['json'] = $payload;
        try { $response = $this->http->request($method, $base . $path, $options); }
        catch (\Throwable $e) { $this->fail('PANEL_GATEWAY_FAILED', 502); }
        if ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400) $this->fail('PANEL_GATEWAY_INVALID_RESPONSE', 502);
        $raw = (string) $response->getBody();
        try { $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { $this->fail('PANEL_GATEWAY_INVALID_RESPONSE', 502); }
        if (!is_array($data)) $this->fail('PANEL_GATEWAY_INVALID_RESPONSE', 502);
        // Retain object-vs-array JSON semantics for empty data objects.
        return [$response->getStatusCode(), $preserveObjects ? json_decode($raw, false, 512, JSON_THROW_ON_ERROR) : $data];
    }

    private function sanitize(string $endpoint, array $b): array
    {
        switch ($endpoint) {
            case '/passport/auth/login':
                $email = strtolower($this->text($b['email'] ?? '', 160));
                $password = $this->text($b['password'] ?? '', 512);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $this->fail('PANEL_GATEWAY_BODY_INVALID');
                return compact('email', 'password');
            case '/user/coupon/check':
                return ['code' => $this->text($b['code'] ?? '', 80), 'plan_id' => $this->integer($b['plan_id'] ?? null)];
            case '/user/order/save':
                if (!in_array($b['period'] ?? '', self::PERIODS, true)) $this->fail('PANEL_GATEWAY_BODY_INVALID');
                $data = ['plan_id' => $this->integer($b['plan_id'] ?? null), 'period' => $b['period']];
                if (!empty($b['coupon_code'])) $data['coupon_code'] = $this->text($b['coupon_code'], 80);
                return $data;
            case '/user/order/checkout':
                return ['trade_no' => $this->trade($b['trade_no'] ?? null), 'method' => $this->integer($b['method'] ?? null, 0), 'token' => ''];
            case '/user/order/cancel': return ['trade_no' => $this->trade($b['trade_no'] ?? null)];
            case '/user/ticket/save':
                $level = $this->integer($b['level'] ?? null, 0);
                if ($level > 2) $this->fail('PANEL_GATEWAY_BODY_INVALID');
                return ['subject' => $this->text($b['subject'] ?? '', 120), 'level' => $level, 'message' => $this->text($b['message'] ?? '', 2000)];
            case '/user/ticket/reply': return ['id' => $this->integer($b['id'] ?? null), 'message' => $this->text($b['message'] ?? '', 2000)];
            case '/user/ticket/close': return ['id' => $this->integer($b['id'] ?? null)];
        }
        $this->fail('PANEL_GATEWAY_ENDPOINT_DENIED', 403);
    }

    private function integer($value, int $min = 1): int
    {
        $number = is_scalar($value) && !is_bool($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        if ($number === false || $number < $min || $number > 2147483647) $this->fail('PANEL_GATEWAY_BODY_INVALID');
        return $number;
    }

    private function trade($value): string
    {
        if (!is_string($value) || !preg_match('/\A[A-Za-z0-9_-]{6,100}\z/', $value)) $this->fail('PANEL_GATEWAY_QUERY_INVALID');
        return $value;
    }

    private function text($value, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) $this->fail('PANEL_GATEWAY_BODY_INVALID');
        return trim($value);
    }

    private function fail(string $code, int $status = 400): void { throw new GatewayException($status, $code, '网站请求未通过校验，请稍后重试'); }
}

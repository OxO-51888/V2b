<?php

namespace App\Modules\ClientGateway;

use App\Http\Controllers\V1\User\CommController;
use App\Http\Controllers\V1\User\OrderController;
use App\Http\Controllers\V1\User\PlanController;
use App\Http\Controllers\V1\User\ServerController;
use App\Http\Controllers\V1\User\StatController;
use App\Http\Controllers\V1\User\UserController;
use App\Models\User;
use App\Modules\Core\PlanPurchasePolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** A fixed read model. No caller-controlled routes, handlers or query forwarding. */
class ClientStateService
{
    private const ACCOUNT = [
        'user' => [UserController::class, 'info', '/user/info'],
        'subscribe' => [UserController::class, 'getSubscribe', '/user/getSubscribe'],
    ];
    private const FULL = [
        'servers' => [ServerController::class, 'fetch', '/user/server/fetch'],
        'plans' => [PlanController::class, 'fetch', '/user/plan/fetch'],
        'orders' => [OrderController::class, 'fetch', '/user/order/fetch'],
        'config' => [CommController::class, 'config', '/user/comm/config'],
        'trafficLogs' => [StatController::class, 'getTrafficLog', '/user/stat/getTrafficLog'],
    ];
    private const LABELS = ['user' => '账户信息', 'subscribe' => '订阅信息', 'servers' => '节点列表',
        'plans' => '套餐列表', 'orders' => '订单列表', 'config' => '站点配置', 'trafficLogs' => '流量记录', 'renewal' => '续费选项'];

    public function snapshot(Request $request, string $scope): array
    {
        if (!in_array($scope, ['full', 'account'], true)) abort(422, '客户端状态范围无效');
        $identity = $request->input('user');
        if (!is_array($identity) || (int) ($identity['id'] ?? 0) <= 0) abort(403, '未登录或登陆已过期');
        $state = ['schemaVersion' => 1, 'scope' => $scope, 'snapshotAt' => time(),
            'capabilities' => ['paymentMethodSwitch' => true, 'currentPlanRenewal' => true, 'subscriptionRuleExplanations' => true]];
        $sections = self::ACCOUNT + ($scope === 'full' ? self::FULL : []);
        $errors = [];
        foreach ($sections as $section => $handler) {
            try {
                // A fresh request deliberately drops scope/id/status and conditional
                // headers. In particular, an outer ETag must not turn servers into 304.
                $child = Request::create('/api/v1' . $handler[2], 'GET');
                $child->merge(['user' => $identity]);
                $state[$section] = $this->readSection($handler, $child);
            } catch (\Throwable $error) {
                $this->rethrowAuthentication($error);
                $errors[$section] = self::LABELS[$section] . '暂时无法加载，请稍后重试';
            }
        }
        try {
            $state['renewal'] = $this->readRenewal((int) $identity['id']);
        } catch (\Throwable $error) {
            $this->rethrowAuthentication($error);
            $errors['renewal'] = self::LABELS['renewal'] . '暂时无法加载，请稍后重试';
        }
        $state['sectionErrors'] = (object) $errors;
        return $state;
    }

    protected function readSection(array $handler, Request $request)
    {
        $response = app($handler[0])->{$handler[1]}($request);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) abort($status, '客户端状态读取失败');
        $payload = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($payload) || !property_exists($payload, 'data')) throw new \UnexpectedValueException('Missing state data');
        return $payload->data;
    }

    protected function readRenewal(int $userId): array
    {
        $user = User::find($userId);
        if (!$user || $user->banned) abort(403, '未登录或登陆已过期');
        return app(PlanPurchasePolicy::class)->currentOptions($user);
    }

    private function rethrowAuthentication(\Throwable $error): void
    {
        if ($error instanceof AuthenticationException || $error instanceof AuthorizationException
            || ($error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [401, 403], true))) {
            throw $error;
        }
    }
}

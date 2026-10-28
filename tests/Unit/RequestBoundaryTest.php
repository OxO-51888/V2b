<?php

namespace Tests\Unit;

use App\Http\Middleware\UAfilter;
use Illuminate\Http\Request;
use Tests\TestCase;

class RequestBoundaryTest extends TestCase
{
    public function testAbsentOptionalHeadersDoNotProducePhp85Warnings(): void
    {
        set_error_handler(static function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $request = Request::create('/fixture');
            $request->headers->remove('User-Agent');
            $response = (new UAfilter())->handle($request, fn () => response('ok'));
            $this->assertSame('ok', $response->getContent());
            $response = (new \App\Http\Middleware\CORS())->handle($request, fn () => response('ok'));
            $this->assertSame('ok', $response->getContent());
            $response = (new \App\Http\Controllers\V1\Client\AppController())->getVersion($request);
            $this->assertSame(200, $response->getStatusCode());
        } finally {
            restore_error_handler();
        }
    }

    public function testExistingBrowserFilteringAndClientVersionBranchesRemainIntact(): void
    {
        config(['v2board.windows_version' => 'win-fixture', 'v2board.macos_version' => 'mac-fixture']);
        foreach (['MicroMessenger', 'QQ/9.0'] as $agent) {
            $request = Request::create('/fixture', 'GET', [], [], [], ['HTTP_USER_AGENT' => $agent]);
            $response = (new UAfilter())->handle($request, fn () => response('allowed'));
            $this->assertStringContainsString('Unsupported Browser', $response->getContent());
        }
        foreach (['tidalab/4.0.0 Win64' => 'win-fixture', 'tunnelab/4.0.0 Macintosh' => 'mac-fixture'] as $agent => $expected) {
            $request = Request::create('/fixture', 'GET', [], [], [], ['HTTP_USER_AGENT' => $agent]);
            $response = (new \App\Http\Controllers\V1\Client\AppController())->getVersion($request);
            $this->assertSame($expected, json_decode($response->getContent(), true)['data']['version']);
        }
    }

    public function testPaymentProviderErrorsStayPrivateWithoutChangingSuccessfulPayloads(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $alipay = \Mockery::mock('overload:Library\\AlipayF2F');
        foreach (['setMethod', 'setAppId', 'setPrivateKey', 'setAlipayPublicKey', 'setNotifyUrl', 'setBizContent'] as $method) {
            $alipay->shouldReceive($method)->andReturnNull();
        }
        $alipay->shouldReceive('send')->andThrow(new \RuntimeException('provider-private-key-fixture'));
        $payment = new \App\Payments\AlipayF2F(['app_id' => 'fixture', 'private_key' => 'fixture', 'public_key' => 'fixture']);
        $order = ['notify_url' => 'https://fixture.invalid/notify', 'return_url' => 'https://fixture.invalid/return',
            'trade_no' => 'fixture-order', 'total_amount' => 100];
        try {
            $payment->pay($order);
            $this->fail('Expected provider rejection');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(__('Failed to create order'), $e->getMessage());
        }
        $session = \Mockery::mock('alias:Stripe\\Checkout\\Session');
        $session->shouldReceive('create')->once()->andReturn((object)['url' => 'https://fixture.invalid/payment']);
        $session->shouldReceive('create')->once()->andThrow(new \RuntimeException('provider-private-key-fixture'));
        $stripe = new \App\Payments\StripeCheckout(['currency' => 'CNY', 'stripe_sk_live' => 'fixture-key']);
        $this->assertSame(['type' => 1, 'data' => 'https://fixture.invalid/payment'], $stripe->pay($order));
        try {
            $stripe->pay($order);
            $this->fail('Expected provider rejection');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(__('Failed to create order'), $e->getMessage());
        } finally {
            \Mockery::close();
        }
    }
}

namespace App\Payments;

// Only this test process resolves the exchange-rate lookup to a fixed offline response.
function file_get_contents($url)
{
    if ($url !== 'https://api.exchangerate-api.com/v4/latest/CNY') {
        throw new \RuntimeException('Unexpected fixture URL');
    }
    return '{"rates":{"CNY":1}}';
}

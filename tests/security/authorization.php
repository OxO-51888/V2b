<?php
// Standalone regression suite. Only SQLite :memory:, array cache and fake dispatchers.
$vendor = $argv[1] ?? dirname(__DIR__, 2) . '/vendor/autoload.php';
$source = $argv[2] ?? dirname(__DIR__, 2);
require $vendor;
spl_autoload_register(function ($class) use ($source) {
    if (strpos($class, 'App\\') !== 0) return;
    $file = $source . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
}, true, true);

use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Services\AuthService;
use App\Services\PaymentService;
use App\Utils\CacheKey;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Bus;

$app = new Illuminate\Foundation\Application($source);
$app->instance('config', new Illuminate\Config\Repository([
    'app' => ['key' => 'security-suite-only-not-a-production-key'],
    'v2board' => [],
]));
$capsule = new Illuminate\Database\Capsule\Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->bootEloquent();
$db = $capsule->getConnection();
$app->instance('db', $capsule->getDatabaseManager());
$app->instance('cache', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
$app->instance('bus', new Illuminate\Bus\Dispatcher($app));
$app->alias('bus', Illuminate\Contracts\Bus\Dispatcher::class);
$app->bind(Illuminate\Contracts\Routing\ResponseFactory::class, function () {
    return new class extends Illuminate\Routing\ResponseFactory {
        public function __construct() {}
    };
});
$app->instance('translator', new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
Bus::fake();
set_error_handler(function ($severity, $message, $file, $line) {
    if ($severity & (E_DEPRECATED | E_USER_DEPRECATED)) return true;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$schema = $db->getSchemaBuilder();
$app->instance('db.schema', $schema);
require_once $source . '/database/migrations/2026_10_23_000001_create_payment_attempts_table.php';
(new CreatePaymentAttemptsTable())->up();
$schema->create('v2_user', function ($t) {
    $t->increments('id'); $t->string('email')->unique();
    foreach (['is_admin','is_staff','banned','balance','commission_balance','u','d','transfer_enable'] as $field) $t->integer($field)->default(0);
    foreach (['password','password_algo','password_salt','telegram_id','token','uuid'] as $field) $t->string($field)->nullable();
    foreach (['plan_id','group_id','invite_user_id','expired_at','created_at','updated_at'] as $field) $t->integer($field)->nullable();
});
$schema->create('v2_payment', function ($t) {
    $t->increments('id'); $t->string('payment'); $t->string('uuid')->unique(); $t->text('config');
    $t->integer('enable')->default(1); $t->string('notify_domain')->nullable();
    $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
});
$schema->create('v2_order', function ($t) {
    $t->increments('id'); $t->integer('user_id'); $t->integer('payment_id')->nullable();
    $t->integer('status')->default(0); $t->string('trade_no')->unique();
    $t->integer('total_amount')->default(1000); $t->integer('handling_amount')->nullable();
    $t->string('callback_no')->nullable(); $t->integer('paid_at')->nullable();
    $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
});
$schema->create('v2_ticket', function ($t) {
    $t->increments('id'); $t->integer('user_id'); $t->integer('status')->default(0);
    $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
});

$checks = [];
function expectTrue($condition, $message = 'Assertion failed') {
    if (!$condition) throw new RuntimeException($message);
}
function testCase($name, $run) {
    global $checks;
    try { $run(); $checks[$name] = ['passed' => true]; }
    catch (Throwable $e) { $checks[$name] = ['passed' => false, 'error' => get_class($e) . ': ' . $e->getMessage()]; }
}
function denied($run) {
    try { $run(); } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { return $e->getStatusCode() >= 400; }
    return false;
}
function fixtureUser($attrs = []) {
    return User::create(array_merge(['email' => bin2hex(random_bytes(8)) . '@example.invalid', 'token' => bin2hex(random_bytes(16))], $attrs));
}
function loginFixture($user) {
    $result = (new AuthService($user))->generateAuthData(Request::create('/fixture', 'GET'));
    return $result['auth_data'];
}
function signedPayment($status = 'TRADE_SUCCESS', $money = '10.00') {
    $params = ['pid' => 'fixture-merchant', 'out_trade_no' => bin2hex(random_bytes(10)),
        'trade_no' => bin2hex(random_bytes(10)), 'money' => $money, 'trade_status' => $status];
    ksort($params);
    $params['sign'] = md5(stripslashes(urldecode(http_build_query($params))) . 'fixture-payment-secret');
    $params['sign_type'] = 'MD5';
    return $params;
}
function paymentFixture($method = 'SGEpay') {
    return Payment::create(['payment' => $method, 'uuid' => bin2hex(random_bytes(16)),
        'config' => ['pid' => 'fixture-merchant', 'key' => 'fixture-payment-secret', 'url' => 'https://payments.example.invalid', 'type' => 'alipay']]);
}
function callbackFixture($payment, $params, $method = null, $orderAttrs = []) {
    $order = Order::create(array_merge(['user_id' => 900000, 'trade_no' => $params['out_trade_no'], 'payment_id' => $payment->id], $orderAttrs));
    $controller = new App\Http\Controllers\V1\Guest\PaymentController();
    $blocked = denied(fn() => $controller->notify($method ?? $payment->payment, $payment->uuid, Request::create('/fixture', 'POST', $params)));
    return [$blocked, (int)$order->fresh()->status];
}

testCase('normal_user_and_admin_authenticate', function () {
    foreach ([[], ['is_admin' => 1], ['is_staff' => 1]] as $role) {
        $user = fixtureUser($role); $jwt = loginFixture($user);
        expectTrue(AuthService::decryptAuthData($jwt)['id'] === $user->id);
    }
});
testCase('forged_array_identity_rejected', function () { expectTrue(AuthService::decryptAuthData(['id'=>1,'is_admin'=>1]) === false); });
testCase('unsigned_or_wrong_key_rejected', function () {
    $user = fixtureUser();
    expectTrue(AuthService::decryptAuthData(JWT::encode(['id'=>$user->id,'session'=>'fixture'], str_repeat('wrong-key', 5), 'HS256')) === false);
});
testCase('old_identity_cache_cannot_supply_privileges', function () {
    $jwt='invalid.claim.signature'; Cache::put($jwt,['id'=>1,'is_admin'=>1,'is_staff'=>1],3600);
    expectTrue(AuthService::decryptAuthData($jwt)===false);
});
testCase('malformed_claims_fail_closed', function () {
    foreach ([['id'=>[1],'session'=>'s'],['id'=>1,'session'=>[]],['id'=>-1,'session'=>'s'],['id'=>1,'session'=>'']] as $claims) {
        expectTrue(AuthService::decryptAuthData(JWT::encode($claims,config('app.key'),'HS256'))===false);
    }
});
testCase('single_session_revocation_is_immediate', function () {
    $user = fixtureUser(); $jwt = loginFixture($user); $other = loginFixture($user);
    AuthService::decryptAuthData($jwt);
    $service = new AuthService($user);
    $claims = (array)JWT::decode($jwt, new Firebase\JWT\Key(config('app.key'), 'HS256'));
    $service->removeSession($claims['session']);
    expectTrue(AuthService::decryptAuthData($jwt) === false, 'Removed session still authenticates');
    expectTrue(AuthService::decryptAuthData($other)['id'] === $user->id, 'Other session was incorrectly removed');
});
testCase('all_sessions_revocation_is_immediate', function () {
    $user = fixtureUser(); $jwt = loginFixture($user); AuthService::decryptAuthData($jwt);
    (new AuthService($user))->removeAllSession(); expectTrue(AuthService::decryptAuthData($jwt) === false);
});
foreach (['is_admin' => App\Http\Middleware\Admin::class, 'is_staff' => App\Http\Middleware\Staff::class] as $role => $middleware) {
    testCase($role . '_downgrade_blocks_old_token', function () use ($role, $middleware) {
        $user = fixtureUser([$role=>1]); $jwt = loginFixture($user); AuthService::decryptAuthData($jwt);
        $user->update([$role=>0]);
        $req = Request::create('/fixture', 'POST', ['auth_data'=>$jwt]);
        expectTrue(denied(fn() => (new $middleware())->handle($req, fn() => true)), 'Old role remains authorized');
    });
}
testCase('banned_account_cannot_write_using_old_session', function () {
    $user = fixtureUser(); $jwt = loginFixture($user); AuthService::decryptAuthData($jwt);
    $user->update(['banned'=>1]); expectTrue(AuthService::decryptAuthData($jwt) === false);
});
testCase('caller_cannot_override_authenticated_user', function () {
    $user = fixtureUser(); $jwt = loginFixture($user);
    $req = Request::create('/fixture', 'POST', ['auth_data'=>$jwt, 'user'=>['id'=>900001, 'is_admin'=>1]]);
    (new App\Http\Middleware\User())->handle($req, function ($r) use ($user) {
        expectTrue($r->user['id'] === $user->id && !$r->user['is_admin']);
    });
});
testCase('ordinary_user_cannot_enter_admin_or_staff_routes', function () {
    $user=fixtureUser();$jwt=loginFixture($user);
    foreach ([App\Http\Middleware\Admin::class,App\Http\Middleware\Staff::class] as $middleware) {
        $req=Request::create('/fixture','POST',['auth_data'=>$jwt,'user'=>['id'=>1,'is_admin'=>1,'is_staff'=>1]]);
        expectTrue(denied(fn()=>(new $middleware())->handle($req,fn()=>true)));
    }
});
testCase('invalid_session_id_is_rejected_without_other_revocations', function () {
    $user=fixtureUser();$jwt=loginFixture($user);
    expectTrue((new AuthService($user))->removeSession(['other'])===false);
    expectTrue(AuthService::decryptAuthData($jwt)['id']===$user->id);
});
testCase('authenticated_lookup_is_one_primary_key_query', function () use ($db) {
    $user=fixtureUser();$jwt=loginFixture($user);
    $db->enableQueryLog();$db->flushQueryLog();
    AuthService::decryptAuthData($jwt);
    $queries=$db->getQueryLog();$db->disableQueryLog();
    expectTrue(count($queries)===1 && strpos($queries[0]['query'],'"v2_user"."id" = ?')!==false);
});
testCase('retired_legacy_telegram_controller_cannot_load', function () {
    expectTrue(!class_exists(App\Http\Controllers\V1\Guest\TelegramController::class));
    expectTrue(!method_exists(App\Http\Controllers\V1\Admin\ConfigController::class, 'setTelegramWebhook'));
});
testCase('legacy_routes_removed_native_binding_remains_authenticated', function () use ($app) {
    $router = new Illuminate\Routing\Router(new Illuminate\Events\Dispatcher($app), $app);
    (new App\Http\Routes\V1\GuestRoute())->map($router);
    (new App\Http\Routes\V1\AdminRoute())->map($router);
    (new App\Http\Routes\V1\TelegramBindingRoute())->map($router);
    $native = [];
    foreach ($router->getRoutes() as $route) {
        expectTrue(!str_contains($route->uri(), 'guest/telegram/webhook'));
        expectTrue(!str_contains($route->uri(), 'config/setTelegramWebhook'));
        if (str_starts_with($route->uri(), 'bot/telegram/')) {
            $native[] = substr($route->uri(), strlen('bot/telegram/'));
            expectTrue(in_array(App\Http\Middleware\TelegramBotAuth::class, $route->gatherMiddleware(), true));
        }
    }
    $expected = ['email/request', 'email/confirm', 'email/unbind/prepare', 'email/unbind/confirm', 'verify',
        'account/subscription', 'account/plan', 'account/publication',
        'account/subscription/reset/prepare', 'account/subscription/reset/confirm'];
    sort($native);
    sort($expected);
    expectTrue($native === $expected);
});
testCase('normal_sgepay_callback_is_paid', function () { expectTrue(callbackFixture(paymentFixture(), signedPayment()) === [false,1]); });
testCase('normal_epay_callback_is_paid', function () { expectTrue(callbackFixture(paymentFixture('EPay'), signedPayment()) === [false,1]); });
testCase('callback_cannot_select_another_adapter', function () { expectTrue(callbackFixture(paymentFixture('EPay'), signedPayment(), 'SGEpay') === [true,0]); });
foreach (['WAIT_BUYER_PAY','TRADE_CLOSED',''] as $status) {
    testCase('sgepay_non_success_rejected_' . ($status ?: 'empty'), function () use ($status) {
        expectTrue(callbackFixture(paymentFixture(), signedPayment($status)) === [true,0]);
    });
}
testCase('incorrect_signature_does_not_pay', function () {
    $params=signedPayment(); $params['sign']=str_repeat('0',32);
    expectTrue(callbackFixture(paymentFixture(),$params) === [true,0]);
});
testCase('missing_payment_is_rejected', function () { expectTrue(denied(fn()=>new PaymentService('EPay',null,'nonexistent-fixture'))); });
testCase('disabled_payment_is_rejected', function () {
    $payment=paymentFixture();$payment->update(['enable'=>0]);
    expectTrue(callbackFixture($payment,signedPayment())===[true,0]);
});
testCase('payment_configuration_form_still_works', function () { expectTrue(isset((new PaymentService('SGEpay'))->form()['key'])); });
testCase('callback_retry_is_idempotent', function () {
    $payment=paymentFixture();$params=signedPayment();
    expectTrue(callbackFixture($payment,$params)===[false,1]);
    (new App\Http\Controllers\V1\Guest\PaymentController())->notify('SGEpay',$payment->uuid,Request::create('/fixture','POST',$params));
    expectTrue(Order::where('trade_no',$params['out_trade_no'])->count()===1);
    expectTrue((int)Order::where('trade_no',$params['out_trade_no'])->first()->status===1);
});
testCase('callback_for_another_payment_does_not_pay', function () {
    $payment=paymentFixture(); $other=paymentFixture();
    expectTrue(callbackFixture($payment,signedPayment(),null,['payment_id'=>$other->id]) === [true,0]);
});
testCase('underpaid_callback_does_not_pay', function () { expectTrue(callbackFixture(paymentFixture(),signedPayment('TRADE_SUCCESS','0.01')) === [true,0]); });
testCase('payment_including_handling_fee_is_paid', function () { expectTrue(callbackFixture(paymentFixture(),signedPayment('TRADE_SUCCESS','10.50'),null,['handling_amount'=>50]) === [false,1]); });
testCase('cancelled_order_is_not_reopened', function () { expectTrue(callbackFixture(paymentFixture(),signedPayment(),null,['status'=>2])[1] === 2); });
testCase('ordinary_user_cannot_cancel_other_users_order', function () {
    $owner=fixtureUser(); $other=fixtureUser();
    $order=Order::create(['user_id'=>$owner->id,'trade_no'=>bin2hex(random_bytes(12))]);
    $request=Request::create('/fixture','POST',['user'=>['id'=>$other->id], 'trade_no'=>$order->trade_no]);
    expectTrue(denied(fn() => (new App\Http\Controllers\V1\User\OrderController())->cancel($request)));
    expectTrue((int)$order->fresh()->status===0);
});
testCase('ordinary_user_cannot_close_other_users_ticket', function () {
    $owner=fixtureUser(); $other=fixtureUser();
    $ticket=App\Models\Ticket::create(['user_id'=>$owner->id]);
    $request=Request::create('/fixture','POST',['user'=>['id'=>$other->id], 'id'=>$ticket->id]);
    expectTrue(denied(fn() => (new App\Http\Controllers\V1\User\TicketController())->close($request)));
    expectTrue((int)$ticket->fresh()->status===0);
});
testCase('staff_cannot_update_admin_or_staff', function () {
    foreach (['is_admin','is_staff'] as $role) {
        $target=fixtureUser([$role=>1]);
        $request = new class extends App\Http\Requests\Staff\UserUpdate {
            public function validated($key = null, $default = null) { return data_get(['email'=>'changed@example.invalid','banned'=>0], $key, $default); }
        };
        $request->initialize([], ['id'=>$target->id]);
        $request->setMethod('POST');
        expectTrue(denied(fn() => (new App\Http\Controllers\V1\Staff\UserController())->update($request)));
        expectTrue($target->fresh()->email === $target->email);
    }
});
foreach (['Staff','Admin'] as $actor) {
    testCase(strtolower($actor).'_password_change_revokes_sessions_and_keeps_quota', function () use ($actor) {
        $target=fixtureUser(['password'=>password_hash('old-fixture-password',PASSWORD_DEFAULT),'password_salt'=>'old-salt','transfer_enable'=>123456,'u'=>123,'d'=>456,'balance'=>789]);
        $jwt=loginFixture($target);AuthService::decryptAuthData($jwt);
        if ($actor==='Staff') {
            $request=new class extends App\Http\Requests\Staff\UserUpdate { public function validated($key = null, $default = null) { return data_get($this->input('fixture_params'), $key, $default); } };
        } else {
            $request=new class extends App\Http\Requests\Admin\UserUpdate { public function validated($key = null, $default = null) { return data_get($this->input('fixture_params'), $key, $default); } };
        }
        $request->initialize([],['id'=>$target->id,'fixture_params'=>['email'=>$target->email,'banned'=>0,'password'=>'new-fixture-password']]);
        $request->setMethod('POST');
        $controller='App\\Http\\Controllers\\V1\\'.$actor.'\\UserController';
        (new $controller())->update($request);
        $fresh=$target->fresh();
        expectTrue(password_verify('new-fixture-password',$fresh->password) && $fresh->password_salt===null);
        expectTrue(AuthService::decryptAuthData($jwt)===false);
        foreach (['transfer_enable','u','d','balance'] as $field) expectTrue($fresh->$field===$target->$field);
    });
}
testCase('password_hashes_are_not_serialized', function () {
    $user=fixtureUser(['password'=>'fixture-password-hash','password_salt'=>'fixture-salt']);
    $data=$user->toArray();expectTrue(!array_key_exists('password',$data)&&!array_key_exists('password_salt',$data));
    expectTrue($user->password==='fixture-password-hash');
});

if (defined('SECURITY_SUITE_DEFER_SUMMARY')) return;
$failed=array_filter($checks, fn($r)=>!$r['passed']);
echo json_encode(['storage'=>'sqlite-memory/cache-array/bus-fake/telegram-sink', 'total'=>count($checks), 'passed'=>count($checks)-count($failed), 'failed'=>$failed, 'checks'=>$checks],JSON_UNESCAPED_SLASHES) . "\n";
exit($failed ? 1 : 0);

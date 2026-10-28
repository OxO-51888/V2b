<?php
// All state is disposable: SQLite memory, array cache and fake message dispatchers.
define('SECURITY_SUITE_DEFER_SUMMARY', true);
require __DIR__ . '/authorization.php';

use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Controllers\V1\Passport\CommController;
use App\Http\Requests\Passport\AuthRegister;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Services\AuthService;
use App\Services\EmailVerificationService;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Bus;

$schema->table('v2_user', function ($t) {
    $t->integer('last_login_at')->nullable();
    $t->integer('device_limit')->nullable();
    $t->integer('speed_limit')->nullable();
});
$schema->create('v2_plan', function ($t) {
    $t->increments('id');
    foreach (['transfer_enable','device_limit','speed_limit','group_id'] as $field) $t->integer($field)->default(0);
    $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
});
$schema->create('v2_invite_code', function ($t) {
    $t->increments('id'); $t->string('code'); $t->integer('user_id'); $t->integer('status')->default(0);
    $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
});
$app->instance(Illuminate\Cache\RateLimiter::class, new Illuminate\Cache\RateLimiter($app['cache']));
$app['config']->set('v2board.email_verify', 1);
$app['config']->set('v2board.password_limit_enable', 1);
$app['config']->set('v2board.password_limit_count', 5);
$app['config']->set('v2board.password_limit_expire', 1);
$app['config']->set('v2board.app_url', 'https://panel.example.invalid');

function hardeningRequest($class, array $params) {
    $request = new $class();
    $request->initialize([], $params, [], [], [], ['REQUEST_METHOD'=>'POST','REMOTE_ADDR'=>'192.0.2.77']);
    if ($request instanceof CommSendEmailVerify) {
        $factory = new Illuminate\Validation\Factory(app('translator'), app());
        $request->setValidator($factory->make($params, $request->rules()));
    }
    return $request;
}
function rejectionMessage(callable $callback) {
    try { $callback(); return null; }
    catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { return $e->getMessage(); }
}
function seedOtp(string $email, string $code = '246813') {
    Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', EmailVerificationService::normalize($email)), $code, 300);
}

testCase('existing_account_registration_never_checks_or_reveals_otp', function () {
    $user = fixtureUser(['is_admin'=>1,'password'=>'unchanged']); seedOtp($user->email);
    $before = $user->fresh()->getAttributes();
    foreach (['000001','246813','not-a-code'] as $code) {
        $request=hardeningRequest(AuthRegister::class,['email'=>$user->email,'password'=>'fixture-password','email_code'=>$code]);
        expectTrue(rejectionMessage(fn() => (new AuthController())->register($request)) === 'Email already exists');
    }
    expectTrue($user->fresh()->getAttributes() === $before);
    expectTrue(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE',$user->email)) === '246813');
});
testCase('registration_wrong_code_limit_is_shared_and_enforced', function () {
    $email='new-limited@example.invalid'; seedOtp($email);
    for ($i=1;$i<=3;$i++) {
        $request=hardeningRequest(AuthRegister::class,['email'=>$email,'password'=>'fixture-password','email_code'=>'000001']);
        expectTrue(denied(fn() => (new AuthController())->register($request)));
        expectTrue((int)Cache::get(CacheKey::get('FORGET_REQUEST_LIMIT',$email)) === $i);
    }
    $request=hardeningRequest(AuthRegister::class,['email'=>$email,'password'=>'fixture-password','email_code'=>'246813']);
    expectTrue(rejectionMessage(fn() => (new AuthController())->register($request)) === 'Reset failed, Please try again later');
    expectTrue(!User::where('email',$email)->exists());
});
testCase('registration_keeps_trial_plan_invitation_and_auth_response', function () {
    $plan=App\Models\Plan::create(['transfer_enable'=>20,'device_limit'=>3,'speed_limit'=>100,'group_id'=>9]);
    $inviter=fixtureUser();
    $invite=new App\Models\InviteCode();$invite->forceFill(['code'=>'fixture-invite','user_id'=>$inviter->id,'status'=>0]);$invite->save();
    config(['v2board.try_out_plan_id'=>$plan->id,'v2board.try_out_hour'=>2,'v2board.invite_force'=>1]);
    $email='new-trial@example.invalid'; seedOtp($email);
    $request=hardeningRequest(AuthRegister::class,['email'=>$email,'password'=>'fixture-password','email_code'=>'246813','invite_code'=>'fixture-invite','is_admin'=>1,'is_staff'=>1]);
    try {
        $response=(new AuthController())->register($request);
        $data=json_decode($response->getContent(),true)['data']; $user=User::where('email',$email)->first();
        expectTrue((int)$user->is_admin===0 && (int)$user->is_staff===0);
        expectTrue((int)$user->transfer_enable === 20*1073741824 && (int)$user->device_limit===3 && (int)$user->speed_limit===100);
        expectTrue((int)$user->plan_id===$plan->id && (int)$user->invite_user_id===$inviter->id);
        expectTrue((int)$invite->fresh()->status===1 && abs($user->expired_at-time()-7200)<5);
        expectTrue(AuthService::decryptAuthData($data['auth_data'])['id']===$user->id && $data['token']===$user->token);
        expectTrue(!Cache::has(CacheKey::get('EMAIL_VERIFY_CODE',$email)));
    } finally { config(['v2board.try_out_plan_id'=>0,'v2board.invite_force'=>0]); }
});
testCase('correcting_required_invite_preserves_valid_otp', function () {
    $email='invite-correction@example.invalid';seedOtp($email);
    $inviter=fixtureUser();$invite=new App\Models\InviteCode();
    $invite->forceFill(['code'=>'corrected-fixture-invite','user_id'=>$inviter->id,'status'=>0]);$invite->save();
    config(['v2board.invite_force'=>1]);
    try {
        $params=['email'=>$email,'password'=>'fixture-password','email_code'=>'246813','invite_code'=>'invalid-fixture-invite'];
        expectTrue(rejectionMessage(fn()=>(new AuthController())->register(hardeningRequest(AuthRegister::class,$params)))==='Invalid invitation code');
        expectTrue(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE',$email))==='246813' && !User::where('email',$email)->exists());
        $params['invite_code']='corrected-fixture-invite';
        $response=(new AuthController())->register(hardeningRequest(AuthRegister::class,$params));
        expectTrue($response->getStatusCode()===200 && User::where('email',$email)->exists());
        expectTrue(!Cache::has(CacheKey::get('EMAIL_VERIFY_CODE',$email)));
    } finally {config(['v2board.invite_force'=>0]);}
});
testCase('registration_without_email_verification_remains_available', function () {
    config(['v2board.email_verify'=>0]);
    try {
        $response=(new AuthController())->register(hardeningRequest(AuthRegister::class,['email'=>'no-otp@example.invalid','password'=>'fixture-password']));
        expectTrue($response->getStatusCode()===200);
    } finally { config(['v2board.email_verify'=>1]); }
});
testCase('forgot_password_replaces_only_password_and_revokes_sessions', function () {
    $user=fixtureUser(['is_admin'=>1,'password'=>password_hash('old-fixture-password',PASSWORD_DEFAULT),'password_salt'=>'old','transfer_enable'=>99999,'u'=>111,'d'=>222,'balance'=>333]);
    $jwt=loginFixture($user); $quick=AuthService::issueQuickLoginToken($jwt); seedOtp($user->email);
    $before=$user->fresh()->getAttributes();
    $request=hardeningRequest(AuthForget::class,['email'=>strtoupper($user->email),'password'=>'new-fixture-password','email_code'=>'246813']);
    expectTrue((new AuthController())->forget($request)->getStatusCode()===200);
    $fresh=$user->fresh(); expectTrue(password_verify('new-fixture-password',$fresh->password));
    foreach (['is_admin','is_staff','email','token','uuid','transfer_enable','u','d','balance'] as $field) expectTrue($fresh->$field===$before[$field]);
    expectTrue($fresh->password_salt===null && $fresh->password_algo===null);
    expectTrue(AuthService::decryptAuthData($jwt)===false);
    expectTrue(denied(fn() => AuthService::consumeQuickLoginToken($quick,Request::create('/fixture'))));
    expectTrue(denied(fn() => (new AuthController())->forget($request)));
});
testCase('failed_otp_does_not_change_password_or_sessions', function () {
    $user=fixtureUser(['password'=>password_hash('fixture-password',PASSWORD_DEFAULT)]);$jwt=loginFixture($user);seedOtp($user->email);
    for ($i=0;$i<3;$i++) expectTrue(denied(fn() => (new AuthController())->forget(hardeningRequest(AuthForget::class,['email'=>$user->email,'password'=>'new-fixture-password','email_code'=>'000001']))));
    expectTrue(denied(fn() => (new AuthController())->forget(hardeningRequest(AuthForget::class,['email'=>$user->email,'password'=>'new-fixture-password','email_code'=>'246813']))));
    expectTrue($user->fresh()->password===$user->password && AuthService::decryptAuthData($jwt)['id']===$user->id);
});
testCase('verification_lock_prevents_simultaneous_code_use', function () {
    $email='lock-fixture@example.invalid';seedOtp($email);$called=0;
    $key='auth:email:lock:'.hash('sha256',base_path().':'.$email);
    $lock=Cache::lock($key,30);expectTrue($lock->get());
    try { expectTrue(denied(fn() => EmailVerificationService::verifyAndRun($email,'246813',function() use (&$called) {$called++;}))); }
    finally { $lock->release(); }
    expectTrue($called===0);
    EmailVerificationService::verifyAndRun($email,'246813',function() use (&$called) {$called++;});
    expectTrue($called===1 && !Cache::has(CacheKey::get('EMAIL_VERIFY_CODE',$email)));
});
testCase('invalid_code_format_and_expiry_fail_closed', function () {
    foreach ([null,[],true,246813,'246813\n','000000'] as $code) {
        $email=bin2hex(random_bytes(6)).'@example.invalid';seedOtp($email);
        expectTrue(denied(fn() => EmailVerificationService::verifyAndRun($email,$code,fn() => true)));
    }
    expectTrue(denied(fn() => EmailVerificationService::verifyAndRun('expired@example.invalid','246813',fn() => true)));
});
testCase('otp_is_consumed_before_account_write_and_not_reusable_after_failure', function () {
    $email='consume-before-write@example.invalid';seedOtp($email);$called=0;
    try {
        EmailVerificationService::verifyAndRun($email,'246813',function () use ($email,&$called) {
            expectTrue(!Cache::has(CacheKey::get('EMAIL_VERIFY_CODE',$email)));
            $called++; throw new RuntimeException('Simulated account write failure');
        });
    } catch (RuntimeException $e) { expectTrue($e->getMessage()==='Simulated account write failure'); }
    expectTrue($called===1 && denied(fn()=>EmailVerificationService::verifyAndRun($email,'246813',fn()=>true)));
});
testCase('completed_callback_cannot_erase_a_newer_code', function () {
    $email='newer-code@example.invalid';seedOtp($email);
    EmailVerificationService::verifyAndRun($email,'246813',fn()=>seedOtp($email,'135792'));
    expectTrue(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE',$email))==='135792');
});
testCase('completed_callback_cannot_erase_newer_attempt_counter', function () {
    $email='newer-attempt@example.invalid';seedOtp($email);
    $key=CacheKey::get('FORGET_REQUEST_LIMIT',$email);
    Cache::put($key,2,300);
    EmailVerificationService::verifyAndRun($email,'246813',function () use ($key) {
        expectTrue(!Cache::has($key));
        Cache::put($key,1,300);
    });
    expectTrue(Cache::get($key)===1);
});
testCase('otp_consumption_storage_failure_prevents_account_write', function () {
    $original=Cache::getFacadeRoot();
    $store=new class extends Illuminate\Cache\ArrayStore {
        public function forget($key) {
            if (strpos($key,'EMAIL_VERIFY_CODE_')===0) return false;
            return parent::forget($key);
        }
    };
    Cache::swap(new Illuminate\Cache\Repository($store));
    try {
        $email='cache-failure@example.invalid';seedOtp($email);$called=0;
        expectTrue(denied(fn()=>EmailVerificationService::verifyAndRun($email,'246813',function() use (&$called) {$called++;})));
        expectTrue($called===0);
    } finally { Cache::swap($original); }
});
testCase('distinct_unicode_mailbox_is_not_equivalent', function () {
    expectTrue(EmailVerificationService::matches('Member@Example.invalid','member@example.invalid'));
    expectTrue(!EmailVerificationService::matches('member@example.invalid',"member@ex\xc3\xa1mple.invalid"));
    expectTrue(!EmailVerificationService::matches('member@example.invalid',"m\xc3\xa9mber@example.invalid"));
});
testCase('mail_uses_registered_address_and_retains_resend_limit', function () use ($app) {
    Bus::fake(); $app[Illuminate\Cache\RateLimiter::class]->clear('192.0.2.77');
    $user=fixtureUser();
    $request=hardeningRequest(CommSendEmailVerify::class,['email'=>strtoupper($user->email),'isforget'=>1]);
    $response=$app->make(CommController::class)->sendEmailVerify($request);expectTrue($response->getStatusCode()===200);
    $jobs=Bus::dispatched(App\Jobs\SendEmailJob::class);expectTrue($jobs->count()===1);
    $reflection=new ReflectionProperty(App\Jobs\SendEmailJob::class,'params');
    $params=$reflection->getValue($jobs->first());expectTrue($params['email']===$user->email);
    expectTrue(preg_match('/\A[0-9]{6}\z/',$params['template_value']['code'])===1);
    expectTrue(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE',$user->email))===$params['template_value']['code']);
    expectTrue(denied(fn() => $app->make(CommController::class)->sendEmailVerify($request)));
    expectTrue(Bus::dispatched(App\Jobs\SendEmailJob::class)->count()===1);
});
testCase('login_email_case_cannot_reset_failure_counter', function () {
    $user=fixtureUser(['password'=>password_hash('fixture-password',PASSWORD_DEFAULT)]);
    Cache::put(CacheKey::get('PASSWORD_ERROR_LIMIT',$user->email),5,60);
    $request=hardeningRequest(AuthLogin::class,['email'=>strtoupper($user->email),'password'=>'fixture-password']);
    expectTrue(rejectionMessage(fn() => (new AuthController())->login($request)) === 'There are too many password errors, please try again after 1 minutes.');
    Cache::forget(CacheKey::get('PASSWORD_ERROR_LIMIT',$user->email));
    expectTrue((new AuthController())->login($request)->getStatusCode()===200);
});
testCase('locked_verification_does_not_queue_an_unusable_code', function () use ($app) {
    Bus::fake();$app[Illuminate\Cache\RateLimiter::class]->clear('192.0.2.77');
    $user=fixtureUser();Cache::put(CacheKey::get('FORGET_REQUEST_LIMIT',$user->email),3,300);
    expectTrue(denied(fn()=>$app->make(CommController::class)->sendEmailVerify(hardeningRequest(CommSendEmailVerify::class,['email'=>$user->email,'isforget'=>1]))));
    expectTrue(Bus::dispatched(App\Jobs\SendEmailJob::class)->count()===0);
    expectTrue(!Cache::has(CacheKey::get('EMAIL_VERIFY_CODE',$user->email)));
});
testCase('quick_login_controllers_retain_existing_url_and_response_shape', function () {
    $user=fixtureUser(['password'=>password_hash('fixture-password',PASSWORD_DEFAULT)]);$jwt=loginFixture($user);
    $controller=new AuthController();
    $response=$controller->getQuickLoginUrl(Request::create('/fixture','POST',['auth_data'=>$jwt,'redirect'=>'dashboard']));
    $url=json_decode($response->getContent(),true)['data'];
    expectTrue(strpos($url,'https://panel.example.invalid/#/login?verify=')===0);
    parse_str(parse_url($url,PHP_URL_FRAGMENT) ? explode('?',parse_url($url,PHP_URL_FRAGMENT),2)[1] : '',$query);
    $result=$controller->token2Login(Request::create('/fixture','GET',['verify'=>$query['verify']]));
    $data=json_decode($result->getContent(),true)['data'];
    expectTrue(AuthService::decryptAuthData($data['auth_data'])['id']===$user->id && $data['token']===$user->token);
    expectTrue(denied(fn() => $controller->token2Login(Request::create('/fixture','GET',['verify'=>$query['verify']]))));
});

$failed=array_filter($checks, fn($r)=>!$r['passed']);
echo json_encode(['storage'=>'sqlite-memory/cache-array/bus-fake/telegram-sink','total'=>count($checks),'passed'=>count($checks)-count($failed),'failed'=>$failed,'checks'=>$checks],JSON_UNESCAPED_SLASHES)."\n";
exit($failed ? 1 : 0);

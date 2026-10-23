<?php
// Reuse only disposable SQLite memory, array cache and fake dispatchers.
define('SECURITY_SUITE_DEFER_SUMMARY', true);
require __DIR__ . '/authorization.php';

use App\Console\Commands\ResetPassword;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\CacheKey;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

function sessionClaim($jwt) {
    return JWT::decode($jwt, new Key(config('app.key'), 'HS256'))->session;
}
function sessionRequest() { return Request::create('/fixture', 'GET', [], [], [], ['HTTP_USER_AGENT'=>'session-fixture']); }

class SessionHardeningArrayStore extends Illuminate\Cache\ArrayStore
{
    public $writes = 0;
    public $failWrites = false;
    public $failQuickDelete = false;
    public function put($key, $value, $seconds) {
        if (strpos($key, 'USER_SESSIONS_') === 0) {
            expectTrue(isset($this->locks[$key . ':lock']), 'Session mutation without user lock');
            $this->writes++;
            if ($this->failWrites) return false;
        }
        return parent::put($key, $value, $seconds);
    }
    public function forget($key) {
        if ($this->failQuickDelete && strpos($key, 'TEMP_TOKEN_') === 0) return false;
        return parent::forget($key);
    }
}
function withSessionStore(callable $run) {
    $original = Cache::getFacadeRoot();
    $store = new SessionHardeningArrayStore();
    Cache::swap(new Illuminate\Cache\Repository($store));
    try { $run($store); } finally { Cache::swap($original); }
}

testCase('session_metadata_is_allowlisted_without_rewriting_legacy_sessions', function () {
    $user=fixtureUser(); $jwt=loginFixture($user); $other=loginFixture($user);
    $key=CacheKey::get('USER_SESSIONS',$user->id); $before=Cache::get($key);
    $sessions=(new AuthService($user))->getSessions();
    foreach ($sessions as $meta) expectTrue(array_keys($meta)===['ip','login_at','ua']);
    expectTrue(Cache::get($key)===$before);
    expectTrue(AuthService::decryptAuthData($jwt)['id']===$user->id && AuthService::decryptAuthData($other)['id']===$user->id);
});
testCase('issuance_refreshes_missing_defaults_and_current_token', function () {
    $user=User::create(['email'=>bin2hex(random_bytes(8)).'@example.invalid','token'=>'initial-fixture-token']);
    expectTrue($user->is_admin===null);
    User::whereKey($user->id)->update(['token'=>'fresh-fixture-token','is_admin'=>1]);
    $auth=(new AuthService($user))->generateAuthData(sessionRequest());
    expectTrue(array_keys($auth)===['token','is_admin','auth_data']);
    expectTrue($auth['token']==='fresh-fixture-token' && (int)$auth['is_admin']===1);
    $claims=(array)JWT::decode($auth['auth_data'],new Key(config('app.key'),'HS256'));
    expectTrue(array_keys($claims)===['id','session']);
});
testCase('stale_password_login_cannot_install_session_after_reset', function () {
    $user=fixtureUser(['password'=>'original-fixture-hash']); $stale=new AuthService($user);
    $jwt=loginFixture($user); (new AuthService($user))->resetPassword('new-fixture-password');
    expectTrue(denied(fn()=>$stale->generateAuthData(sessionRequest())));
    expectTrue($stale->getSessions()===[] && AuthService::decryptAuthData($jwt)===false);
    expectTrue(AuthService::decryptAuthData(loginFixture($user->fresh()))['id']===$user->id);
});
testCase('stale_email_login_cannot_install_session_after_email_change', function () {
    $user=fixtureUser(); $stale=new AuthService($user); $jwt=loginFixture($user);
    (new AuthService($user))->updateUser(['email'=>'changed-login-'.$user->id.'@example.invalid']);
    expectTrue(denied(fn()=>$stale->generateAuthData(sessionRequest())));
    expectTrue($stale->getSessions()===[] && AuthService::decryptAuthData($jwt)===false);
    expectTrue(AuthService::decryptAuthData(loginFixture($user->fresh()))['id']===$user->id);
});
testCase('password_reset_rechecks_email_before_any_revocation', function () {
    $user=fixtureUser(['password'=>'original-fixture-hash']); $service=new AuthService($user); $jwt=loginFixture($user);
    User::whereKey($user->id)->update(['email'=>'changed-'. $user->id .'@example.invalid']);
    expectTrue(denied(fn()=>$service->resetPassword('new-fixture-password')));
    expectTrue($user->fresh()->password==='original-fixture-hash');
    expectTrue(AuthService::decryptAuthData($jwt)['id']===$user->id);
});
testCase('verified_reset_rechecks_each_original_credential_field', function () {
    foreach (['password','password_algo','password_salt'] as $field) {
        $user=fixtureUser(['password'=>'old-hash','password_algo'=>'old-algo','password_salt'=>'old-salt']);
        $service=new AuthService($user); $jwt=loginFixture($user);
        User::whereKey($user->id)->update([$field=>'changed-value']); $before=$user->fresh()->getAttributes();
        expectTrue(denied(fn()=>$service->resetPassword('new-fixture-password',true)));
        expectTrue($user->fresh()->getAttributes()===$before);
        expectTrue(AuthService::decryptAuthData($jwt)['id']===$user->id);
    }
});
testCase('default_reset_allows_password_replacement_but_preserves_other_fields', function () {
    $user=fixtureUser(['password'=>'old-hash','password_salt'=>'old-salt','password_algo'=>'old-algo','balance'=>12,'u'=>34,'d'=>56]);
    $service=new AuthService($user); $jwt=loginFixture($user);
    User::whereKey($user->id)->update(['password'=>'concurrently-updated-hash']); $before=$user->fresh();
    expectTrue($service->resetPassword('new-fixture-password')===true); $fresh=$user->fresh();
    expectTrue(password_verify('new-fixture-password',$fresh->password) && $fresh->password_salt===null && $fresh->password_algo===null);
    foreach (['email','token','uuid','is_admin','is_staff','balance','u','d','transfer_enable'] as $field) expectTrue($fresh->$field===$before->$field);
    expectTrue(AuthService::decryptAuthData($jwt)===false);
});
testCase('session_mutations_are_locked_and_unknown_removal_does_not_write', function () {
    withSessionStore(function ($store) {
        $user=fixtureUser(); $service=new AuthService($user); $jwt=loginFixture($user); $other=loginFixture($user);
        $writes=$store->writes; expectTrue($service->removeSession('unknown')===true && $store->writes===$writes);
        expectTrue($service->removeSession(sessionClaim($jwt))===true);
        $new=loginFixture($user);
        expectTrue(AuthService::decryptAuthData($jwt)===false && AuthService::decryptAuthData($other)['id']===$user->id);
        expectTrue($service->removeAllSession()===true && $service->removeAllSession()===true);
        loginFixture($user);
        expectTrue(AuthService::decryptAuthData($other)===false && AuthService::decryptAuthData($new)===false);
        $service->resetPassword('new-fixture-password');
    });
});
testCase('held_user_lock_blocks_all_mutations_with_bounded_wait', function () {
    $user=fixtureUser(['password'=>'old-hash']); $service=new AuthService($user); $jwt=loginFixture($user);
    $code=AuthService::issueQuickLoginToken($jwt); $key=CacheKey::get('USER_SESSIONS',$user->id); $before=Cache::get($key);
    $lock=Cache::lock($key.':lock',60); expectTrue($lock->get()); $start=microtime(true);
    try {
        foreach ([fn()=>$service->generateAuthData(sessionRequest()),fn()=>$service->removeSession(sessionClaim($jwt)),
            fn()=>$service->removeAllSession(),fn()=>$service->resetPassword('new-fixture-password'),
            fn()=>AuthService::issueQuickLoginToken($jwt),fn()=>AuthService::consumeQuickLoginToken($code,sessionRequest())] as $operation) {
            expectTrue(denied($operation));
        }
    } finally { $lock->release(); }
    expectTrue(microtime(true)-$start<25 && Cache::get($key)===$before && $user->fresh()->password==='old-hash');
});
testCase('failed_session_writes_never_report_success_or_change_password', function () {
    withSessionStore(function ($store) {
        $user=fixtureUser(['password'=>'old-hash']); $service=new AuthService($user); $jwt=loginFixture($user);
        $before=$service->getSessions(); $store->failWrites=true;
        foreach ([fn()=>$service->generateAuthData(sessionRequest()),fn()=>$service->removeSession(sessionClaim($jwt)),
            fn()=>$service->removeAllSession(),fn()=>$service->resetPassword('new-fixture-password')] as $operation) expectTrue(denied($operation));
        expectTrue($service->getSessions()===$before && $user->fresh()->password==='old-hash');
        $store->failWrites=false; expectTrue($service->removeAllSession()===true);
    });
});
testCase('quick_login_is_one_time_and_expires_after_sixty_seconds', function () {
    $user=fixtureUser(); $jwt=loginFixture($user); $code=AuthService::issueQuickLoginToken($jwt);
    $auth=AuthService::consumeQuickLoginToken($code,sessionRequest());
    expectTrue(AuthService::decryptAuthData($auth['auth_data'])['id']===$user->id);
    expectTrue(AuthService::decryptAuthData($jwt)['id']===$user->id);
    expectTrue(denied(fn()=>AuthService::consumeQuickLoginToken($code,sessionRequest())));
    $code=AuthService::issueQuickLoginToken($jwt);
    Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::now()->addSeconds(61));
    try { expectTrue(denied(fn()=>AuthService::consumeQuickLoginToken($code,sessionRequest()))); }
    finally { Illuminate\Support\Carbon::setTestNow(); }
});
testCase('quick_login_revalidates_revocation_ban_deletion_and_uid', function () {
    foreach (['single','all','reset','ban','delete','uid'] as $action) {
        $user=fixtureUser(); $jwt=loginFixture($user); $code=AuthService::issueQuickLoginToken($jwt); $service=new AuthService($user);
        if ($action==='single') $service->removeSession(sessionClaim($jwt));
        if ($action==='all') $service->removeAllSession();
        if ($action==='reset') $service->resetPassword('new-fixture-password');
        if ($action==='ban') $user->update(['banned'=>1]);
        if ($action==='delete') $user->delete();
        if ($action==='uid') {
            $key=CacheKey::get('TEMP_TOKEN',$code); $token=Cache::get($key); $token['uid']=fixtureUser()->id; Cache::put($key,$token,60);
        }
        expectTrue(denied(fn()=>AuthService::consumeQuickLoginToken($code,sessionRequest())), $action);
    }
});
testCase('quick_login_rejects_legacy_and_malformed_tokens', function () {
    $user=fixtureUser(); $code=App\Utils\Helper::guid(); Cache::put(CacheKey::get('TEMP_TOKEN',$code),$user->id,60);
    expectTrue(denied(fn()=>AuthService::consumeQuickLoginToken($code,sessionRequest())));
    foreach ([null,[],true,'bad',str_repeat('a',4097)] as $value) {
        expectTrue(denied(fn()=>AuthService::consumeQuickLoginToken($value,sessionRequest())));
        expectTrue(denied(fn()=>AuthService::issueQuickLoginToken($value)));
    }
});
testCase('quick_login_failed_deletion_cannot_mint_session', function () {
    withSessionStore(function ($store) {
        $user=fixtureUser(); $jwt=loginFixture($user); $code=AuthService::issueQuickLoginToken($jwt);
        $service=new AuthService($user); $before=$service->getSessions(); $store->failQuickDelete=true;
        expectTrue(denied(fn()=>AuthService::consumeQuickLoginToken($code,sessionRequest())));
        expectTrue($service->getSessions()===$before);
        $store->failQuickDelete=false; AuthService::consumeQuickLoginToken($code,sessionRequest());
    });
});
testCase('sensitive_user_updates_revoke_before_save_and_cache_failure_aborts', function () {
    withSessionStore(function ($store) {
        $user=fixtureUser(); $jwt=loginFixture($user); $service=new AuthService($user);
        $store->failWrites=true;
        expectTrue(denied(fn()=>$service->updateUser(['email'=>'blocked-update@example.invalid'])));
        expectTrue($user->fresh()->email===$user->email && AuthService::decryptAuthData($jwt)['id']===$user->id);
        $store->failWrites=false;
        expectTrue($service->updateUser(['email'=>'allowed-update@example.invalid'])===true);
        expectTrue($user->fresh()->email==='allowed-update@example.invalid' && AuthService::decryptAuthData($jwt)===false);
    });
});
testCase('ordinary_only_user_update_rechecks_concurrent_promotion', function () {
    foreach (['is_admin','is_staff'] as $role) {
        $user=fixtureUser(); $service=new AuthService($user); $jwt=loginFixture($user);
        User::whereKey($user->id)->update([$role=>1]); $before=$user->fresh()->getAttributes();
        expectTrue(denied(fn()=>$service->updateUser(['balance'=>999],true)));
        expectTrue($user->fresh()->getAttributes()===$before && AuthService::decryptAuthData($jwt)['id']===$user->id);
    }
});
testCase('nonsensitive_user_update_preserves_sessions', function () {
    withSessionStore(function ($store) {
        $user=fixtureUser(); $jwt=loginFixture($user); $writes=$store->writes;
        expectTrue((new AuthService($user))->updateUser(['balance'=>123],true)===true);
        expectTrue($store->writes===$writes && (int)$user->fresh()->balance===123);
        expectTrue(AuthService::decryptAuthData($jwt)['id']===$user->id);
    });
});
testCase('cli_password_reset_revokes_sessions_and_clears_salt', function () {
    $user=fixtureUser(['password'=>'old-hash','password_algo'=>'old-algo','password_salt'=>'old-salt']); $jwt=loginFixture($user);
    $command=new class extends ResetPassword {
        public $email;
        public $messages=[];
        public function argument($key=null) { return $this->email; }
        public function info($string,$verbosity=null) { $this->messages[]=$string; }
    };
    $command->email=$user->email; $command->handle(); $fresh=$user->fresh();
    expectTrue($fresh->password!=='old-hash' && $fresh->password_algo===null && $fresh->password_salt===null);
    expectTrue(AuthService::decryptAuthData($jwt)===false && count($command->messages)===2);
    expectTrue(preg_match('/[a-f0-9]{32}/',implode(' ',$command->messages),$match)===1 && password_verify($match[0],$fresh->password));
});

$failed=array_filter($checks,fn($r)=>!$r['passed']);
echo json_encode(['storage'=>'sqlite-memory/cache-array/bus-fake/telegram-sink','total'=>count($checks),'passed'=>count($checks)-count($failed),'failed'=>$failed,'checks'=>$checks],JSON_UNESCAPED_SLASHES)."\n";
exit($failed ? 1 : 0);

<?php
declare(strict_types=1);
namespace Illuminate\Http { class Request { public array $user = ['id' => 1]; public function input($key) { return 'app'; } } }
namespace Illuminate\Foundation\Http { class FormRequest { public array $data = []; public function validated() { return $this->data; } } }
namespace Illuminate\Support\Facades { class Cache { public static function has($key) { return false; } } }
namespace App\Http\Controllers { class Controller {} }
namespace App\Utils { class Dict { const WITHDRAW_METHOD_WHITELIST_DEFAULT = []; const EMAIL_WHITELIST_SUFFIX_DEFAULT = []; } }
namespace App\Models {
    class User {
        public static function select($fields) { return new self; }
        public function find($id) { return (object)['plan_id' => 1, 'expired_at' => null]; }
    }
}
namespace App\Services {
    class FrontendThemeService { public function activeTheme() { return 'NINI'; } }
    class ConfigurationSaveService { public function save($data) { $GLOBALS['settings'] = $data; } }
}
namespace {
    // Only storage/framework boundaries are stubbed. The actual changed controller and request execute locally.
    function config($key, $default = null) {
        if ($key === 'app.key') return 'local-fixture-only';
        if ($key === 'v2board') return $GLOBALS['settings'];
        return $GLOBALS['settings'][substr($key, 8)] ?? $default;
    }
    function response($data) { return $data; }
    function app($class) { return new $class; }
    function check($value, $message) { if (!$value) throw new \RuntimeException($message); }
    require dirname(__DIR__, 2).'/app/Http/Requests/Admin/ConfigSave.php';
    require dirname(__DIR__, 2).'/app/Http/Controllers/V1/Admin/ConfigController.php';
    require dirname(__DIR__, 2).'/app/Http/Controllers/V1/User/CommController.php';
    $GLOBALS['settings'] = ['app_name' => 'Local fixture', 'subscribe_url' => 'https://example.test', 'tos_url' => null, 'windows_version' => '1.0'];
    $controller = new \App\Http\Controllers\V1\Admin\ConfigController;
    $userController = new \App\Http\Controllers\V1\User\CommController;
    $request = new \App\Http\Requests\Admin\ConfigSave;
    foreach (['windows', 'macos', 'android'] as $platform) {
        $key = $platform.'_download_url_2';
        check($request->rules()[$key] === 'nullable|string|max:4096|url:http,https', 'Second URL must be validated');
        check($controller->fetch(new \Illuminate\Http\Request)['data']['app'][$key] === null, 'Default must be empty');
        foreach (['https://example.test/便宜客户端.exe?one=1&two=2', 'https://mirror.test/app', '', null] as $value) {
            $original = $GLOBALS['settings'];
            $request->data = [$key => $value];
            check($controller->save($request)['data'] === true, 'Save failed');
            check($controller->fetch(new \Illuminate\Http\Request)['data']['app'][$key] === $value, 'Admin roundtrip failed');
            check($userController->config(new \Illuminate\Http\Request)['data'][$key] === $value, 'Front end roundtrip failed');
            check(array_diff_key($GLOBALS['settings'], [$key => 1]) === array_diff_key($original, [$key => 1]), 'Unrelated setting changed');
        }
    }
    $request->data = ['windows_download_url_2' => 'https://mirror.test/windows', 'macos_download_url_2' => 'https://mirror.test/mac', 'android_download_url_2' => 'https://mirror.test/android'];
    $controller->save($request);
    $expected = $request->data;
    $request->data = ['windows_version' => '1.1.0'];
    $controller->save($request);
    foreach ($expected as $key => $value) check($GLOBALS['settings'][$key] === $value, 'Existing form save removed mirror');
    check($userController->config(new \Illuminate\Http\Request)['data']['windows_version'] === '1.1.0', 'Original version flow changed');
    echo "PHP 8.5: three-platform save/read/clear, user API and unrelated configuration preservation passed.\n";
}

<?php
declare(strict_types=1);
namespace Illuminate\Http { class Request { public function input($key) { return 'site'; } } }
namespace Illuminate\Foundation\Http { class FormRequest { public array $data = []; public function validated() { return $this->data; } } }
namespace Illuminate\Support\Facades { class Cache { public static function has($key) { return false; } } }
namespace App\Http\Controllers { class Controller {} }
namespace App\Utils { class Dict { const WITHDRAW_METHOD_WHITELIST_DEFAULT = []; const EMAIL_WHITELIST_SUFFIX_DEFAULT = []; } }
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
    $GLOBALS['settings'] = ['app_name' => 'Local fixture', 'subscribe_url' => 'https://example.test', 'tos_url' => null, 'windows_version' => '1.0'];
    $original = $GLOBALS['settings'];
    $controller = new \App\Http\Controllers\V1\Admin\ConfigController;
    $request = new \App\Http\Requests\Admin\ConfigSave;
    check($request->rules()['admin_dashboard_privacy'] === 'in:0,1', 'Boolean whitelist required');
    check($controller->fetch(new \Illuminate\Http\Request)['data']['site']['admin_dashboard_privacy'] === 0, 'Default must be disabled');
    foreach ([1, 0, '1', '0'] as $value) {
        $request->data = ['admin_dashboard_privacy' => $value];
        check($controller->save($request)['data'] === true, 'Save failed');
        check($controller->fetch(new \Illuminate\Http\Request)['data']['site']['admin_dashboard_privacy'] === (int)$value, 'Roundtrip failed');
        check(array_diff_key($GLOBALS['settings'], ['admin_dashboard_privacy' => 1]) === $original, 'Unrelated setting changed');
    }
    $request->data = ['app_name' => 'Another local name'];
    $controller->save($request);
    check((int)$GLOBALS['settings']['admin_dashboard_privacy'] === 0, 'Other settings overwrote privacy');
    echo "PHP 8.5 config roundtrip and preservation: passed\n";
}

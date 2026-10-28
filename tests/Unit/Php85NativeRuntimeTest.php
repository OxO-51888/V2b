<?php

namespace Tests\Unit;

use App\Services\PaymentService;
use Tests\TestCase;

class Php85NativeRuntimeTest extends TestCase
{
    public function testRuntimeAndDependenciesTargetOnlyPhp85AndLaravel13(): void
    {
        $this->assertGreaterThanOrEqual(80512, PHP_VERSION_ID);
        $this->assertLessThan(80600, PHP_VERSION_ID);
        $this->assertStringStartsWith('13.', app()->version());
        $composer = json_decode(file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('~8.5.12', $composer['require']['php']);
        $this->assertSame('8.5.12', $composer['config']['platform']['php']);
        $this->assertArrayNotHasKey('paragonie/sodium_compat', $composer['require']);
        $this->assertFalse(class_exists('ParagonIE_Sodium_Compat'));
        $this->assertTrue(extension_loaded('sodium'));
        $this->assertSame(5, (new \ReflectionFunction('openssl_public_encrypt'))->getNumberOfParameters());
    }

    public function testEveryPaymentDriverCanLoadItsAdminFormWithoutDeprecations(): void
    {
        $files = glob(app_path('Payments/*.php'));
        $this->assertCount(14, $files);
        set_error_handler(static function ($level, $message, $file, $line) {
            throw new \ErrorException($message, 0, $level, $file, $line);
        }, E_ALL);
        try {
            foreach ($files as $file) {
                $name = basename($file, '.php');
                $class = 'App\\Payments\\' . $name;
                $driver = new $class([]);
                $this->assertTrue((new \ReflectionProperty($driver, 'config'))->isDefault(), $name);
                $this->assertSame($driver->form(), (new PaymentService($name))->form(), $name);
            }
        } finally {
            restore_error_handler();
        }
    }

    public function testNativePaymentSignatureComparisonPreservesResults(): void
    {
        foreach ([\App\Payments\BTCPay::class, \App\Payments\Coinbase::class] as $class) {
            $driver = new $class([]);
            $method = new \ReflectionMethod($driver, 'hashEqual');
            foreach ([['', ''], ['abc', 'abc'], ['abc', 'abd'], ['short', 'longer'], ["\0\xff", "\0\xfe"]] as [$a, $b]) {
                $this->assertSame(hash_equals($a, $b), $method->invoke($driver, $a, $b));
            }
        }
    }

    public function testNativeOaepRequiresSha256AndGatewayNoLongerStartsAProcess(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $public = openssl_pkey_get_details($key)['key'];
        $secret = random_bytes(32);
        $this->assertTrue(openssl_public_encrypt($secret, $cipher, $public, OPENSSL_PKCS1_OAEP_PADDING, 'sha256'));
        $this->assertTrue(openssl_private_decrypt($cipher, $plain, $key, OPENSSL_PKCS1_OAEP_PADDING, 'sha256'));
        $this->assertSame($secret, $plain);
        $this->assertFalse(openssl_private_decrypt($cipher, $wrong, $key, OPENSSL_PKCS1_OAEP_PADDING, 'sha1'));
        while (openssl_error_string() !== false) {}
        $this->assertStringNotContainsString('proc_open', file_get_contents(app_path('Services/ClientGateway/Crypto.php')));
    }
}

<?php

namespace App\Modules\ClientGateway;

class Crypto
{
    private $secret;
    public $publicPem;

    public function __construct(string $directory)
    {
        $private = $this->der($directory . '/license-private.pem', 'PRIVATE KEY');
        $public = $this->der($directory . '/license-public.pem', 'PUBLIC KEY');
        $privatePrefix = hex2bin('302e020100300506032b657004220420');
        $publicPrefix = hex2bin('302a300506032b6570032100');
        if (strlen($private) !== 48 || substr($private, 0, 16) !== $privatePrefix
            || strlen($public) !== 44 || substr($public, 0, 12) !== $publicPrefix) {
            throw new \RuntimeException('Invalid Ed25519 signing key format');
        }
        $pair = sodium_crypto_sign_seed_keypair(substr($private, 16));
        if (!hash_equals(sodium_crypto_sign_publickey($pair), substr($public, 12))) {
            throw new \RuntimeException('Gateway signing keys do not match');
        }
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->publicPem = file_get_contents($directory . '/license-public.pem');
    }

    private function der(string $file, string $label): string
    {
        $pem = file_get_contents($file);
        if (!preg_match('/\A-----BEGIN ' . $label . '-----\s+([A-Za-z0-9+\/=\r\n]+)-----END ' . $label . '-----\s*\z/', $pem, $match)) {
            throw new \RuntimeException('Signing key is unavailable');
        }
        return base64_decode(preg_replace('/\s+/', '', $match[1]), true);
    }

    public static function json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
    }

    public static function canonical($value): string
    {
        if (is_object($value) || (is_array($value) && !array_is_list($value))) {
            $values = (array) $value;
            ksort($values, SORT_STRING);
            $pairs = [];
            foreach ($values as $key => $item) $pairs[] = self::json((string) $key) . ':' . self::canonical($item);
            return '{' . implode(',', $pairs) . '}';
        }
        if (is_array($value)) return '[' . implode(',', array_map([self::class, 'canonical'], $value)) . ']';
        return self::json($value);
    }

    public static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function now(int $seconds = 0): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . $seconds . ' seconds')->format('Y-m-d\TH:i:s.v\Z');
    }

    public function sign(array $payload): string
    {
        return self::b64(sodium_crypto_sign_detached(self::canonical($payload), $this->secret));
    }

    public function clientKey(string $pem): string
    {
        if (strlen($pem) > 4096 || strpos($pem, 'PRIVATE KEY') !== false) throw new \InvalidArgumentException('Invalid public key');
        $key = @openssl_pkey_get_public($pem);
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048 || $details['bits'] > 4096) {
            throw new \InvalidArgumentException('RSA public key must be 2048-4096 bits');
        }
        return $details['key'];
    }

    public function envelope(string $publicKey, array $payload): array
    {
        $publicKey = $this->clientKey($publicKey);
        $aes = random_bytes(32);
        $iv = random_bytes(12);
        $aad = 'appgate-secure-auth-v1';
        $ciphertext = openssl_encrypt(self::json($payload), 'aes-256-gcm', $aes, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($ciphertext === false) throw new \RuntimeException('Encryption failed');
        if (!openssl_public_encrypt($aes, $encryptedKey, $publicKey, OPENSSL_PKCS1_OAEP_PADDING, 'sha256')
            || strlen($encryptedKey) < 256) {
            throw new \RuntimeException('RSA encryption failed');
        }
        $envelope = ['ok' => true, 'secure' => true, 'version' => 1, 'alg' => 'RSA-OAEP-SHA256+A256GCM+Ed25519',
            'aad' => self::b64($aad), 'key' => self::b64($encryptedKey), 'iv' => self::b64($iv),
            'tag' => self::b64($tag), 'payload' => self::b64($ciphertext), 'issuedAt' => self::now()];
        return $envelope + ['signature' => $this->sign($envelope)];
    }
}

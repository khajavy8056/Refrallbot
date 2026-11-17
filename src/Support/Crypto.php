<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * رمزنگاری اطلاعات حساس (توکن ربات، شماره کارت) با AES-256-GCM
 * کلید از APP_KEY در .env خوانده می‌شود.
 */
final class Crypto
{
    private static ?string $key = null;

    public static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $appKey = Env::get('APP_KEY', '');
        if ($appKey === '' || strlen($appKey) < 32) {
            $file = RB_STORAGE . '/app_key.php';
            if (is_file($file)) {
                $appKey = (string) trim((string) file_get_contents($file));
            }
            if (strlen($appKey) < 32) {
                $appKey = self::generateKeyFile($file);
            }
        }
        return self::$key = hash('sha256', $appKey, true);
    }

    private static function generateKeyFile(string $file): string
    {
        $key = bin2hex(random_bytes(32));
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        @file_put_contents($file, $key, LOCK_EX);
        @chmod($file, 0640);
        return $key;
    }

    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('رمزنگاری ناموفق بود.');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        if (!str_starts_with($payload, 'v1:')) {
            // داده‌ی رمزنگاری‌نشده قدیمی
            return $payload;
        }
        $raw = base64_decode(substr($payload, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }

    /** ماسک کردن توکن برای نمایش */
    public static function maskSecret(?string $secret): string
    {
        if ($secret === null || $secret === '') {
            return '—';
        }
        $len = strlen($secret);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return substr($secret, 0, 4) . str_repeat('•', max(4, $len - 10)) . substr($secret, -6);
    }
}

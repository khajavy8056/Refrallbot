<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * محافظت در برابر CSRF برای فرم‌ها و درخواست‌های AJAX
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function check(?string $token = null): bool
    {
        $token ??= $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!is_string($token) || $token === '') {
            return false;
        }
        $expected = $_SESSION[self::KEY] ?? '';
        return $expected !== '' && hash_equals($expected, $token);
    }

    public static function verify(): void
    {
        if (!self::check()) {
            if (Request::isAjax()) {
                json_out(['ok' => false, 'error' => 'توکن امنیتی نامعتبر است. صفحه را دوباره بارگذاری کنید.'], 419);
                exit;
            }
            if (!headers_sent()) {
                http_response_code(419);
            }
            echo 'توکن امنیتی نامعتبر است. لطفاً صفحه را دوباره بارگذاری کنید.';
            exit;
        }
    }
}

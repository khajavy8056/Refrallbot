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

    /**
     * خواندن توکن از همهٔ منابع ممکن، به ترتیب اولویت:
     *   ۱) هدر X-CSRF-Token (روشی که RB.post در JavaScript استفاده می‌کند)
     *   ۲) بدنهٔ JSON (چون RB.post بدنه را JSON می‌فرستد و $_POST خالی است)
     *   ۳) فیلد _csrf یا csrf_token در فرم معمولی
     *   ۴) پارامتر _csrf در آدرس
     */
    private static function submittedToken(): ?string
    {
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_CSRF'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }

        // بدنهٔ JSON — باگ واقعی: قبلاً فقط $_POST بررسی می‌شد و درخواست‌های
        // JSON همیشه 419 می‌گرفتند.
        $json = Request::json();
        foreach (['_csrf', 'csrf_token', '_token'] as $key) {
            if (isset($json[$key]) && is_string($json[$key]) && $json[$key] !== '') {
                return $json[$key];
            }
        }

        foreach (['_csrf', 'csrf_token', '_token'] as $key) {
            if (isset($_POST[$key]) && is_string($_POST[$key]) && $_POST[$key] !== '') {
                return $_POST[$key];
            }
            if (isset($_GET[$key]) && is_string($_GET[$key]) && $_GET[$key] !== '') {
                return $_GET[$key];
            }
        }

        return null;
    }

    public static function check(?string $token = null): bool
    {
        $token ??= self::submittedToken();
        if (!is_string($token) || $token === '') {
            return false;
        }
        $expected = $_SESSION[self::KEY] ?? '';
        return $expected !== '' && hash_equals($expected, $token);
    }

    public static function verify(): void
    {
        if (!self::check()) {
            $message = 'توکن امنیتی نامعتبر یا منقضی شده است. صفحه را دوباره بارگذاری کنید.';
            if (Request::isAjax() || Request::expectsJson()) {
                json_out([
                    'ok' => false,
                    'error' => $message,
                    'code' => 'csrf_invalid',
                    'restart' => true,
                ], 419);
                exit;
            }
            if (!headers_sent()) {
                http_response_code(419);
            }
            echo $message;
            exit;
        }
    }
}

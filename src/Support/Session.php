<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * مدیریت امن Session
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // اگر هدرها ارسال شده‌اند یا SAPI فاقد session است (مثلاً wasm/CLI بدون
        // storage)، تلاش برای session_start فقط یک Warning تولید می‌کند که
        // ErrorHandler آن را به ErrorException تبدیل می‌کند و کل درخواست
        // می‌میرد — با بدنهٔ خالی. پس قبل از هر کاری محیط را بررسی می‌کنیم.
        if (PHP_SAPI === 'cli' || !function_exists('session_start')) {
            $_SESSION = is_array($_SESSION ?? null) ? $_SESSION : [];
            return;
        }

        $secure = Request::isHttps();
        if (headers_sent()) {
            // در CLI/تست یا پس از ارسال خروجی، فقط نشست موجود را ادامه می‌دهیم
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            if (empty($_SESSION['_created'])) {
                $_SESSION['_created'] = time();
                $_SESSION['_fingerprint'] = self::fingerprint();
            }
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('rbsid');
        @session_start();
        if (empty($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
            $_SESSION['_fingerprint'] = self::fingerprint();
        } elseif (($_SESSION['_fingerprint'] ?? '') !== self::fingerprint()) {
            // تغییر مرورگر/دستگاه.
            //
            // نسخهٔ قبلی اینجا نشست را کامل نابود می‌کرد و اثر انگشت شامل
            // پیشوند IP هم بود. روی اندروید/Termux و شبکهٔ همراه، IP عمومی
            // مرتب عوض می‌شود؛ نتیجه این بود که در میانهٔ نصب، اطلاعات
            // دیتابیس، مرحلهٔ ویزارد و توکن CSRF از بین می‌رفت و کاربر با
            // 419/422 در حلقه گیر می‌کرد.
            //
            // حالا: فقط شناسهٔ نشست بازتولید می‌شود (ضد Session Fixation)
            // و دادهٔ نشست حفظ می‌ماند.
            \RB\Support\Session::regenerate();
            $_SESSION['_fingerprint'] = self::fingerprint();
        }
    }

    /**
     * اثر انگشت مرورگر — عمداً بدون IP.
     *
     * IP در شبکهٔ همراه/Termux ناپایدار است و نباید باعث از دست رفتن
     * نشست شود. User-Agent برای تشخیص تغییر دستگاه کافی است و اگر عوض
     * شود، فقط شناسهٔ نشست بازتولید می‌شود (نه نابودی داده).
     */
    private static function fingerprint(): string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $lang = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        return hash('sha256', $ua . '|' . $lang);
    }

    /** شمارندهٔ بازتولید شناسه (برای تست‌ها؛ در SAPI غیروب session_id خالی است) */
    public static int $regenerateCount = 0;

    public static function regenerate(): void
    {
        self::$regenerateCount++;
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}

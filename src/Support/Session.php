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
            // نشست مشکوک — شروع مجدد
            session_unset();
            session_destroy();
            @session_start();
            $_SESSION['_created'] = time();
            $_SESSION['_fingerprint'] = self::fingerprint();
        }
    }

    private static function fingerprint(): string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return hash('sha256', $ua . '|' . substr(Request::ip(), 0, 7));
    }

    public static function regenerate(): void
    {
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

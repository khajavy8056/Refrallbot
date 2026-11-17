<?php
declare(strict_types=1);

namespace RB\Http;

use RB\Services\AdminAuth;
use RB\Services\TelegramWebApp;
use RB\Support\Request;
use RB\Support\Session;

/**
 * میدل‌ورهای کنترل دسترسی
 */
final class Middleware
{
    /** @return bool false = پردازش متوقف شود */
    public static function run(string $name): bool
    {
        return match ($name) {
            'admin' => self::admin(),
            'admin_guest' => self::adminGuest(),
            'csrf' => self::csrf(),
            'installed' => self::installed(),
            default => true,
        };
    }

    public static function installed(): bool
    {
        if (self::isInstalled()) {
            return true;
        }
        Request::redirect(url('install'));
        return false;
    }

    public static function isInstalled(): bool
    {
        if (\RB\Support\Env::bool('RB_INSTALLED', false)) {
            return true;
        }
        try {
            $db = \RB\Support\Database::instance();
            if (!$db->tableExists('system_settings')) {
                return false;
            }
            return (bool) $db->value("SELECT 1 FROM system_settings WHERE `key` = 'installed_at' LIMIT 1");
        } catch (\Throwable) {
            return false;
        }
    }

    public static function admin(): bool
    {
        Session::start();
        if (!empty($_SESSION['admin_id']) && AdminAuth::validateSession()) {
            return true;
        }
        if (Request::path() !== '/admin/login') {
            if (Request::isAjax() || str_starts_with(Request::path(), '/admin/api/')) {
                json_out(['ok' => false, 'error' => 'نیاز به ورود مجدد دارید.', 'redirect' => url('admin/login')], 401);
                return false;
            }
            $_SESSION['_intended'] = Request::path();
            Request::redirect(url('admin/login'));
            return false;
        }
        return true;
    }

    public static function adminGuest(): bool
    {
        Session::start();
        if (!empty($_SESSION['admin_id']) && AdminAuth::validateSession()) {
            Request::redirect(url('admin'));
            return false;
        }
        return true;
    }

    public static function csrf(): bool
    {
        Session::start();
        \RB\Support\Csrf::verify();
        return true;
    }
}

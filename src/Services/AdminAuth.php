<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;
use RB\Support\Request;
use RB\Support\Session;

/**
 * احراز هویت مدیران با رمز هش‌شده، محدودیت تلاش و ثبت لاگ
 */
final class AdminAuth
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 15;
    public const SESSION_LIFETIME = 7200; // ۲ ساعت بی‌فعالیتی

    public static function attempt(string $username, string $password, string $ip): array
    {
        $db = Database::instance();
        $username = trim($username);

        if (self::isLocked($username, $ip)) {
            return ['ok' => false, 'error' => 'تعداد تلاش‌های ناموفق زیاد است. لطفاً ' . self::LOCK_MINUTES . ' دقیقه بعد تلاش کنید.'];
        }

        $admin = $db->first('SELECT * FROM admins WHERE username = :u LIMIT 1', ['u' => $username]);
        if ($admin === null || !password_verify($password, (string) $admin['password_hash'])) {
            self::recordFailure($username, $ip);
            AuditService::log('admin.login_failed', 'admin', $admin ? (int) $admin['id'] : null, ['username' => $username]);
            return ['ok' => false, 'error' => 'نام کاربری یا رمز عبور نادرست است.'];
        }
        if (!(int) $admin['is_active']) {
            return ['ok' => false, 'error' => 'حساب مدیریتی شما غیرفعال است.'];
        }

        // ارتقای هش در صورت نیاز
        if (password_needs_rehash((string) $admin['password_hash'], PASSWORD_DEFAULT)) {
            $db->update('admins', [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ], 'id = :id', ['id' => (int) $admin['id']]);
        }

        self::clearFailures($username, $ip);
        self::login((int) $admin['id'], $ip);
        return ['ok' => true, 'admin' => $admin];
    }

    public static function login(int $adminId, string $ip): void
    {
        $db = Database::instance();
        Session::start();
        Session::regenerate();
        $_SESSION['admin_id'] = $adminId;
        $_SESSION['admin_last_seen'] = time();
        $_SESSION['admin_ip'] = $ip;
        $db->update('admins', [
            'last_login_at' => now(),
            'last_login_ip' => $ip,
            'failed_attempts' => 0,
            'locked_until' => null,
        ], 'id = :id', ['id' => $adminId]);
        AuditService::log('admin.login', 'admin', $adminId);
    }

    public static function validateSession(): bool
    {
        Session::start();
        if (empty($_SESSION['admin_id'])) {
            return false;
        }
        $lastSeen = (int) ($_SESSION['admin_last_seen'] ?? 0);
        if ($lastSeen > 0 && time() - $lastSeen > self::SESSION_LIFETIME) {
            self::logout();
            return false;
        }
        $_SESSION['admin_last_seen'] = time();

        $admin = Database::instance()->first(
            'SELECT id, is_active FROM admins WHERE id = :id',
            ['id' => (int) $_SESSION['admin_id']]
        );
        if ($admin === null || !(int) $admin['is_active']) {
            self::logout();
            return false;
        }
        return true;
    }

    public static function logout(): void
    {
        $adminId = $_SESSION['admin_id'] ?? null;
        if ($adminId) {
            try {
                AuditService::log('admin.logout', 'admin', (int) $adminId);
            } catch (\Throwable) {
            }
        }
        Session::start();
        Session::destroy();
    }

    public static function current(): ?array
    {
        Session::start();
        if (empty($_SESSION['admin_id'])) {
            return null;
        }
        return Database::instance()->first(
            'SELECT a.*, r.name AS role_name, r.slug AS role_slug, r.permissions
             FROM admins a LEFT JOIN admin_roles r ON r.id = a.role_id
             WHERE a.id = :id',
            ['id' => (int) $_SESSION['admin_id']]
        );
    }

    public static function requireSuperAdmin(): bool
    {
        $admin = self::current();
        return $admin !== null && (($admin['role_slug'] ?? '') === 'superadmin');
    }

    private static function isLocked(string $username, string $ip): bool
    {
        $db = Database::instance();
        $locked = $db->value(
            'SELECT locked_until FROM login_attempts WHERE username = :u AND ip = :ip',
            ['u' => $username, 'ip' => $ip]
        );
        return $locked !== null && strtotime((string) $locked) > time();
    }

    private static function recordFailure(string $username, string $ip): void
    {
        $db = Database::instance();
        $row = $db->first(
            'SELECT * FROM login_attempts WHERE username = :u AND ip = :ip',
            ['u' => $username, 'ip' => $ip]
        );
        if ($row === null) {
            $db->insert('login_attempts', [
                'username' => $username,
                'ip' => $ip,
                'failed_count' => 1,
                'last_attempt_at' => now(),
                'locked_until' => null,
            ]);
            return;
        }
        $count = (int) $row['failed_count'] + 1;
        $db->update('login_attempts', [
            'failed_count' => $count,
            'last_attempt_at' => now(),
            'locked_until' => $count >= self::MAX_ATTEMPTS
                ? date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60)
                : null,
        ], 'id = :id', ['id' => (int) $row['id']]);
    }

    private static function clearFailures(string $username, string $ip): void
    {
        Database::instance()->delete('login_attempts', 'username = :u AND ip = :ip', ['u' => $username, 'ip' => $ip]);
    }

    /** ورود با لینک یک‌بارمصرف تلگرام */
    public static function attemptMagicToken(string $token): bool
    {
        $db = Database::instance();
        if ($token === '' || !preg_match('~^[a-f0-9]{48}$~', $token)) {
            return false;
        }
        $key = 'magic_login:' . hash('sha256', $token);
        $row = $db->first('SELECT * FROM system_settings WHERE `key` = :k', ['k' => $key]);
        if ($row === null) {
            return false;
        }
        $payload = json_decode((string) $row['value'], true) ?: [];
        $db->delete('system_settings', '`key` = :k', ['k' => $key]);
        if (($payload['expires'] ?? 0) < time()) {
            return false;
        }
        $admin = $db->first('SELECT id, is_active FROM admins WHERE id = :id', ['id' => (int) ($payload['admin_id'] ?? 0)]);
        if ($admin === null || !(int) $admin['is_active']) {
            return false;
        }
        self::login((int) $admin['id'], Request::ip());
        AuditService::log('admin.login', 'admin', (int) $admin['id'], ['method' => 'telegram_magic_link']);
        return true;
    }
}

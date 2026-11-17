<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * کمکی‌های درخواست HTTP
 */
final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = trim((string) Config::get('app.base_path', ''), '/');
        if ($base !== '' && str_starts_with(ltrim($path, '/'), $base)) {
            $path = substr(ltrim($path, '/'), strlen($base));
            $path = '/' . ltrim($path, '/');
        }
        return $path === '' ? '/' : $path;
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return false;
    }

    /** مبدأ (scheme://host[:port]) درخواست جاری */
    public static function origin(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $scheme = self::isHttps() ? 'https' : 'http';
        return $scheme . '://' . $host;
    }

    /**
     * آی‌پی کلاینت.
     * هدرهای پروکسی فقط وقتی معتبر شمرده می‌شوند که REMOTE_ADDR در TRUSTED_PROXIES باشد
     * (وگرنه هر کسی می‌تواند با X-Forwarded-For هویت جعل کند و قفل ورود/کنترل تقلب را دور بزند).
     */
    public static function ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            $remote = '';
        }

        if ($remote !== '' && self::isTrustedProxy($remote)) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $key) {
                $value = trim((string) ($_SERVER[$key] ?? ''));
                if ($value === '') {
                    continue;
                }
                $ip = trim(explode(',', $value)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $remote !== '' ? $remote : '0.0.0.0';
    }

    /** آیا آی‌پی در فهرست پروکسی‌های مطمئن (TRUSTED_PROXIES) است؟ */
    public static function isTrustedProxy(string $ip): bool
    {
        $list = (string) (Env::get('TRUSTED_PROXIES', '') ?? '');
        if (trim($list) === '') {
            return false;
        }
        foreach (explode(',', $list) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if ($entry === '*' || $entry === '0.0.0.0/0') {
                return true;
            }
            if (str_contains($entry, '/')) {
                if (self::ipInCidr($ip, $entry)) {
                    return true;
                }
                continue;
            }
            if ($entry === $ip) {
                return true;
            }
        }
        return false;
    }

    /** بررسی تعلق آی‌پی به محدوده CIDR (IPv4 و IPv6) */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($bits === null || !is_numeric($bits)) {
            return false;
        }
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton((string) $subnet);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = (int) $bits;
        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = 0xFF << (8 - $remainder) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    public static function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public static function isAjax(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    }

    /** بدنه JSON خام */
    public static function json(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return $cache = is_array($data) ? $data : [];
    }

    public static function raw(): string
    {
        return (string) file_get_contents('php://input');
    }

    /**
     * بازگردانی کاربر. در محیط تست می‌توان با Request::$redirectHandler
     * به‌جای exit، یک RedirectException گرفت و مقصد را بررسی کرد.
     *
     * @var null|callable(string,int):void
     */
    public static $redirectHandler = null;

    public static function redirect(string $to, int $status = 302): never
    {
        if (self::$redirectHandler !== null) {
            (self::$redirectHandler)($to, $status);
            throw new RedirectException($to, $status);
        }
        if (!headers_sent()) {
            header('Location: ' . $to, true, $status);
        }
        echo '<meta http-equiv="refresh" content="0;url=' . e($to) . '">';
        exit;
    }
}

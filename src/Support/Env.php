<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * خواندن ساده فایل .env بدون وابستگی خارجی
 */
final class Env
{
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
                $inner = substr($value, 1, -1);
                // بازگردانی escapeها — مکمل InstallerController::envValue()
                // تا رمز دیتابیس با کاراکترهای خاص (", \\, newline) دقیقاً
                // همان‌طور که وارد شده بازخوانی شود.
                $value = strtr($inner, [
                    '\\n' => "\n",
                    '\\r' => "\r",
                    '\\t' => "\t",
                    '\\"' => '"',
                    '\\\\' => '\\',
                ]);
            } elseif (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
                $value = substr($value, 1, -1);
            }
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = self::$values[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }

    /**
     * مقدار خام بدون تبدیل «تهی → پیش‌فرض».
     *
     * Env::get() عمداً مقدار خالی را «تعریف‌نشده» حساب می‌کند تا مقادیر
     * پیش‌فرض (مثل APP_TIMEZONE) کار کنند. اما برای مقادیری که خالی بودنشان
     * معنادار است (مثلاً DB_PASSWORD بدون رمز) باید بتوان رشتهٔ خالی را از
     * «کلید موجود نیست» تشخیص داد.
     */
    public static function getRaw(string $key): ?string
    {
        if (array_key_exists($key, self::$values)) {
            return (string) self::$values[$key];
        }
        $env = getenv($key);
        return $env === false ? null : (string) $env;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }
}

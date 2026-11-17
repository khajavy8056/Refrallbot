<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * بارگذاری فایل‌های تنظیمات از config/
 */
final class Config
{
    private static array $data = [];
    private static string $path = '';

    public static function init(string $path): void
    {
        self::$path = rtrim($path, '/');
    }

    /** @return mixed */
    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $file = array_shift($parts);
        if (!array_key_exists($file, self::$data)) {
            $full = self::$path . '/' . $file . '.php';
            self::$data[$file] = is_file($full) ? require $full : [];
        }
        $value = self::$data[$file];
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value ?? $default;
    }
}

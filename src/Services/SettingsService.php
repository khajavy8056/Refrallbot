<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;

/**
 * تنظیمات سیستم (کلید/مقدار) با کش در حافظه
 */
final class SettingsService
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            $rows = Database::instance()->all('SELECT key, value FROM system_settings');
        } catch (\Throwable) {
            return self::$cache = [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[$row['key']] = $row['value'];
        }
        return self::$cache = $out;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    public static function set(string $key, mixed $value, string $group = 'general'): void
    {
        $db = Database::instance();
        $stringValue = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
        $exists = $db->value('SELECT id FROM system_settings WHERE `key` = :k', ['k' => $key]);
        if ($exists) {
            $db->update('system_settings', [
                'value' => $stringValue,
                'group_name' => $group,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => (int) $exists]);
        } else {
            $db->insert('system_settings', [
                'key' => $key,
                'value' => $stringValue,
                'group_name' => $group,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        self::$cache = null;
    }

    public static function setMany(array $values, string $group = 'general'): void
    {
        foreach ($values as $key => $value) {
            self::set($key, $value, $group);
        }
    }

    /** گروه هر کلید — برای نمایش در صفحه تنظیمات */
    public static function groupOf(string $key): string
    {
        try {
            $group = Database::instance()->value(
                'SELECT group_name FROM system_settings WHERE `key` = :k',
                ['k' => $key]
            );
        } catch (\Throwable) {
            return 'general';
        }
        return (string) ($group ?: 'general');
    }

    public static function forget(): void
    {
        self::$cache = null;
    }
}

<?php
declare(strict_types=1);

use RB\Support\Database;
use RB\Support\SchemaBuilder;

/**
 * Migration 003 — سیستم پاداش دوطرفه دعوت دوستان + پروفایل و ظاهر ربات (v1.3.0)
 *
 * کاملاً Idempotent و سازگار با:
 * - نصب تازه روی MySQL 8 / MariaDB 10.3+ و SQLite 3
 * - ارتقای مستقیم یک‌کلیکی از نسخه‌های v1.1.x و v1.2.0 (حتی پیش از بارگذاری SchemaBuilder جدید در حافظه)
 */
return static function (SchemaBuilder $schema): void {
    $columns = [
        // تنظیمات مستقل و نسخه‌بندی‌شده پاداش دعوت‌شده (خوش‌آمدگویی)
        ['commission_setting_versions', 'invitee_reward',  'BIGINT NOT NULL DEFAULT 0',      'INTEGER NOT NULL DEFAULT 0'],
        ['commission_setting_versions', 'invitee_enabled', 'TINYINT(1) NOT NULL DEFAULT 0',  'INTEGER NOT NULL DEFAULT 0'],

        // مجموع پاداش خوش‌آمدگویی دعوت دریافتی هر کاربر (همگام با دفتر کل)
        ['users', 'welcome_earned', 'BIGINT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],

        // مشخصات ظاهری ربات تلگرام (بیوگرافی کوتاه Bio، توضیحات کامل Description و تصویر پروفایل)
        ['telegram_settings', 'bot_short_description', 'VARCHAR(160) NULL DEFAULT NULL', 'TEXT NULL'],
        ['telegram_settings', 'bot_description',       'TEXT NULL',                      'TEXT NULL'],
        ['telegram_settings', 'bot_photo_path',        'VARCHAR(255) NULL DEFAULT NULL', 'TEXT NULL'],
        ['telegram_settings', 'bot_photo_file_id',     'VARCHAR(191) NULL DEFAULT NULL', 'TEXT NULL'],
        ['telegram_settings', 'profile_synced_at',     'DATETIME NULL DEFAULT NULL',     'TEXT NULL'],
    ];

    if (method_exists($schema, 'addColumn')) {
        foreach ($columns as [$table, $column, $mysqlDef, $sqliteDef]) {
            $schema->addColumn($table, $column, $mysqlDef, $sqliteDef);
        }
        return;
    }

    // مسیر سازگاری هنگام ارتقای زنده از نسخه‌های v1.1.x / v1.2.0 که هنوز SchemaBuilder قدیمی در حافظه است
    $db = Database::instance();
    $pdo = $db->pdo();
    $isSqlite = $db->isSqlite();
    $q = $isSqlite ? '"' : '`';

    $hasColumn = static function (string $table, string $column) use ($db, $pdo, $isSqlite, $q): bool {
        if (!$db->tableExists($table)) {
            return false;
        }
        if ($isSqlite) {
            $stmt = $pdo->query("PRAGMA table_info({$q}{$table}{$q})");
            if ($stmt === false) {
                return false;
            }
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                if (isset($row['name']) && strcasecmp((string) $row['name'], $column) === 0) {
                    return true;
                }
            }
            return false;
        }
        $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $column);
        $stmt = $pdo->query("SHOW COLUMNS FROM {$q}{$table}{$q} LIKE " . $db->quote($like));
        if ($stmt === false) {
            return false;
        }
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $field = (string) ($row['Field'] ?? $row['field'] ?? '');
            if ($field !== '' && strcasecmp($field, $column) === 0) {
                return true;
            }
        }
        return false;
    };

    foreach ($columns as [$table, $column, $mysqlDef, $sqliteDef]) {
        if (!$db->tableExists($table) || $hasColumn($table, $column)) {
            continue;
        }
        $def = $isSqlite ? $sqliteDef : $mysqlDef;
        $pdo->exec("ALTER TABLE {$q}{$table}{$q} ADD COLUMN {$q}{$column}{$q} {$def}");
    }
};

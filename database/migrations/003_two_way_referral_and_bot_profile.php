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

    // مسیر سازگاری در صورت کش بودن SchemaBuilder قدیمی در OPcache (PDO خالص بدون وابستگی به متدهای جدید Database)
    $db = Database::instance();
    $pdo = $db->pdo();
    $isSqlite = method_exists($db, 'isSqlite')
        ? $db->isSqlite()
        : (strtolower((string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) === 'sqlite');
    $q = $isSqlite ? '"' : '`';

    $hasTable = static function (string $table) use ($pdo, $isSqlite): bool {
        if ($isSqlite) {
            $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :t LIMIT 1");
            $st->execute([':t' => $table]);
            return $st->fetchColumn() !== false;
        }
        $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $table);
        $quoted = $pdo->quote($like);
        if ($quoted === false) {
            $quoted = "'" . str_replace("'", "''", $like) . "'";
        }
        $st = $pdo->query('SHOW TABLES LIKE ' . $quoted);
        if ($st === false) {
            return false;
        }
        while (($row = $st->fetch(\PDO::FETCH_NUM)) !== false) {
            if (isset($row[0]) && strcasecmp((string) $row[0], $table) === 0) {
                return true;
            }
        }
        return false;
    };

    $hasColumn = static function (string $table, string $column) use ($pdo, $isSqlite, $q, $hasTable): bool {
        if (!$hasTable($table)) {
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
        $quoted = $pdo->quote($like);
        if ($quoted === false) {
            $quoted = "'" . str_replace("'", "''", $like) . "'";
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM {$q}{$table}{$q} LIKE " . $quoted);
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
        if (!$hasTable($table) || $hasColumn($table, $column)) {
            continue;
        }
        $def = $isSqlite ? $sqliteDef : $mysqlDef;
        try {
            $pdo->exec("ALTER TABLE {$q}{$table}{$q} ADD COLUMN {$q}{$column}{$q} {$def}");
        } catch (\PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            if ($code === 1060 || str_contains(strtolower($e->getMessage()), 'duplicate column name')) {
                continue;
            }
            throw $e;
        }
    }
};

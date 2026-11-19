<?php
declare(strict_types=1);

use RB\Support\Database;
use RB\Support\SchemaBuilder;

/**
 * Migration 005 — عضویت اجباری میان‌پیام‌رسانی قابل راستی‌آزمایی، ثبت آمار دقیق عضویت کانال‌ها
 * و گزارش ساختاریافته خطاهای ارتباطی به تفکیک پیام‌رسان (v1.5.0 — نسخه جامع نهایی)
 *
 * ۱۰۰٪ Idempotent، غیرمخرب و سازگار با:
 * - ارتقای مستقیم تک‌مرحله‌ای از v1.1.x / v1.2.0 / v1.3.0 / v1.4.0 به v1.5.0
 * - نصب تازه روی MySQL 8 / MariaDB 10.3+ (Strict Mode + utf8mb4) و SQLite 3
 */
return static function (SchemaBuilder $schema): void {

    // ۱) ثبت دقیق و تفکیک‌شده وضعیت عضویت هر کاربر در هر کانال اجباری (مستقیم API / میان‌پیام‌رسانی API / تأیید کاربری)
    $schema->table('user_channel_memberships', function ($t) {
        $t->id();
        $t->bigint('user_id')->foreign('user_id', 'users', 'CASCADE');
        $t->bigint('channel_id')->foreign('channel_id', 'telegram_channels', 'CASCADE');
        $t->string('channel_platform', 32, false, "'telegram'");      // پیام‌رسان میزبان کانال
        $t->string('entry_platform', 32, false, "'telegram'");        // پیام‌رسان ورودی کاربر هنگام تأیید
        $t->string('verify_method', 32, false, "'api_direct'");       // api_direct|api_cross_linked|self_declared
        $t->string('status', 20, false, "'verified'");                // verified|left
        $t->string('verified_external_id', 128, true);                // شناسه کاربری تأییدشده در پیام‌رسان میزبان کانال
        $t->datetime('verified_at', true);
        $t->datetime('last_checked_at', true);
        $t->timestamps();
        $t->unique(['user_id', 'channel_id']);
        $t->index(['channel_platform', 'status']);
        $t->index(['entry_platform', 'status']);
        $t->index('channel_id');
    });

    // ۲) گزارش ساختاریافته و ایزوله خطاهای ارتباطی به تفکیک هر پیام‌رسان
    $schema->table('messenger_error_logs', function ($t) {
        $t->id();
        $t->string('platform', 32);                                    // telegram|bale|rubika|eitaa
        $t->string('category', 32, false, "'api'");                    // connection|webhook|send_message|channel_check|polling|profile|api
        $t->string('method_name', 80, true);                           // getMe|sendMessage|getChatMember|setWebhook|...
        $t->int('error_code', true);
        $t->text('message');                                           // متن خطا (پاک‌سازی‌شده از توکن)
        $t->text('context');                                           // JSON جزئیات غیرحساس
        $t->datetime('created_at', false, 'CURRENT_TIMESTAMP');
        $t->index('platform');
        $t->index('category');
        $t->index('created_at');
    });

    // ۳) ستون‌های هدف‌گذاری پیام‌رسان ورودی، راهنمای شرط میان‌پیام‌رسانی و updated_at روی telegram_channels
    $columns = [
        ['telegram_channels', 'target_platforms', "VARCHAR(255) NOT NULL DEFAULT 'same'", 'TEXT NOT NULL DEFAULT \'same\''],
        ['telegram_channels', 'cross_rule_note',  'VARCHAR(255) NULL DEFAULT NULL',       'TEXT NULL'],
        ['telegram_channels', 'updated_at',       'DATETIME NULL DEFAULT NULL',           'TEXT NULL'],
    ];

    if (method_exists($schema, 'addColumn')) {
        foreach ($columns as [$table, $column, $mysqlDef, $sqliteDef]) {
            $schema->addColumn($table, $column, $mysqlDef, $sqliteDef);
        }
        return;
    }

    // مسیر سازگاری برای ارتقای مستقیم در صورت کش بودن SchemaBuilder نسخه v1.2 در OPcache
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

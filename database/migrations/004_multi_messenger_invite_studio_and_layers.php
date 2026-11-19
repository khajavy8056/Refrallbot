<?php
declare(strict_types=1);

use RB\Support\Database;
use RB\Support\SchemaBuilder;

/**
 * Migration 004 — سیستم یکپارچه چندپیام‌رسانی (تلگرام، روبیکا، ایتا، بله)،
 * استودیوی پیام‌ها و بنرهای آماده دعوت دوستان، اتصال تأییدشده حساب‌ها و شفافیت درآمد لایه‌ای (v1.4.0)
 *
 * کاملاً Idempotent و سازگار با:
 * - نصب تازه روی MySQL 8 / MariaDB 10.3+ (Strict Mode + utf8mb4) و SQLite 3
 * - ارتقای مستقیم یک‌کلیکی از نسخه‌های v1.1.x، v1.2.0 و v1.3.0
 */
return static function (SchemaBuilder $schema): void {

    // ۱) تنظیمات، توکن رمزنگاری‌شده، وضعیت اتصال و پروفایل مستقل هر پیام‌رسان
    $schema->table('messenger_connections', function ($t) {
        $t->id();
        $t->string('platform', 32);                                    // telegram|bale|rubika|eitaa|...
        $t->string('display_name', 120, true);
        $t->bool('is_enabled', false);
        $t->string('bot_token_enc', 512, true);                        // رمزنگاری AES-256-GCM
        $t->string('secondary_token_enc', 512, true);                  // توکن مکمل (مثلاً API کانال ایتایار)
        $t->string('webhook_secret', 128, true);
        $t->string('webhook_url', 512, true);
        $t->string('webhook_status', 32, false, "'not_set'");          // active|error|not_set|disabled
        $t->text('webhook_info');
        $t->datetime('webhook_set_at', true);
        $t->bool('polling_enabled', false);
        $t->string('polling_offset', 191, true);
        $t->string('app_slug', 100, true);                             // شناسه پیوند برنامک (ایتا)
        $t->string('bot_id', 128, true);                               // عددی یا GUID رشته‌ای (روبیکا)
        $t->string('bot_username', 100, true);
        $t->string('bot_name', 160, true);
        $t->string('bot_short_description', 255, true);                // بیوگرافی کوتاه (Bio)
        $t->text('bot_description', true);                             // توضیحات کامل / درباره ربات
        $t->text('bot_start_message', true);                           // پیام شروع ثبت‌شده در پلتفرم
        $t->string('bot_share_url', 255, true);
        $t->string('bot_photo_file_id', 255, true);
        $t->string('bot_photo_path', 255, true);                       // مسیر داخلی تصویر پروفایل
        $t->datetime('profile_synced_at', true);
        $t->string('connection_status', 32, false, "'unconfigured'");  // connected|configured|error|unconfigured
        $t->text('last_error');
        $t->datetime('last_test_at', true);
        $t->datetime('last_update_at', true);
        $t->bigint('updates_received', false, '0');
        $t->bigint('messages_sent', false, '0');
        $t->bigint('messages_failed', false, '0');
        $t->bool('menu_synced', false);
        $t->datetime('menu_synced_at', true);
        $t->text('extra_config');                                      // JSON تنظیمات تکمیلی هر پیام‌رسان
        $t->timestamps();
        $t->unique('platform');
        $t->index('is_enabled');
        $t->index('connection_status');
    });

    // ۲) نگاشت هویت‌های پیام‌رسانی به هویت مرکزی کاربر (users.id) و کیف پول واحد
    $schema->table('user_messenger_accounts', function ($t) {
        $t->id();
        $t->bigint('user_id')->foreign('user_id', 'users', 'CASCADE');
        $t->string('platform', 32);                                    // telegram|bale|rubika|eitaa
        $t->string('external_user_id', 128);                           // شناسه اختصاصی کاربر در آن پیام‌رسان
        $t->string('external_chat_id', 128, true);                     // شناسه چت خصوصی برای ارسال پیام
        $t->string('username', 100, true);
        $t->string('first_name', 128, true);
        $t->string('last_name', 128, true);
        $t->string('language_code', 16, false, "'fa'");
        $t->bool('can_send_message', true);
        $t->bool('is_blocked', false);
        $t->bool('is_primary', false);
        $t->string('verified_via', 32, false, "'bot'");                // bot|webapp|link_token|legacy_telegram|admin
        $t->text('meta');                                              // JSON
        $t->datetime('first_seen_at', true);
        $t->datetime('last_seen_at', true);
        $t->timestamps();
        $t->unique(['platform', 'external_user_id']);
        $t->index(['user_id', 'platform']);
        $t->index('platform');
    });

    // ۳) توکن یک‌بارمصرف تأیید اتصال امن حساب‌های چند پیام‌رسان به یک هویت مالی واحد
    //    (صرف تشابه شماره یا نام کاربری هرگز باعث ادغام خودکار نمی‌شود)
    $schema->table('user_link_tokens', function ($t) {
        $t->id();
        $t->bigint('user_id')->foreign('user_id', 'users', 'CASCADE');
        $t->string('token_hash', 64);                                  // SHA-256 کد یک‌بارمصرف
        $t->datetime('expires_at', false);
        $t->datetime('used_at', true);
        $t->string('used_platform', 32, true);
        $t->string('used_external_id', 128, true);
        $t->datetime('created_at', false, 'CURRENT_TIMESTAMP');
        $t->unique('token_hash');
        $t->index('user_id');
        $t->index('expires_at');
    });

    // ۴) ضدتکرار اتمی آپدیت‌های دریافتی برای تمامی پیام‌رسان‌ها
    $schema->table('messenger_processed_updates', function ($t) {
        $t->id();
        $t->string('platform', 32);
        $t->string('update_key', 150);
        $t->string('status', 20, false, "'processing'");               // processing|done|failed
        $t->datetime('created_at', false, 'CURRENT_TIMESTAMP');
        $t->datetime('processed_at', true);
        $t->unique(['platform', 'update_key']);
        $t->index('created_at');
    });

    // ۵) پیام‌های آماده دعوت دوستان (چندقالبه با متغیرهای پویا، ایموجی و قالب پیش‌فرض)
    $schema->table('invite_messages', function ($t) {
        $t->id();
        $t->string('title', 160);
        $t->text('body');
        $t->bool('is_enabled', true);
        $t->bool('is_default', false);
        $t->int('sort_order', false, '0');
        $t->bigint('share_count', false, '0');
        $t->timestamps();
        $t->index('is_enabled');
        $t->index('is_default');
    });

    // ۶) بنرها و تصاویر گرافیکی آماده دعوت دوستان (توکار + آپلود اختصاصی مدیر)
    $schema->table('invite_banners', function ($t) {
        $t->id();
        $t->string('title', 160);
        $t->string('image_path', 255);                                 // assets/img/banners/... یا storage:invite_banners/...
        $t->text('caption_template', true);                            // کپشن اختصاصی اختیاری با متغیرهای پویا
        $t->bool('is_builtin', false);
        $t->bool('is_enabled', true);
        $t->bool('is_default', false);
        $t->int('sort_order', false, '0');
        $t->bigint('share_count', false, '0');
        $t->text('cached_file_ids');                                   // JSON file_id کش‌شده به تفکیک پیام‌رسان
        $t->timestamps();
        $t->index('is_enabled');
        $t->index('is_default');
    });

    // ۷) ستون‌های جدید روی جداول موجود برای تفکیک دقیق پیام‌رسان ورود، دعوت، کانال‌ها و گزارش مالی
    $columns = [
        ['users', 'entry_platform',           "VARCHAR(32) NOT NULL DEFAULT 'telegram'", "TEXT NOT NULL DEFAULT 'telegram'"],
        ['users', 'referral_source_platform', 'VARCHAR(32) NULL DEFAULT NULL',           'TEXT NULL'],

        ['telegram_channels',   'platform',        "VARCHAR(32) NOT NULL DEFAULT 'telegram'", "TEXT NOT NULL DEFAULT 'telegram'"],
        ['telegram_channels',   'check_mode',      "VARCHAR(24) NOT NULL DEFAULT 'api'",      "TEXT NOT NULL DEFAULT 'api'"],
        ['referral_events',     'platform',        'VARCHAR(32) NULL DEFAULT NULL',           'TEXT NULL'],
        ['telegram_messages',   'platform',        "VARCHAR(32) NOT NULL DEFAULT 'telegram'", "TEXT NOT NULL DEFAULT 'telegram'"],
        ['broadcast_campaigns', 'target_platform', "VARCHAR(32) NOT NULL DEFAULT 'all'",      "TEXT NOT NULL DEFAULT 'all'"],
    ];

    if (method_exists($schema, 'addColumn')) {
        foreach ($columns as [$table, $column, $mysqlDef, $sqliteDef]) {
            $schema->addColumn($table, $column, $mysqlDef, $sqliteDef);
        }
        return;
    }

    // مسیر سازگاری برای ارتقای مستقیم از نسخه‌های قدیمی‌تر از v1.3.0
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

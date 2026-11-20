<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * اجرای امن، ترتیبی و Idempotent تمام Migrationهای پایگاه داده
 *
 * طراحی مقاوم در برابر ارتقای زنده تک‌مرحله‌ای (مثلاً v1.1/v1.2 → v1.5):
 * هنگام به‌روزرسانی از پنل مدیریت، کلاس RB\Support\Database نسخه قبلی از قبل
 * در حافظه PHP بارگذاری شده است (و ممکن است فاقد متدهای جدید مثل columnExists باشد)،
 * در حالی که Migrator و SchemaBuilder جدید از دیسک خوانده می‌شوند.
 * بنابراین این کلاس هیچ وابستگی بدون بررسی (method_exists) به متدهای جدید Database ندارد
 * و تمام بررسی‌های جدول، ستون و ایندکس را با پشتیبان مستقیم PDO انجام می‌دهد.
 */
final class Migrator
{
    private Database $db;
    private string $migrationsDir;

    public function __construct(?Database $db = null, ?string $migrationsDir = null)
    {
        $this->db = $db ?? Database::instance();
        $this->migrationsDir = rtrim($migrationsDir ?? (RB_ROOT . '/database/migrations'), '/\\');
    }

    /** @return string[] نام migrationهای اجراشده */
    public function applied(): array
    {
        if (!$this->hasTable('migrations')) {
            return [];
        }
        try {
            $stmt = $this->db->pdo()->query('SELECT migration FROM migrations ORDER BY migration');
            if ($stmt === false) {
                return [];
            }
            $out = [];
            while (($val = $stmt->fetchColumn()) !== false) {
                if (is_string($val) && $val !== '') {
                    $out[] = $val;
                }
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * اجرای زنجیره‌ای و خودکار تمام Migrationهای اعمال‌نشده به ترتیب شماره فایل
     * @return array{applied:string[],skipped:string[]}
     */
    public function run(): array
    {
        // تضمین utf8mb4 روی نشست و دیتابیس (با بررسی وجود متد برای سازگاری با نسخه‌های قدیمی در حافظه)
        if (method_exists($this->db, 'ensureDatabaseUtf8mb4')) {
            $this->db->ensureDatabaseUtf8mb4();
        }

        $applied = $this->applied();
        $files = glob($this->migrationsDir . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $newlyApplied = [];
        $skipped = [];
        $knownTables = ['migrations'];
        $driver = method_exists($this->db, 'driver')
            ? $this->db->driver()
            : ($this->isSqlite() ? 'sqlite' : 'mysql');

        foreach ($files as $file) {
            $name = basename($file);
            $schema = new SchemaBuilder($driver);
            $migration = require $file;
            if (!is_callable($migration)) {
                throw new \RuntimeException('Migration معتبر نیست: ' . $name);
            }
            $migration($schema);
            if (method_exists($schema, 'tableNames')) {
                foreach ($schema->tableNames() as $tName) {
                    $knownTables[] = $tName;
                }
            }

            if (in_array($name, $applied, true)) {
                $skipped[] = $name;
                continue;
            }

            foreach ($schema->statements() as $sql) {
                $this->executeStatement($sql, $name);
            }

            if (method_exists($schema, 'runAfter')) {
                $schema->runAfter($this->db);
            }
            $this->ensureMigrationsTable($driver);
            $this->recordMigration($name, $driver);
            $applied[] = $name;
            $newlyApplied[] = $name;
        }

        // یکسان‌سازی charset/collation جدول‌های برنامه در صورت پشتیبانی نسخه Database در حافظه
        if (method_exists($this->db, 'ensureTablesUtf8mb4')) {
            $this->db->ensureTablesUtf8mb4(array_values(array_unique($knownTables)));
        }

        // همگام‌سازی غیرمخرب داده‌های قبلی هنگام ارتقای مستقیم از v1.2/v1.3/v1.4 (فقط درج رکوردهای مکمل، بدون تغییر داده‌های موجود)
        if ($newlyApplied !== []) {
            $this->syncLegacyDataSafely($driver);
        }

        return ['applied' => $newlyApplied, 'skipped' => $skipped];
    }

    /** اجرای ایمن و Idempotent یک دستور DDL با محافظت در برابر ستون/ایندکس/جدول از قبل موجود */
    private function executeStatement(string $sql, string $migrationName): void
    {
        try {
            $existingIndex = $this->existingIndexName($sql);
            if ($existingIndex !== null) {
                if (!$this->hasTable($existingIndex[0]) || $this->hasIndex($existingIndex[0], $existingIndex[1])) {
                    return;
                }
            }

            $existingCol = $this->existingAddedColumn($sql);
            if ($existingCol !== null) {
                if (!$this->hasTable($existingCol[0])) {
                    throw new \RuntimeException(
                        'جدول پیش‌نیاز «' . $existingCol[0] . '» برای افزودن ستون «' . $existingCol[1] . '» در Migration ' . $migrationName . ' یافت نشد.'
                    );
                }
                if ($this->hasColumn($existingCol[0], $existingCol[1])) {
                    return;
                }
            }

            $this->db->pdo()->exec($sql);
        } catch (\PDOException $e) {
            if ($this->isBenignDuplicateDdlError($e, $sql)) {
                return;
            }
            throw new \RuntimeException(
                'خطا در اجرای Migration ' . $migrationName . ': ' . $this->redactMessage($e->getMessage()),
                0,
                $e
            );
        }
    }

    /** تشخیص خطاهای تکراری بی‌خطر DDL (اگر ستون یا ایندکس در تلاش قبلی ساخته شده باشد) */
    private function isBenignDuplicateDdlError(\PDOException $e, string $sql): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $msg = strtolower($e->getMessage());
        $trimmed = ltrim($sql);

        // Duplicate column name (MySQL/MariaDB 1060 / SQLSTATE 42S21 / SQLite)
        if (stripos($trimmed, 'ALTER TABLE') === 0 && (
            $driverCode === 1060
            || $sqlState === '42S21'
            || str_contains($msg, 'duplicate column name')
        )) {
            return true;
        }

        // Duplicate index / key name (MySQL/MariaDB 1061 / SQLite)
        if (preg_match('~^CREATE\s+(?:UNIQUE\s+)?INDEX\b~i', $trimmed) && (
            $driverCode === 1061
            || str_contains($msg, 'duplicate key name')
            || (str_contains($msg, 'index') && str_contains($msg, 'already exists'))
        )) {
            return true;
        }

        // Table already exists (MySQL/MariaDB 1050 / SQLite)
        if (stripos($trimmed, 'CREATE TABLE') === 0 && (
            $driverCode === 1050
            || (str_contains($msg, 'table') && str_contains($msg, 'already exists'))
        )) {
            return true;
        }

        return false;
    }

    private function ensureMigrationsTable(string $driver): void
    {
        if ($this->hasTable('migrations')) {
            return;
        }
        $this->db->pdo()->exec(
            $driver === 'sqlite'
                ? 'CREATE TABLE IF NOT EXISTS migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL UNIQUE, applied_at TEXT NOT NULL)'
                : 'CREATE TABLE IF NOT EXISTS `migrations` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `migration` VARCHAR(191) NOT NULL, `applied_at` DATETIME NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `uk_migration` (`migration`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function recordMigration(string $name, string $driver): void
    {
        $sql = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO migrations (migration, applied_at) VALUES (:m, :a)'
            : 'INSERT IGNORE INTO `migrations` (`migration`, `applied_at`) VALUES (:m, :a)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            ':m' => $name,
            ':a' => date('Y-m-d H:i:s'),
        ]);
    }

    private function isSqlite(): bool
    {
        if (method_exists($this->db, 'isSqlite')) {
            return $this->db->isSqlite();
        }
        return strtolower((string) $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME)) === 'sqlite';
    }

    /** بررسی مستقل وجود جدول (بدون خطا روی هر نسخه قدیمی Database در حافظه) */
    public function hasTable(string $table): bool
    {
        if (!preg_match('~^[A-Za-z0-9_]{1,64}$~', $table)) {
            return false;
        }
        if (method_exists($this->db, 'tableExists')) {
            return $this->db->tableExists($table);
        }
        return self::pdoTableExists($this->db->pdo(), $this->isSqlite(), $table);
    }

    /** بررسی مستقل وجود ایندکس (بدون خطا روی هر نسخه قدیمی Database در حافظه) */
    public function hasIndex(string $table, string $index): bool
    {
        if (!preg_match('~^[A-Za-z0-9_]{1,64}$~', $table) || !preg_match('~^[A-Za-z0-9_]{1,64}$~', $index)) {
            return false;
        }
        if (method_exists($this->db, 'indexExists')) {
            return $this->db->indexExists($table, $index);
        }
        return self::pdoIndexExists($this->db->pdo(), $this->isSqlite(), $table, $index);
    }

    /**
     * بررسی مستقل وجود ستون (رفع ریشه‌ای خطای Call to undefined method Database::columnExists
     * هنگام ارتقای زنده از v1.2.0 که نسخه قدیمی Database را در حافظه دارد)
     */
    public function hasColumn(string $table, string $column): bool
    {
        if (!preg_match('~^[A-Za-z0-9_]{1,64}$~', $table) || !preg_match('~^[A-Za-z0-9_]{1,64}$~', $column)) {
            return false;
        }
        if (method_exists($this->db, 'columnExists')) {
            return $this->db->columnExists($table, $column);
        }
        return self::pdoColumnExists($this->db->pdo(), $this->isSqlite(), $table, $column);
    }

    /** بررسی وجود جدول با PDO خالص (سازگار با MySQL 8، MariaDB 10.3+ و SQLite 3) */
    public static function pdoTableExists(\PDO $pdo, bool $isSqlite, string $table): bool
    {
        if (!preg_match('~^[A-Za-z0-9_]{1,64}$~', $table)) {
            return false;
        }
        try {
            if ($isSqlite) {
                $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :t LIMIT 1");
                $stmt->execute([':t' => $table]);
                return $stmt->fetchColumn() !== false;
            }
            $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $table);
            $quoted = $pdo->quote($like);
            if ($quoted === false) {
                $quoted = "'" . str_replace("'", "''", $like) . "'";
            }
            $stmt = $pdo->query('SHOW TABLES LIKE ' . $quoted);
            if ($stmt === false) {
                return false;
            }
            while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
                if (isset($row[0]) && strcasecmp((string) $row[0], $table) === 0) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** بررسی وجود ستون با PDO خالص (SHOW COLUMNS در MySQL بدون تداخل collation در information_schema) */
    public static function pdoColumnExists(\PDO $pdo, bool $isSqlite, string $table, string $column): bool
    {
        if (!preg_match('~^[A-Za-z0-9_]{1,64}$~', $table) || !preg_match('~^[A-Za-z0-9_]{1,64}$~', $column)) {
            return false;
        }
        if (!self::pdoTableExists($pdo, $isSqlite, $table)) {
            return false;
        }
        try {
            if ($isSqlite) {
                $stmt = $pdo->query('PRAGMA table_info("' . $table . '")');
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
            $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $quoted);
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
        } catch (\Throwable) {
            return false;
        }
    }

    /** بررسی وجود ایندکس با PDO خالص */
    public static function pdoIndexExists(\PDO $pdo, bool $isSqlite, string $table, string $index): bool
    {
        if (!preg_match('~^[A-Za-z0-9_]{1,64}$~', $table) || !preg_match('~^[A-Za-z0-9_]{1,64}$~', $index)) {
            return false;
        }
        try {
            if ($isSqlite) {
                $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'index' AND name = :i LIMIT 1");
                $stmt->execute([':i' => $index]);
                return $stmt->fetchColumn() !== false;
            }
            $stmt = $pdo->query('SHOW INDEX FROM `' . $table . '`');
            if ($stmt === false) {
                return false;
            }
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $keyName = (string) ($row['Key_name'] ?? $row['key_name'] ?? '');
                if ($keyName !== '' && strcasecmp($keyName, $index) === 0) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * انتقال غیرمخرب تنظیمات اتصال تلگرام و نگاشت حساب کاربران قبلی پس از ارتقا از v1.2/v1.3/v1.4.
     * فقط از PDO خالص و INSERT غیرمخرب استفاده می‌کند و هیچ داده مالی، کاربری یا رفرال را تغییر نمی‌دهد.
     */
    private function syncLegacyDataSafely(string $driver): void
    {
        $pdo = $this->db->pdo();
        $isSqlite = ($driver === 'sqlite');
        $now = date('Y-m-d H:i:s');

        // ۱) انتقال اتصال موجود ربات تلگرام از telegram_settings به messenger_connections (فقط اگر هنوز ثبت نشده باشد)
        try {
            if (
                self::pdoTableExists($pdo, $isSqlite, 'telegram_settings')
                && self::pdoTableExists($pdo, $isSqlite, 'messenger_connections')
            ) {
                $tgStmt = $pdo->query('SELECT * FROM telegram_settings ORDER BY id ASC LIMIT 1');
                $tg = $tgStmt !== false ? $tgStmt->fetch(\PDO::FETCH_ASSOC) : false;

                if (is_array($tg) && !empty($tg['bot_token_enc'])) {
                    $chk = $pdo->prepare("SELECT id, bot_token_enc FROM messenger_connections WHERE platform = 'telegram' LIMIT 1");
                    $chk->execute();
                    $existing = $chk->fetch(\PDO::FETCH_ASSOC);

                    $wasConnected = (($tg['connection_status'] ?? '') === 'connected')
                        || !empty($tg['webhook_active'])
                        || !empty($tg['bot_username']);
                    $connStatus = $wasConnected ? 'connected' : (string) ($tg['connection_status'] ?? 'configured');
                    $secret = !empty($tg['webhook_secret']) ? (string) $tg['webhook_secret'] : bin2hex(random_bytes(24));

                    if (!is_array($existing)) {
                        $ins = $pdo->prepare(
                            'INSERT INTO messenger_connections
                             (platform, display_name, is_enabled, bot_token_enc, webhook_secret, webhook_url, webhook_status,
                              webhook_info, polling_enabled, bot_id, bot_username, bot_name, bot_short_description,
                              bot_description, bot_photo_file_id, bot_photo_path, profile_synced_at, connection_status,
                              last_error, last_test_at, last_update_at, updates_received, messages_sent, messages_failed,
                              menu_synced, extra_config, created_at, updated_at)
                             VALUES
                             (:platform, :display_name, :is_enabled, :bot_token_enc, :webhook_secret, :webhook_url, :webhook_status,
                              :webhook_info, 0, :bot_id, :bot_username, :bot_name, :bot_short_description,
                              :bot_description, :bot_photo_file_id, :bot_photo_path, :profile_synced_at, :connection_status,
                              :last_error, :last_test_at, :last_update_at, :updates_received, :messages_sent, :messages_failed,
                              0, :extra_config, :created_at, :updated_at)'
                        );
                        $ins->execute([
                            ':platform' => 'telegram',
                            ':display_name' => 'تلگرام',
                            ':is_enabled' => 1,
                            ':bot_token_enc' => (string) $tg['bot_token_enc'],
                            ':webhook_secret' => $secret,
                            ':webhook_url' => $tg['webhook_url'] ?? null,
                            ':webhook_status' => !empty($tg['webhook_active']) ? 'active' : 'not_set',
                            ':webhook_info' => (string) ($tg['webhook_info'] ?? ''),
                            ':bot_id' => !empty($tg['bot_id']) ? (string) $tg['bot_id'] : null,
                            ':bot_username' => $tg['bot_username'] ?? null,
                            ':bot_name' => $tg['bot_name'] ?? null,
                            ':bot_short_description' => $tg['bot_short_description'] ?? null,
                            ':bot_description' => $tg['bot_description'] ?? null,
                            ':bot_photo_file_id' => $tg['bot_photo_file_id'] ?? null,
                            ':bot_photo_path' => $tg['bot_photo_path'] ?? null,
                            ':profile_synced_at' => $tg['profile_synced_at'] ?? null,
                            ':connection_status' => $connStatus,
                            ':last_error' => (string) ($tg['last_error'] ?? ''),
                            ':last_test_at' => $tg['last_test_at'] ?? null,
                            ':last_update_at' => $tg['last_update_at'] ?? null,
                            ':updates_received' => (int) ($tg['updates_received'] ?? 0),
                            ':messages_sent' => (int) ($tg['messages_sent'] ?? 0),
                            ':messages_failed' => (int) ($tg['messages_failed'] ?? 0),
                            ':extra_config' => '{}',
                            ':created_at' => $now,
                            ':updated_at' => $now,
                        ]);
                    } elseif (empty($existing['bot_token_enc'])) {
                        $upd = $pdo->prepare(
                            'UPDATE messenger_connections
                             SET is_enabled = 1, bot_token_enc = :tok, webhook_secret = :sec, webhook_url = :wurl,
                                 webhook_status = :wst, bot_id = :bid, bot_username = :bun, bot_name = :bname,
                                 connection_status = :cst, updated_at = :uat
                             WHERE id = :id'
                        );
                        $upd->execute([
                            ':tok' => (string) $tg['bot_token_enc'],
                            ':sec' => $secret,
                            ':wurl' => $tg['webhook_url'] ?? null,
                            ':wst' => !empty($tg['webhook_active']) ? 'active' : 'not_set',
                            ':bid' => !empty($tg['bot_id']) ? (string) $tg['bot_id'] : null,
                            ':bun' => $tg['bot_username'] ?? null,
                            ':bname' => $tg['bot_name'] ?? null,
                            ':cst' => $connStatus,
                            ':uat' => $now,
                            ':id' => (int) $existing['id'],
                        ]);
                    }
                }
            }
        } catch (\Throwable) {
            // همگام‌سازی تکمیلی در اولین اجرای MessengerRegistry نیز انجام می‌شود
        }

        // ۲) نگاشت کامل و بدون افت تمام کاربران تلگرام قبلی به جدول user_messenger_accounts
        try {
            if (
                self::pdoTableExists($pdo, $isSqlite, 'users')
                && self::pdoTableExists($pdo, $isSqlite, 'user_messenger_accounts')
            ) {
                $insSql = $isSqlite
                    ? 'INSERT OR IGNORE INTO user_messenger_accounts
                       (user_id, platform, external_user_id, external_chat_id, username, first_name, last_name,
                        language_code, can_send_message, is_blocked, is_primary, verified_via, meta,
                        first_seen_at, last_seen_at, created_at, updated_at)
                       VALUES
                       (:uid, :plat, :eid, :cid, :un, :fn, :ln, :lang, :can, :blk, 1, :via, :meta, :fseen, :lseen, :cat, :uat)'
                    : 'INSERT IGNORE INTO `user_messenger_accounts`
                       (`user_id`, `platform`, `external_user_id`, `external_chat_id`, `username`, `first_name`, `last_name`,
                        `language_code`, `can_send_message`, `is_blocked`, `is_primary`, `verified_via`, `meta`,
                        `first_seen_at`, `last_seen_at`, `created_at`, `updated_at`)
                       VALUES
                       (:uid, :plat, :eid, :cid, :un, :fn, :ln, :lang, :can, :blk, 1, :via, :meta, :fseen, :lseen, :cat, :uat)';
                $insStmt = $pdo->prepare($insSql);

                // پردازش دسته‌ای تا سقف ۱۰۰ دسته ۵۰۰تایی برای پشتیبانی ایمن از پایگاه‌داده‌های بزرگ
                for ($batch = 0; $batch < 100; $batch++) {
                    $q = $pdo->query(
                        "SELECT u.id, u.telegram_id, u.username, u.first_name, u.last_name, u.language, u.status, u.last_activity_at, u.created_at
                         FROM users u
                         LEFT JOIN user_messenger_accounts a ON a.platform = 'telegram' AND a.user_id = u.id
                         WHERE u.telegram_id > 0 AND a.id IS NULL
                         ORDER BY u.id ASC
                         LIMIT 500"
                    );
                    $rows = $q !== false ? $q->fetchAll(\PDO::FETCH_ASSOC) : [];
                    if ($rows === []) {
                        break;
                    }
                    foreach ($rows as $u) {
                        $tid = (string) $u['telegram_id'];
                        $blocked = (($u['status'] ?? 'active') === 'blocked') ? 1 : 0;
                        $created = (string) ($u['created_at'] ?: $now);
                        $insStmt->execute([
                            ':uid' => (int) $u['id'],
                            ':plat' => 'telegram',
                            ':eid' => $tid,
                            ':cid' => $tid,
                            ':un' => $u['username'] ?? null,
                            ':fn' => $u['first_name'] ?? null,
                            ':ln' => $u['last_name'] ?? null,
                            ':lang' => (string) ($u['language'] ?? 'fa') ?: 'fa',
                            ':can' => $blocked ? 0 : 1,
                            ':blk' => $blocked,
                            ':via' => 'legacy_telegram',
                            ':meta' => '{}',
                            ':fseen' => $created,
                            ':lseen' => (string) ($u['last_activity_at'] ?: $created),
                            ':cat' => $created,
                            ':uat' => $now,
                        ]);
                    }
                    if (count($rows) < 500) {
                        break;
                    }
                }
            }
        } catch (\Throwable) {
        }
    }

    private function redactMessage(string $message): string
    {
        if (class_exists(ErrorHandler::class) && method_exists(ErrorHandler::class, 'redact')) {
            return ErrorHandler::redact($message);
        }
        return $message;
    }

    /**
     * استخراج (جدول، ایندکس) از یک دستور CREATE INDEX تا بتوان تکرار آن را رد کرد.
     * @return array{0:string,1:string}|null
     */
    private function existingIndexName(string $sql): ?array
    {
        if (!preg_match('~^CREATE\s+(?:UNIQUE\s+)?INDEX\s+[`"]([^`"]+)[`"]\s+ON\s+[`"]([^`"]+)[`"]~i', trim($sql), $m)) {
            return null;
        }
        return [$m[2], $m[1]];
    }

    /**
     * استخراج (جدول، ستون) از یک دستور ALTER TABLE ... ADD COLUMN برای idempotent بودن Migrationها.
     * @return array{0:string,1:string}|null
     */
    private function existingAddedColumn(string $sql): ?array
    {
        if (!preg_match('~^ALTER\s+TABLE\s+[`"]([A-Za-z0-9_]+)[`"]\s+ADD\s+COLUMN\s+[`"]([A-Za-z0-9_]+)[`"]~i', trim($sql), $m)) {
            return null;
        }
        return [$m[1], $m[2]];
    }
}

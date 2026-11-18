<?php
declare(strict_types=1);

namespace RB\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * لایه دسترسی به داده — PDO با پشتیبانی MySQL (هاست) و SQLite (تست/توسعه)
 * تمام کوئری‌ها با Prepared Statement اجرا می‌شوند (ضد SQL Injection)
 */
final class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo = null;
    private int $txLevel = 0;
    private bool $emulatePrepares = false;
    private array $config;

    /** مجموعه کاراکتر و Collation واحد در کل سامانه (پشتیبانی کامل فارسی + ایموجی ۴ بایتی) */
    public const MYSQL_CHARSET = 'utf8mb4';
    public const MYSQL_COLLATION = 'utf8mb4_unicode_ci';

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? [
            'driver' => Env::get('DB_DRIVER', 'mysql'),
            'host' => Env::get('DB_HOST', 'localhost'),
            'port' => Env::get('DB_PORT', '3306'),
            'database' => Env::get('DB_DATABASE', ''),
            'username' => Env::get('DB_USERNAME', ''),
            'password' => Env::get('DB_PASSWORD', ''),
        ];
    }

    public static function instance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function setInstance(?Database $db): void
    {
        self::$instance = $db;
    }

    public function driver(): string
    {
        return $this->config['driver'] ?? 'mysql';
    }

    public function isSqlite(): bool
    {
        return $this->driver() === 'sqlite';
    }

    public function config(): array
    {
        return $this->config;
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            if ($this->isSqlite()) {
                $path = $this->config['database'] ?? ':memory:';
                if ($path !== ':memory:' && !is_dir(dirname($path))) {
                    @mkdir(dirname($path), 0775, true);
                }
                $this->pdo = new PDO('sqlite:' . $path, null, null, $options);
                $this->pdo->exec('PRAGMA journal_mode = WAL');
                $this->pdo->exec('PRAGMA foreign_keys = ON');
                $this->pdo->exec('PRAGMA busy_timeout = 8000');
            } else {
                $port = (string) ($this->config['port'] ?? '');
                $port = $port !== '' ? $port : '3306';
                $dbName = (string) ($this->config['database'] ?? '');
                $dsn = $dbName !== ''
                    ? sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $this->config['host'], $port, $dbName, self::MYSQL_CHARSET)
                    : sprintf('mysql:host=%s;port=%s;charset=%s', $this->config['host'], $port, self::MYSQL_CHARSET);

                // روی برخی هاست‌های اشتراکی (cPanel/DirectAdmin با MySQL 8)، تنظیم
                // skip-character-set-client-handshake یا init_connect='SET NAMES utf8'
                // باعث می‌شود charset داخل DSN نادیده گرفته شود و character_set_client
                // روی utf8mb3_general_ci بماند؛ در نتیجه هنگام درج متن دارای ایموجی
                // ۴ بایتی (مثل تنظیمات اولیه و منوهای ربات در مرحلهٔ نصب) خطای
                // SQLSTATE[HY000]: 3988 (Conversion from utf8mb3_general_ci into
                // utf8mb4_general_ci impossible for parameter) رخ می‌دهد.
                $mysqlOptions = $options;
                if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                    $mysqlOptions[PDO::MYSQL_ATTR_INIT_COMMAND] = sprintf(
                        "SET NAMES '%s' COLLATE '%s'",
                        self::MYSQL_CHARSET,
                        self::MYSQL_COLLATION
                    );
                }
                if ($this->emulatePrepares) {
                    $mysqlOptions[PDO::ATTR_EMULATE_PREPARES] = true;
                }

                try {
                    $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], $mysqlOptions);
                } catch (PDOException $initErr) {
                    // اگر پروکسی خاصی MYSQL_ATTR_INIT_COMMAND را نپذیرفت، بدون آن وصل شویم و بلافاصله SET NAMES بزنیم
                    if (!isset($mysqlOptions[PDO::MYSQL_ATTR_INIT_COMMAND])) {
                        throw $initErr;
                    }
                    $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], $options);
                }

                $this->applyUtf8mb4(true);

                // تنظیم sql_mode نباید هرگز اتصال را بشکند. بعضی هاست‌های
                // اشتراکی اجازهٔ تغییر SESSION sql_mode را نمی‌دهند و خطای
                // «Access denied» می‌دهند؛ در آن حالت به تنظیمات پیش‌فرض
                // سرور تکیه می‌کنیم (کد ما با ONLY_FULL_GROUP_BY هم سازگار است).
                try {
                    $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
                } catch (\PDOException $e) {
                    ErrorHandler::write('warning', 'sql_mode تنظیم نشد (بی‌خطر): ' . $e->getMessage());
                }
            }
        } catch (PDOException $e) {
            throw new RuntimeException('اتصال به پایگاه داده ناموفق بود: ' . ErrorHandler::redact($e->getMessage()), 0, $e);
        }

        return $this->pdo;
    }

    /**
     * تضمین utf8mb4 روی تمام متغیرهای نشست MySQL/MariaDB.
     *
     * هم SET NAMES (برای سازگاری با ProxySQL / MySQL Governor) و هم تنظیم صریح
     * character_set_client / character_set_connection / character_set_results /
     * collation_connection اجرا می‌شود تا خطای 3988 و 1267 رخ ندهد.
     */
    public function applyUtf8mb4(bool $explicitSessionVars = false): void
    {
        if ($this->isSqlite() || !($this->pdo instanceof PDO)) {
            return;
        }
        try {
            $this->pdo->exec(sprintf("SET NAMES '%s' COLLATE '%s'", self::MYSQL_CHARSET, self::MYSQL_COLLATION));
        } catch (PDOException) {
            try {
                $this->pdo->exec(sprintf("SET NAMES '%s'", self::MYSQL_CHARSET));
            } catch (PDOException $e) {
                ErrorHandler::write('warning', 'SET NAMES utf8mb4 تنظیم نشد (بی‌خطر): ' . $e->getMessage());
            }
        }
        if ($explicitSessionVars) {
            try {
                $this->pdo->exec(sprintf(
                    "SET SESSION character_set_client = '%1\$s', character_set_connection = '%1\$s', character_set_results = '%1\$s', collation_connection = '%2\$s'",
                    self::MYSQL_CHARSET,
                    self::MYSQL_COLLATION
                ));
            } catch (PDOException) {
                // بی‌خطر؛ SET NAMES در خط بالا کافی است
            }
        }
    }

    public function run(string $sql, array $params = []): \PDOStatement
    {
        $pdo = $this->pdo();
        try {
            return $this->prepareAndExecute($pdo, $sql, $params);
        } catch (PDOException $e) {
            if ($this->isSqlite() || !self::isCollationOrCharsetError($e)) {
                throw $e;
            }
            return $this->recoverCharsetAndRetry($pdo, $sql, $params, $e);
        }
    }

    private function prepareAndExecute(PDO $pdo, string $sql, array $params): \PDOStatement
    {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with((string) $key, ':') ? (string) $key : ':' . $key);
            if (is_bool($value)) {
                $stmt->bindValue($name, $value ? 1 : 0, PDO::PARAM_INT);
            } elseif (is_int($value)) {
                $stmt->bindValue($name, $value, PDO::PARAM_INT);
            } elseif ($value === null) {
                $stmt->bindValue($name, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($name, (string) $value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        return $stmt;
    }

    /**
     * تشخیص خطاهای تبدیل charset/collation در MySQL 8 و MariaDB
     *   - 3988: Conversion from collation X into Y impossible for parameter
     *   - 1267/1270/1271: Illegal mix of collations
     *   - 1366: Incorrect string value (مثلاً ایموجی ۴ بایتی روی ستون utf8mb3)
     */
    public static function isCollationOrCharsetError(PDOException $e): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $msg = $e->getMessage();
        if (in_array($driverCode, [3988, 1267, 1270, 1271], true)) {
            return true;
        }
        if ($driverCode === 1366 && stripos($msg, 'Incorrect string value') !== false) {
            return true;
        }
        return (bool) preg_match('~\b(3988|Conversion from collation|Illegal mix of collations)\b~i', $msg);
    }

    /**
     * بازیابی خودکار در برابر خطای 3988 / 1267 / 1366 روی هاست‌های MySQL 8:
     *   ۱) اعمال مجدد SET NAMES utf8mb4 + متغیرهای نشست (برای ProxySQL و init_connect)
     *   ۲) اگر ستون مقصد utf8mb3/latin1 مانده و بیرون از تراکنش هستیم، تبدیل امن جدول به utf8mb4
     *   ۳) تلاش مجدد با Prepared Statement بومی
     *   ۴) اگر کلاینت C سرور (libmysqlclient 8.0.26+ با [client] default-character-set=utf8)
     *      همچنان در پروتکل باینری COM_STMT_EXECUTE پارامتر را utf8mb3 برچسب بزند و خطای 3988
     *      بدهد، فعال‌سازی Emulated Prepares روی اتصال utf8mb4 (ایمن با mysql_real_escape_string)
     */
    private function recoverCharsetAndRetry(PDO $pdo, string $sql, array $params, PDOException $firstError): \PDOStatement
    {
        $this->applyUtf8mb4(true);

        $msg = $firstError->getMessage();
        $driverCode = (int) ($firstError->errorInfo[1] ?? 0);
        $legacyTarget = $driverCode === 1366
            || (bool) preg_match('~into\s+(?:collation\s+)?(?:utf8mb3|utf8_|latin1)~i', $msg);

        if ($legacyTarget && !$this->inTransaction()) {
            $targetTable = self::extractWriteTable($sql);
            if ($targetTable !== null) {
                $this->convertTableToUtf8mb4($targetTable);
            }
        }

        try {
            return $this->prepareAndExecute($pdo, $sql, $params);
        } catch (PDOException $secondError) {
            $code2 = (int) ($secondError->errorInfo[1] ?? 0);
            $is3988 = $code2 === 3988 || stripos($secondError->getMessage(), 'Conversion from collation') !== false;
            if (!$is3988 || $this->emulatePrepares) {
                throw $secondError;
            }
            // عقب‌نشینی امن به Emulated Prepares بعد از تثبیت SET NAMES utf8mb4
            $this->applyUtf8mb4(true);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
            $this->emulatePrepares = true;
            return $this->prepareAndExecute($pdo, $sql, $params);
        }
    }

    /** استخراج نام جدول مقصد در INSERT/UPDATE (فقط شناسه‌های معتبر پروژه) */
    private static function extractWriteTable(string $sql): ?string
    {
        if (preg_match('~^\s*(?:INSERT(?:\s+IGNORE)?\s+INTO|UPDATE)\s+`?([a-z0-9_]{1,64})`?\b~i', $sql, $m)) {
            $table = strtolower($m[1]);
            if ($table !== 'information_schema') {
                return $table;
            }
        }
        return null;
    }

    /** تبدیل امن یک جدول به utf8mb4_unicode_ci (فقط خارج از تراکنش) */
    public function convertTableToUtf8mb4(string $table): bool
    {
        if ($this->isSqlite() || $this->inTransaction() || !preg_match('~^[a-z0-9_]{1,64}$~', $table)) {
            return false;
        }
        try {
            $this->pdo()->exec(sprintf(
                'ALTER TABLE `%s` CONVERT TO CHARACTER SET %s COLLATE %s',
                $table,
                self::MYSQL_CHARSET,
                self::MYSQL_COLLATION
            ));
            return true;
        } catch (\Throwable $e) {
            ErrorHandler::write('warning', 'تبدیل collation جدول ' . $table . ' ممکن نشد: ' . $e->getMessage());
            return false;
        }
    }

    /** تنظیم پیش‌فرض دیتابیس به utf8mb4_unicode_ci (در صورت داشتن مجوز ALTER) */
    public function ensureDatabaseUtf8mb4(): void
    {
        if ($this->isSqlite() || $this->inTransaction()) {
            return;
        }
        $this->applyUtf8mb4(true);
        $dbName = (string) ($this->config['database'] ?? '');
        if ($dbName === '' || !preg_match('~^[A-Za-z0-9_$]{1,64}$~', $dbName)) {
            return;
        }
        try {
            $stmt = $this->pdo()->query('SELECT @@collation_database');
            $collation = $stmt !== false ? (string) ($stmt->fetchColumn() ?: '') : '';
            if ($collation !== '' && strtolower($collation) !== self::MYSQL_COLLATION) {
                $safe = str_replace('`', '', $dbName);
                $this->pdo()->exec(sprintf(
                    'ALTER DATABASE `%s` CHARACTER SET %s COLLATE %s',
                    $safe,
                    self::MYSQL_CHARSET,
                    self::MYSQL_COLLATION
                ));
            }
        } catch (\Throwable) {
            // روی هاست اشتراکی بدون مجوز ALTER DATABASE بی‌صدا رد می‌شود
        }
    }

    /**
     * یکسان‌سازی charset/collation جدول‌های خود پروژه به utf8mb4_unicode_ci.
     * از SHOW TABLE STATUS استفاده می‌کند تا درگیر utf8mb3 بودن information_schema نشود
     * و هرگز به جدول‌های متفرقه در دیتابیس مشترک دست نمی‌زند.
     *
     * @param string[] $appTables
     */
    public function ensureTablesUtf8mb4(array $appTables): void
    {
        if ($this->isSqlite() || $this->inTransaction() || $appTables === []) {
            return;
        }
        $allowed = [];
        foreach ($appTables as $t) {
            if (is_string($t) && preg_match('~^[a-z0-9_]{1,64}$~', $t)) {
                $allowed[strtolower($t)] = $t;
            }
        }
        if ($allowed === []) {
            return;
        }

        $toConvert = [];
        $pdo = $this->pdo();
        try {
            $stmt = $pdo->query('SHOW TABLE STATUS');
            $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as $row) {
                $name = strtolower((string) ($row['Name'] ?? $row['name'] ?? ''));
                // رد کردن VIEWها (در SHOW TABLE STATUS مقدار Engine و Collation برای VIEW تهی است)
                $coll = strtolower((string) ($row['Collation'] ?? $row['collation'] ?? ''));
                if ($name === '' || !isset($allowed[$name]) || $coll === '') {
                    continue;
                }
                if ($coll !== self::MYSQL_COLLATION) {
                    $toConvert[$name] = $allowed[$name];
                    continue;
                }
                // بررسی ستون‌های متنی احتمالی که با utf8mb3/latin1 ساخته شده باشند
                $colStmt = $pdo->query('SHOW FULL COLUMNS FROM ' . $this->quoteIdent($allowed[$name]));
                $cols = $colStmt !== false ? $colStmt->fetchAll(PDO::FETCH_ASSOC) : [];
                foreach ($cols as $col) {
                    $cColl = strtolower((string) ($col['Collation'] ?? $col['collation'] ?? ''));
                    if ($cColl !== '' && !str_starts_with($cColl, 'utf8mb4_')) {
                        $toConvert[$name] = $allowed[$name];
                        break;
                    }
                }
            }
        } catch (\Throwable) {
            return;
        }

        if ($toConvert === []) {
            return;
        }

        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        } catch (\Throwable) {
        }
        try {
            foreach ($toConvert as $table) {
                $this->convertTableToUtf8mb4($table);
            }
        } finally {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (\Throwable) {
            }
        }
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row === false ? $default : ($row[0] ?? $default);
    }

    public function column(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO ' . $this->quoteIdent($table) . ' (' . implode(', ', array_map([$this, 'quoteIdent'], $cols)) . ')'
            . ' VALUES (' . implode(', ', array_map(static fn($c) => ':' . $c, $cols)) . ')';
        $this->run($sql, $data);
        return (int) $this->pdo()->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = $this->quoteIdent($col) . ' = :set_' . $col;
        }
        $params = [];
        foreach ($data as $k => $v) {
            $params['set_' . $k] = $v;
        }
        foreach ($whereParams as $k => $v) {
            $params[ltrim((string) $k, ':')] = $v;
        }
        $sql = 'UPDATE ' . $this->quoteIdent($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        return $this->run($sql, $params)->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        return $this->run('DELETE FROM ' . $this->quoteIdent($table) . ' WHERE ' . $where, $params)->rowCount();
    }

    /**
     * درج در صورت نبود کلید یکتا (سازگار با MySQL و SQLite)
     * @param string|string[] $uniqueColumns ستون یا ستون‌های یکتای مورد بررسی
     */
    public function insertIgnore(string $table, array $data, string|array $uniqueColumns): bool
    {
        $columns = is_array($uniqueColumns) ? $uniqueColumns : [$uniqueColumns];
        $conditions = [];
        $params = [];
        foreach ($columns as $i => $column) {
            $conditions[] = $this->quoteIdent($column) . ' = :u' . $i;
            $params['u' . $i] = $data[$column] ?? null;
        }
        // مسابقهٔ همزمانی: در MySQL دو درخواست هم‌زمان می‌توانند هر دو «وجود
        // ندارد» ببینند و دومی خطای Duplicate key بدهد. برای اینکه رفتار
        // «درج بی‌صدا» تضمین شود، خطای 1062 را می‌بلعیم و false برمی‌گردانیم.
        $existing = $this->value(
            'SELECT 1 FROM ' . $this->quoteIdent($table) . ' WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1',
            $params
        );
        if ($existing !== null) {
            return false;
        }
        try {
            $this->insert($table, $data);
        } catch (\PDOException $e) {
            // 23000 = نقض قید یکتایی (هم در MySQL و هم SQLite)
            if (($e->getCode() === '23000' || (int) ($e->errorInfo[1] ?? 0) === 1062)) {
                return false;
            }
            throw $e;
        }
        return true;
    }

    /** نقل‌قول امن مقدار رشته‌ای برای استفاده در دامپ SQL */
    public function quote(string $value): string
    {
        $pdo = $this->pdo();
        $quoted = $pdo->quote($value);
        return $quoted === false ? "'" . str_replace("'", "''", $value) . "'" : $quoted;
    }

    public function quoteIdent(string $name): string
    {
        return '`' . str_replace('`', '', $name) . '`';
    }

    /**
     * فهرست نام جدول‌های دیتابیس فعلی.
     * در MySQL از SHOW FULL TABLES استفاده می‌شود (سریع روی هاست اشتراکی و بدون
     * درگیری با collation ستون‌های utf8mb3 در information_schema).
     *
     * @return string[]
     */
    public function listTables(): array
    {
        try {
            if ($this->isSqlite()) {
                $rows = $this->column(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
                );
                return array_values(array_map('strval', $rows));
            }
            $stmt = $this->pdo()->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            if ($stmt === false) {
                return [];
            }
            $names = [];
            while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                if (isset($row[0]) && (string) $row[0] !== '') {
                    $names[] = (string) $row[0];
                }
            }
            sort($names, SORT_STRING);
            return $names;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * آیا ایندکس با این نام روی این جدول وجود دارد؟
     * از SHOW INDEX استفاده می‌شود تا هیچ پارامتر Prepared Statement با ستون‌های
     * utf8mb3 در information_schema.statistics مقایسه نشود (رفع خطای 3988/1267).
     */
    public function indexExists(string $table, string $index): bool
    {
        try {
            if ($this->isSqlite()) {
                return (bool) $this->value(
                    "SELECT name FROM sqlite_master WHERE type='index' AND name = :i",
                    ['i' => $index]
                );
            }
            if (!preg_match('~^[A-Za-z0-9_]{1,64}$~', $table) || !preg_match('~^[A-Za-z0-9_]{1,64}$~', $index)) {
                return false;
            }
            $stmt = $this->pdo()->query('SHOW INDEX FROM ' . $this->quoteIdent($table));
            if ($stmt === false) {
                return false;
            }
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
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

    public function tableExists(string $table): bool
    {
        try {
            if ($this->isSqlite()) {
                return (bool) $this->value(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name = :t",
                    ['t' => $table]
                );
            }
            if (!preg_match('~^[A-Za-z0-9_]{1,64}$~', $table)) {
                return false;
            }
            $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $table);
            $stmt = $this->pdo()->query('SHOW TABLES LIKE ' . $this->quote($like));
            if ($stmt === false) {
                return false;
            }
            while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                if (isset($row[0]) && strcasecmp((string) $row[0], $table) === 0) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * اجرای یک تراکنش؛ در صورت خطا همه‌چیز Rollback می‌شود.
     * از Savepoint برای تراکنش‌های تودرتویی استفاده می‌کند.
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $outer = $this->txLevel === 0;
        $savepoint = 'sp_' . $this->txLevel;

        if ($outer) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }
        $this->txLevel++;

        try {
            $result = $callback($this);
            $this->txLevel--;
            if ($outer) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return $result;
        } catch (\Throwable $e) {
            $this->txLevel--;
            try {
                if ($outer) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                }
            } catch (\Throwable) {
                // ignore
            }
            throw $e;
        }
    }

    public function inTransaction(): bool
    {
        return $this->txLevel > 0;
    }

    /** قفل ردیف برای جلوگیری از Race Condition در عملیات مالی */
    public function lockRow(string $table, int $id): ?array
    {
        if ($this->isSqlite()) {
            return $this->first('SELECT * FROM ' . $this->quoteIdent($table) . ' WHERE id = :id', ['id' => $id]);
        }
        return $this->first(
            'SELECT * FROM ' . $this->quoteIdent($table) . ' WHERE id = :id FOR UPDATE',
            ['id' => $id]
        );
    }

    public function close(): void
    {
        $this->pdo = null;
    }
}

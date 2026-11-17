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
    private array $config;

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
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $this->config['host'],
                    $this->config['port'] ?: '3306',
                    $this->config['database']
                );
                $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], $options);
                $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
                $this->pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
            }
        } catch (PDOException $e) {
            throw new RuntimeException('اتصال به پایگاه داده ناموفق بود: ' . ErrorHandler::redact($e->getMessage()), 0, $e);
        }

        return $this->pdo;
    }

    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                is_null($value) => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
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
        $existing = $this->value(
            'SELECT 1 FROM ' . $this->quoteIdent($table) . ' WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1',
            $params
        );
        if ($existing !== null) {
            return false;
        }
        $this->insert($table, $data);
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

    public function tableExists(string $table): bool
    {
        try {
            if ($this->isSqlite()) {
                return (bool) $this->value(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name = :t",
                    ['t' => $table]
                );
            }
            return (bool) $this->value(
                'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t',
                ['t' => $table]
            );
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

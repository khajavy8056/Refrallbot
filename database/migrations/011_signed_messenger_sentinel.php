<?php
declare(strict_types=1);

use RB\Support\Database;
use RB\Support\SchemaBuilder;

// Existing Telegram IDs remain positive; non-Telegram identities use negative
// sentinels. These three independent columns can contain either kind of ID.
return static function (SchemaBuilder $schema): void {
    $schema->after(static function (Database $db): void {
        if ($db->isSqlite()) return; // SQLite INTEGER is already signed 64-bit.
        $pdo = $db->pdo();
        $plans = [];
        foreach (['users', 'bot_sessions', 'broadcast_targets'] as $table) {
            $stmt = $pdo->query("SHOW FULL COLUMNS FROM `{$table}` WHERE Field = 'telegram_id'");
            $column = $stmt === false ? false : $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$column || !preg_match('/^(?:tinyint|smallint|mediumint|int|integer|bigint)(?:\(\d+\))?(?: unsigned)?$/i', (string) $column['Type']) || (string) $column['Extra'] !== '') {
                throw new \RuntimeException("نوع سفارشی یا ستون ناسازگار {$table}.telegram_id؛ ارتقا متوقف شد.");
            }
            // Preflight all tables before altering any. Never truncate an existing unsigned ID.
            $tooLarge = $pdo->query("SELECT 1 FROM `{$table}` WHERE `telegram_id` > 9223372036854775807 LIMIT 1");
            if ($tooLarge !== false && $tooLarge->fetchColumn() !== false) {
                throw new \RuntimeException("شناسه خارج از محدوده در {$table}.telegram_id؛ ارتقا متوقف شد.");
            }
            if (preg_match('/^bigint(?:\(\d+\))?$/i', (string) $column['Type'])) continue;
            $null = strtoupper((string) $column['Null']) === 'YES';
            $definition = 'BIGINT ' . ($null ? 'NULL' : 'NOT NULL');
            if ($column['Default'] !== null) {
                if (!preg_match('/^-?\d+$/', (string) $column['Default'])) throw new \RuntimeException("مقدار پیش‌فرض ناسازگار {$table}.telegram_id؛ ارتقا متوقف شد.");
                $definition .= ' DEFAULT ' . $column['Default'];
            } elseif ($null) {
                $definition .= ' DEFAULT NULL';
            }
            $comment = (string) ($column['Comment'] ?? '');
            if ($comment !== '') $definition .= ' COMMENT ' . $pdo->quote($comment);
            $plans[] = "ALTER TABLE `{$table}` MODIFY COLUMN `telegram_id` {$definition}";
        }
        // MySQL DDL commits implicitly; safe to retry after a partial failure.
        foreach ($plans as $sql) $pdo->exec($sql);
    });
};

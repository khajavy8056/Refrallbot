<?php
declare(strict_types=1);

namespace RB\Bot;

use RB\Support\Database;

/**
 * وضعیت گفتگوی چندمرحله‌ای ربات (بدون نیاز به حافظه‌ی دائمی)
 */
final class BotSession
{
    public function __construct(private Database $db)
    {
    }

    public function get(int $telegramId): ?array
    {
        $row = $this->db->first(
            'SELECT * FROM bot_sessions WHERE telegram_id = :t LIMIT 1',
            ['t' => $telegramId]
        );
        if ($row === null) {
            return null;
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            $this->clear($telegramId);
            return null;
        }
        $row['payload'] = json_decode((string) ($row['payload'] ?? '{}'), true) ?: [];
        return $row;
    }

    public function setStep(int $telegramId, ?int $userId, string $step, array $payload = [], int $ttlSeconds = 900): void
    {
        $existing = $this->db->first('SELECT id FROM bot_sessions WHERE telegram_id = :t', ['t' => $telegramId]);
        $data = [
            'telegram_id' => $telegramId,
            'user_id' => $userId,
            'step' => $step,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
            'updated_at' => now(),
        ];
        if ($existing) {
            $this->db->update('bot_sessions', $data, 'id = :id', ['id' => (int) $existing['id']]);
        } else {
            $data['created_at'] = now();
            $this->db->insert('bot_sessions', $data);
        }
    }

    public function clear(int $telegramId): void
    {
        $this->db->delete('bot_sessions', 'telegram_id = :t', ['t' => $telegramId]);
    }
}

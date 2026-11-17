<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;

/**
 * پیام‌های پشتیبانی
 */
final class SupportService
{
    public static function create(int $userId, string $message, ?string $subject = null): int
    {
        $db = Database::instance();
        $id = $db->insert('support_messages', [
            'user_id' => $userId,
            'admin_id' => null,
            'direction' => 'in',
            'subject' => $subject ? mb_substr($subject, 0, 150) : null,
            'message' => $message,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    public static function reply(int $ticketId, int $adminId, string $message): void
    {
        $db = Database::instance();
        $ticket = $db->first('SELECT * FROM support_messages WHERE id = :id', ['id' => $ticketId]);
        if ($ticket === null) {
            throw new \RuntimeException('پیام پشتیبانی یافت نشد.');
        }
        $db->insert('support_messages', [
            'user_id' => (int) $ticket['user_id'],
            'admin_id' => $adminId,
            'direction' => 'out',
            'subject' => $ticket['subject'],
            'message' => $message,
            'status' => 'answered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $db->update('support_messages', ['status' => 'answered', 'updated_at' => now()], 'id = :id', ['id' => $ticketId]);
        AuditService::log('support.replied', 'support', $ticketId, [], $adminId);
        Notifier::toUser((int) $ticket['user_id'], '📩 پاسخ پشتیبانی', $message, 'support');
    }
}

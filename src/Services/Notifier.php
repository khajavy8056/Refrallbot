<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;
use RB\Support\ErrorHandler;

/**
 * اطلاع‌رسانی: ذخیره اعلان در دیتابیس + تلاش برای ارسال پیام تلگرام
 * ارسال‌ها هرگز باعث شکست عملیات اصلی نمی‌شوند.
 */
final class Notifier
{
    public static function toUser(int $userId, string $title, string $body, string $type = 'info', ?string $link = null, bool $sendTelegram = true): void
    {
        $db = Database::instance();
        try {
            $db->insert('notifications', [
                'user_id' => $userId,
                'audience' => 'user',
                'type' => $type,
                'title' => mb_substr($title, 0, 150),
                'body' => $body,
                'link' => $link,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            ErrorHandler::logException($e);
            return;
        }

        if (!$sendTelegram || !TelegramService::configured()) {
            return;
        }
        try {
            $user = $db->first('SELECT telegram_id FROM users WHERE id = :id', ['id' => $userId]);
            if ($user === null) {
                return;
            }
            $text = '<b>' . e($title) . '</b>' . "\n\n" . $body;
            $tg = new TelegramService();
            $tg->sendMessage((int) $user['telegram_id'], $text);
        } catch (\Throwable $e) {
            ErrorHandler::logException($e);
        }
    }

    /** اطلاع‌رسانی پورسانت به ذی‌نفعان */
    public static function referralCommission(array $referredUser, array $result, CommissionSettings $settings): void
    {
        $db = Database::instance();
        $name = trim((string) (($referredUser['first_name'] ?? '') . ' ' . ($referredUser['last_name'] ?? ''))) ?: 'کاربر جدید';
        foreach ($result['commissions'] as $commission) {
            if (($commission['amount'] ?? 0) <= 0) {
                continue;
            }
            $title = $commission['type'] === 'direct' ? '🎉 پاداش معرفی جدید' : '📈 پورسانت لایه ' . fa_digits((int) $commission['level']);
            $body = $commission['type'] === 'direct'
                ? 'کاربر «' . $name . '» با لینک دعوت شما عضو شد.' . "\n" . 'مبلغ ' . wtoman($commission['amount']) . ' به کیف پول شما اضافه شد.'
                : 'از معرفی کاربر «' . $name . '» در سطح ' . fa_digits((int) $commission['level']) . ' شبکه شما، مبلغ ' . wtoman($commission['amount']) . ' به کیف پول شما اضافه شد.';
            self::toUser((int) $commission['beneficiary_id'], $title, $body, 'commission');
        }
    }
}

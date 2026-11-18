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
    /**
     * ارسال‌های معوق (پس از COMMIT تراکنش).
     * @var array<int,array{title:string,body:string}>
     */
    private static array $deferred = [];

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

        if (!$sendTelegram) {
            return;
        }
        self::sendTelegram($userId, $title, $body);
    }

    /**
     * ثبت اعلان در دیتابیس بدون ارسال تلگرام در همان لحظه.
     *
     * دلیل: درخواست HTTP تلگرام می‌تواند تا ۲۰ ثانیه طول بکشد؛ اگر داخل یک
     * تراکنش دیتابیس اجرا شود، قفل سطرها (و در MySQL قفل جدول) تا پایان
     * ارسال باز می‌ماند. پس رکورد اعلان داخل تراکنش ثبت می‌شود ولی ارسال
     * تلگرام پس از COMMIT با flushDeferred() انجام می‌گیرد.
     */
    public static function defer(int $userId, string $title, string $body, string $type = 'info', ?string $link = null): void
    {
        self::toUser($userId, $title, $body, $type, $link, false);
        self::$deferred[] = ['user_id' => $userId, 'title' => $title, 'body' => $body];
    }

    /** ارسال تلگرام اعلان‌های معوق — باید بعد از COMMIT صدا زده شود. */
    public static function flushDeferred(): void
    {
        if (self::$deferred === []) {
            return;
        }
        $items = self::$deferred;
        self::$deferred = [];
        foreach ($items as $item) {
            self::sendTelegram($item['user_id'], $item['title'], $item['body']);
        }
    }

    public static function hasDeferred(): bool
    {
        return self::$deferred !== [];
    }

    /** ارسال فوری یک پیام تلگرام (بدون ثبت اعلان جدید) */
    public static function sendTelegram(int $userId, string $title, string $body): void
    {
        if (!TelegramService::configured()) {
            return;
        }
        try {
            $user = Database::instance()->first('SELECT telegram_id FROM users WHERE id = :id', ['id' => $userId]);
            if ($user === null || empty($user['telegram_id'])) {
                return;
            }
            $text = '<b>' . e($title) . '</b>' . "\n\n" . $body;
            (new TelegramService())->sendMessage((int) $user['telegram_id'], $text);
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

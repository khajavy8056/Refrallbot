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
     * @var array<int,array{user_id:int,title:string,body:string}>
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

    /** پاک‌کردن صف ارسال معوق (در صورت Rollback تراکنش تا پیام اشتباه نرود) */
    public static function clearDeferred(): void
    {
        self::$deferred = [];
    }

    /**
     * ثبت و ارسال هوشمند: اگر داخل تراکنش دیتابیس باشیم ارسال تلگرام را به بعد از
     * COMMIT موکول می‌کند؛ در غیر این صورت بلافاصله می‌فرستد.
     */
    private static function dispatch(int $userId, string $title, string $body, string $type = 'info', ?string $link = null): void
    {
        if (Database::instance()->inTransaction()) {
            self::defer($userId, $title, $body, $type, $link);
            return;
        }
        self::toUser($userId, $title, $body, $type, $link, true);
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

    /**
     * پیام «دعوت موفق» برای معرف — بلافاصله پس از اتصال زیرمجموعهٔ جدید.
     *
     * حتی اگر پاداش معرفی صفر/غیرفعال باشد یا شرایط واجد شرایط شدن (شماره تماس /
     * کانال اجباری) هنوز کامل نشده باشد، معرف از پیوستن فرد جدید و تعداد کل
     * دعوت‌های مستقیم خود باخبر می‌شود. ضدتکرار روی (referrer, invited) تضمین
     * می‌کند برای یک کاربر دعوت‌شده هرگز دو پیام ارسال نشود.
     */
    public static function referralJoined(int $referrerId, array $invitedUser, ?int $directCount = null, ?int $totalCount = null): void
    {
        if ($referrerId <= 0) {
            return;
        }
        if (!SettingsService::bool('notify_referral_joined', true)) {
            return;
        }

        $db = Database::instance();
        try {
            $referrer = $db->first(
                'SELECT id, status, direct_ref_count, total_ref_count FROM users WHERE id = :id',
                ['id' => $referrerId]
            );
            if ($referrer === null || ($referrer['status'] ?? '') === 'blocked') {
                return;
            }

            $invitedId = (int) ($invitedUser['id'] ?? 0);
            if ($invitedId > 0 && $invitedId === $referrerId) {
                return;
            }

            // ضدتکرار: برای هر زیرمجموعه حداکثر یک اعلان دعوت موفق ثبت/ارسال شود
            $dedupeLink = $invitedId > 0 ? 'invite:' . $invitedId : null;
            if ($dedupeLink !== null) {
                $already = $db->value(
                    "SELECT 1 FROM notifications WHERE user_id = :u AND type = 'referral_join' AND link = :l LIMIT 1",
                    ['u' => $referrerId, 'l' => $dedupeLink]
                );
                if ($already) {
                    return;
                }
            }

            $direct = $directCount ?? (int) ($referrer['direct_ref_count'] ?? 0);
            if ($direct < 1) {
                $direct = max(1, (int) $db->value('SELECT COUNT(*) FROM users WHERE referred_by = :r', ['r' => $referrerId]));
            }
            $total = $totalCount ?? (int) ($referrer['total_ref_count'] ?? $direct);

            $rawName = trim((string) (($invitedUser['first_name'] ?? '') . ' ' . ($invitedUser['last_name'] ?? '')));
            $safeName = $rawName !== '' ? e($rawName) : 'کاربر جدید';
            $rawUsername = ltrim(trim((string) ($invitedUser['username'] ?? '')), '@');
            $safeUsername = $rawUsername !== '' ? '@' . e($rawUsername) : '';

            $title = '🎉 دعوت موفق جدید';
            $custom = trim((string) (SettingsService::get('referral_joined_message', '') ?? ''));
            if ($custom !== '') {
                $body = str_replace(
                    ['{name}', '{username}', '{count}', '{direct_count}', '{total_count}', '{app_name}'],
                    [
                        $safeName,
                        $safeUsername !== '' ? $safeUsername : $safeName,
                        fa_digits($direct),
                        fa_digits($direct),
                        fa_digits(max($direct, $total)),
                        e((string) SettingsService::get('app_name', 'رفرال‌بات')),
                    ],
                    $custom
                );
            } else {
                $userLabel = '«' . $safeName . '»' . ($safeUsername !== '' ? ' (' . $safeUsername . ')' : '');
                $body = 'تبریک! کاربر ' . $userLabel . ' با لینک دعوت شما وارد ربات شد.' . "\n"
                    . '👥 تعداد دعوت‌های شما: <b>' . fa_digits($direct) . ' نفر</b>';
                if ($total > $direct) {
                    $body .= "\n" . '🌐 کل شبکه شما: ' . fa_digits($total) . ' نفر';
                }
            }

            self::dispatch($referrerId, $title, $body, 'referral_join', $dedupeLink);
        } catch (\Throwable $e) {
            ErrorHandler::logException($e);
        }
    }

    /** اطلاع‌رسانی پورسانت به ذی‌نفعان */
    public static function referralCommission(array $referredUser, array $result, CommissionSettings $settings): void
    {
        $rawName = trim((string) (($referredUser['first_name'] ?? '') . ' ' . ($referredUser['last_name'] ?? '')));
        $safeName = $rawName !== '' ? e($rawName) : 'کاربر جدید';
        foreach ($result['commissions'] as $commission) {
            if (($commission['amount'] ?? 0) <= 0) {
                continue;
            }
            $title = $commission['type'] === 'direct' ? '💰 پاداش معرفی جدید' : '📈 پورسانت لایه ' . fa_digits((int) $commission['level']);
            $body = $commission['type'] === 'direct'
                ? 'بابت معرفی کاربر «' . $safeName . '»، مبلغ ' . wtoman($commission['amount']) . ' به کیف پول شما اضافه شد.'
                : 'از معرفی کاربر «' . $safeName . '» در سطح ' . fa_digits((int) $commission['level']) . ' شبکه شما، مبلغ ' . wtoman($commission['amount']) . ' به کیف پول شما اضافه شد.';
            self::dispatch((int) $commission['beneficiary_id'], $title, $body, 'commission');
        }
    }
}

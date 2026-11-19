<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Messengers\MessengerCapabilities;
use RB\Messengers\MessengerRegistry;
use RB\Support\Database;
use RB\Support\ErrorHandler;

/**
 * سرویس یکپارچه اطلاع‌رسانی چندپیام‌رسانی
 *
 * اعلان در جدول notifications ذخیره می‌شود و به‌صورت ایزوله و خارج از تراکنش دیتابیس
 * از طریق پیام‌رسان متصل کاربر (تلگرام، بله، روبیکا یا ایتا) ارسال می‌گردد.
 * خطا در هیچ پیام‌رسانی هرگز عملیات مالی یا سایر پیام‌رسان‌ها را متوقف نمی‌کند.
 */
final class Notifier
{
    /**
     * ارسال‌های معوق (پس از COMMIT تراکنش).
     * @var array<int,array{user_id:int,title:string,body:string,platform:?string}>
     */
    private static array $deferred = [];

    public static function toUser(
        int $userId,
        string $title,
        string $body,
        string $type = 'info',
        ?string $link = null,
        bool $sendTelegram = true,
        ?string $preferredPlatform = null
    ): void {
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
        self::sendMessenger($userId, $title, $body, $preferredPlatform);
    }

    /** ثبت اعلان در دیتابیس و موکول کردن ارسال پیام‌رسان به بعد از COMMIT */
    public static function defer(
        int $userId,
        string $title,
        string $body,
        string $type = 'info',
        ?string $link = null,
        ?string $preferredPlatform = null
    ): void {
        self::toUser($userId, $title, $body, $type, $link, false);
        self::$deferred[] = [
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'platform' => $preferredPlatform,
        ];
    }

    /** ارسال اعلان‌های معوق پس از COMMIT قطعی تراکنش */
    public static function flushDeferred(): void
    {
        if (self::$deferred === [] || Database::instance()->inTransaction()) {
            return;
        }
        $items = self::$deferred;
        self::$deferred = [];
        foreach ($items as $item) {
            self::sendMessenger($item['user_id'], $item['title'], $item['body'], $item['platform'] ?? null);
        }
    }

    public static function hasDeferred(): bool
    {
        return self::$deferred !== [];
    }

    public static function clearDeferred(): void
    {
        self::$deferred = [];
    }

    private static function dispatch(
        int $userId,
        string $title,
        string $body,
        string $type = 'info',
        ?string $link = null,
        ?string $preferredPlatform = null
    ): void {
        if (Database::instance()->inTransaction()) {
            self::defer($userId, $title, $body, $type, $link, $preferredPlatform);
            return;
        }
        self::toUser($userId, $title, $body, $type, $link, true, $preferredPlatform);
    }

    /** ارسال فوری اعلان به پیام‌رسان کاربر (نام قدیمی برای سازگاری ۱۰۰٪ حفظ شده است) */
    public static function sendTelegram(int $userId, string $title, string $body): void
    {
        self::sendMessenger($userId, $title, $body, null);
    }

    /** ارسال ایزوله اعلان از طریق پیام‌رسان فعال کاربر (تلگرام / بله / روبیکا / ایتا) */
    public static function sendMessenger(int $userId, string $title, string $body, ?string $preferredPlatform = null): void
    {
        try {
            $text = '<b>' . e($title) . '</b>' . "\n\n" . $body;
            (new MessengerRegistry(Database::instance()))->notifyUser($userId, $text, $preferredPlatform);
        } catch (\Throwable $e) {
            ErrorHandler::logException($e);
        }
    }

    /** پیام «دعوت موفق» برای معرف بلافاصله پس از ورود زیرمجموعه در هر پیام‌رسان */
    public static function referralJoined(int $referrerId, array $invitedUser, ?int $directCount = null, ?int $totalCount = null): void
    {
        if ($referrerId <= 0 || !SettingsService::bool('notify_referral_joined', true)) {
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
            $srcPlat = (string) ($invitedUser['referral_source_platform'] ?? $invitedUser['entry_platform'] ?? MessengerCapabilities::TELEGRAM);
            $platLabel = MessengerCapabilities::badge($srcPlat);

            $title = '🎉 دعوت موفق جدید';
            $custom = trim((string) (SettingsService::get('referral_joined_message', '') ?? ''));
            if ($custom !== '') {
                $body = str_replace(
                    ['{name}', '{username}', '{count}', '{direct_count}', '{total_count}', '{app_name}', '{messenger}'],
                    [
                        $safeName,
                        $safeUsername !== '' ? $safeUsername : $safeName,
                        fa_digits($direct),
                        fa_digits($direct),
                        fa_digits(max($direct, $total)),
                        e((string) SettingsService::get('app_name', 'رفرال‌بات')),
                        e(MessengerCapabilities::label($srcPlat)),
                    ],
                    $custom
                );
            } else {
                $userLabel = '«' . $safeName . '»' . ($safeUsername !== '' ? ' (' . $safeUsername . ')' : '');
                $body = 'تبریک! کاربر ' . $userLabel . ' از طریق لینک دعوت شما (' . e($platLabel) . ') عضو شد.' . "\n"
                    . '👥 دعوت‌های مستقیم شما (لایه ۰): <b>' . fa_digits($direct) . ' نفر</b>';
                if ($total > $direct) {
                    $body .= "\n" . '🌐 زیرمجموعه‌های غیرمستقیم: ' . fa_digits($total - $direct) . ' نفر (کل شبکه: ' . fa_digits($total) . ' نفر)';
                }
            }

            self::dispatch($referrerId, $title, $body, 'referral_join', $dedupeLink);
        } catch (\Throwable $e) {
            ErrorHandler::logException($e);
        }
    }

    /** اطلاع‌رسانی دقیق پاداش لایه ۰ (مستقیم)، هدیه خوش‌آمدگویی و لایه‌های ۱+ (غیرمستقیم) */
    public static function referralCommission(array $referredUser, array $result, CommissionSettings $settings): void
    {
        $rawName = trim((string) (($referredUser['first_name'] ?? '') . ' ' . ($referredUser['last_name'] ?? '')));
        $safeName = $rawName !== '' ? e($rawName) : 'کاربر جدید';

        foreach ($result['commissions'] as $commission) {
            $amount = (int) ($commission['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $type = (string) ($commission['type'] ?? '');
            $isPending = (($commission['status'] ?? 'credited') === 'pending');
            $statusSuffix = $isPending ? ' ثبت شد و پس از تأیید مدیریت به موجودی اضافه می‌شود.' : ' به کیف پول شما واریز شد.';

            if ($type === 'welcome') {
                $title = $isPending ? '🎁 هدیه خوش‌آمدگویی در انتظار تأیید' : '🎁 پاداش خوش‌آمدگویی واریز شد';
                $body = 'تبریک «' . $safeName . '» عزیز! شرایط ثبت‌نام شما تکمیل شد و مبلغ <b>'
                    . wtoman($amount) . '</b> هدیه خوش‌آمدگویی' . $statusSuffix;
            } elseif ($type === 'direct') {
                $title = $isPending ? '💰 پاداش معرفی مستقیم ثبت شد' : '💰 پاداش معرفی مستقیم (لایه ۰)';
                $body = 'بابت معرفی مستقیم کاربر «' . $safeName . '» (لایه ۰)، مبلغ <b>'
                    . wtoman($amount) . '</b>' . $statusSuffix;
            } else {
                $lvl = (int) ($commission['level'] ?? 1);
                $title = '📈 درآمد لایه ' . fa_digits($lvl) . ' (غیرمستقیم)';
                $body = 'بابت عضویت موفق کاربر «' . $safeName . '» در لایه ' . fa_digits($lvl) . ' شبکه غیرمستقیم شما، مبلغ <b>'
                    . wtoman($amount) . '</b>' . $statusSuffix;
            }
            self::dispatch((int) $commission['beneficiary_id'], $title, $body, 'commission');
        }
    }
}

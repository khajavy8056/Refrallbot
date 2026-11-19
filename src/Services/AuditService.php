<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;
use RB\Support\Request;
use RB\Support\Session;

/**
 * ثبت گزارش فعالیت مدیران
 */
final class AuditService
{
    /** @var array<string,string> برچسب فارسی فعالیت‌ها */
    public const ACTIONS = [
        'admin.login' => 'ورود مدیر',
        'admin.logout' => 'خروج مدیر',
        'admin.login_failed' => 'تلاش ناموفق ورود',
        'admin.created' => 'ایجاد مدیر',
        'admin.updated' => 'ویرایش مدیر',
        'admin.password_changed' => 'تغییر رمز مدیر',
        'settings.updated' => 'تغییر تنظیمات سیستم',
        'settings.commission_updated' => 'تغییر تنظیمات پورسانت',
        'settings.withdrawal_updated' => 'تغییر تنظیمات برداشت',
        'telegram.token_saved' => 'ذخیره توکن ربات',
        'telegram.token_removed' => 'قطع اتصال ربات',
        'telegram.webhook_set' => 'تنظیم Webhook',
        'telegram.webhook_deleted' => 'حذف Webhook',
        'telegram.menu_synced' => 'همگام‌سازی منوهای ربات',
        'telegram.profile_synced' => 'استعلام پروفایل و بیوگرافی ربات',
        'telegram.profile_updated' => 'ویرایش پروفایل و بیوگرافی (Bio) ربات',
        'telegram.photo_updated' => 'تغییر تصویر پروفایل ربات',
        'telegram.photo_removed' => 'حذف تصویر پروفایل ربات',
        'user.status_changed' => 'تغییر وضعیت کاربر',
        'user.verified' => 'تأیید احراز عضویت کاربر',
        'user.referrer_changed' => 'تغییر معرف کاربر',
        'user.updated' => 'ویرایش اطلاعات کاربر',
        'wallet.adjusted' => 'اصلاح مالی کیف پول',
        'withdrawal.approved' => 'تأیید درخواست برداشت',
        'withdrawal.rejected' => 'رد درخواست برداشت',
        'withdrawal.paid' => 'ثبت پرداخت برداشت',
        'withdrawal.cancelled' => 'لغو درخواست برداشت',
        'campaign.created' => 'ایجاد کمپین',
        'campaign.updated' => 'ویرایش کمپین',
        'campaign.reward_granted' => 'پرداخت جایزه کمپین',
        'broadcast.created' => 'ایجاد پیام همگانی',
        'broadcast.started' => 'شروع ارسال همگانی',
        'broadcast.cancelled' => 'لغو ارسال همگانی',
        'channel.created' => 'افزودن کانال اجباری',
        'channel.updated' => 'ویرایش کانال اجباری',
        'channel.deleted' => 'حذف کانال اجباری',
        'security.reviewed' => 'بررسی رویداد امنیتی',
        'backup.created' => 'تهیه نسخه پشتیبان',
        'backup.downloaded' => 'دانلود نسخه پشتیبان',
        'backup.restored' => 'بازیابی نسخه پشتیبان',
        'update.checked' => 'بررسی نسخه جدید',
        'update.applied' => 'به‌روزرسانی سامانه',
        'update.failed' => 'خطا در به‌روزرسانی سامانه',
        'update.restored' => 'بازگردانی فایل‌های سامانه',
        'update.settings_updated' => 'تغییر تنظیمات به‌روزرسانی',
        'support.replied' => 'پاسخ به پشتیبانی',
        'commission.reverted' => 'برگشت پورسانت',
    ];

    public static function log(string $action, ?string $entityType = null, ?int $entityId = null, array $details = [], ?int $adminId = null): void
    {
        $admin = null;
        if ($adminId !== null) {
            $admin = Database::instance()->first('SELECT name FROM admins WHERE id = :id', ['id' => $adminId]);
        } elseif (!empty($_SESSION['admin_id'])) {
            $adminId = (int) $_SESSION['admin_id'];
            $admin = Database::instance()->first('SELECT name FROM admins WHERE id = :id', ['id' => $adminId]);
        }

        // پاکسازی اطلاعات حساس قبل از ذخیره
        $details = self::sanitize($details);

        Database::instance()->insert('admin_activity_logs', [
            'admin_id' => $adminId,
            'admin_name' => $admin['name'] ?? 'سیستم',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'created_at' => now(),
        ]);
    }

    private static function sanitize(array $details): array
    {
        $sensitive = ['password', 'password_hash', 'token', 'bot_token', 'card_number', 'secret', 'totp_secret', 'api_key'];
        array_walk_recursive($details, static function (&$value, $key) use ($sensitive) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                $value = '[REDACTED]';
            } elseif (is_string($value)) {
                $value = mb_substr($value, 0, 1000);
            }
        });
        return $details;
    }

    public static function label(string $action): string
    {
        return self::ACTIONS[$action] ?? $action;
    }
}

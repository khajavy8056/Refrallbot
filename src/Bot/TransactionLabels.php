<?php
declare(strict_types=1);

namespace RB\Bot;

/**
 * برچسب فارسی انواع تراکنش (مشترک بین ربات، پنل کاربری و پنل مدیریت)
 */
final class TransactionLabels
{
    public const TYPES = [
        'commission_direct' => 'پاداش معرفی مستقیم',
        'commission_welcome' => 'پاداش خوش‌آمدگویی دعوت',
        'commission_level' => 'پورسانت لایه‌ای',
        'campaign_reward' => 'جایزه کمپین',
        'level_promotion' => 'جایزه ارتقای سطح',
        'withdrawal_reserve' => 'رزرو برداشت',
        'withdrawal_release' => 'بازگشت مبلغ برداشت',
        'withdrawal_paid' => 'پرداخت برداشت',
        'adjustment' => 'اصلاح مالی',
        'reversal' => 'برگشت مالی',
        'refund' => 'بازپرداخت',
        'manual_credit' => 'واریز دستی',
        'manual_debit' => 'برداشت دستی',
    ];

    public const STATUSES = [
        'completed' => 'تکمیل‌شده',
        'pending' => 'در انتظار',
        'reverted' => 'برگشت‌خورده',
        'failed' => 'ناموفق',
        'info' => 'اطلاع‌رسانی (بدون اثر بر موجودی)',
    ];

    public static function label(string $type): string
    {
        return self::TYPES[$type] ?? $type;
    }

    public static function status(string $status): string
    {
        return self::STATUSES[$status] ?? $status;
    }

    /** کلاس رنگ وضعیت برای رابط کاربری */
    public static function statusClass(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'pending', 'info' => 'warning',
            'reverted', 'failed' => 'danger',
            default => 'muted',
        };
    }

    public static function typeClass(string $type): string
    {
        return match ($type) {
            'commission_direct', 'commission_welcome' => 'success',
            'commission_level' => 'info',
            'campaign_reward' => 'purple',
            'withdrawal_paid' => 'danger',
            'withdrawal_reserve' => 'warning',
            'withdrawal_release', 'adjustment', 'reversal' => 'muted',
            default => 'muted',
        };
    }
}

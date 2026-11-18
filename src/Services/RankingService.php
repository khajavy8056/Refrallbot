<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;

/**
 * رتبه‌بندی کاربران بر اساس معیار قابل تنظیم
 */
final class RankingService
{
    public const METRICS = [
        'direct_ref_count' => 'بیشترین معرفی مستقیم',
        'total_ref_count' => 'بیشترین زیرمجموعه',
        'total_earned' => 'بیشترین درآمد',
        'layer_earned' => 'بیشترین پورسانت لایه‌ای',
    ];

    public static function enabled(): bool
    {
        return SettingsService::bool('ranking_enabled', true);
    }

    public static function metric(): string
    {
        $metric = (string) SettingsService::get('ranking_metric', 'direct_ref_count');
        return array_key_exists($metric, self::METRICS) ? $metric : 'direct_ref_count';
    }

    /** @return array<int,array> */
    public static function top(int $limit = 20, ?string $metric = null): array
    {
        $metric = $metric ?? self::metric();
        if (!array_key_exists($metric, self::METRICS)) {
            $metric = 'direct_ref_count';
        }
        return Database::instance()->all(
            "SELECT id, first_name, last_name, username, telegram_id, referral_code,
                    direct_ref_count, total_ref_count, total_earned, layer_earned, joined_at
             FROM users
             WHERE status <> 'blocked' AND {$metric} > 0
             ORDER BY {$metric} DESC, id ASC
             LIMIT " . (int) $limit
        );
    }

    /** رتبه کاربر (۱ = بالاترین) */
    public static function rankOf(int $userId, ?string $metric = null): ?int
    {
        if (!self::enabled()) {
            return null;
        }
        $metric = $metric ?? self::metric();
        if (!array_key_exists($metric, self::METRICS)) {
            $metric = 'direct_ref_count';
        }
        $db = Database::instance();
        $value = $db->value("SELECT {$metric} FROM users WHERE id = :id", ['id' => $userId]);
        if ($value === null || (int) $value <= 0) {
            return null;
        }
        $better = (int) $db->value(
            "SELECT COUNT(*) + 1 FROM users WHERE {$metric} > :v AND status <> 'blocked'",
            ['v' => (int) $value]
        );
        return $better;
    }

    public static function totalParticipants(?string $metric = null): int
    {
        $metric = $metric ?? self::metric();
        return (int) Database::instance()->value(
            "SELECT COUNT(*) FROM users WHERE {$metric} > 0 AND status <> 'blocked'"
        );
    }
}

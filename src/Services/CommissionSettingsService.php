<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;
use RB\Support\Money;

/**
 * اعتبارسنجی تنظیمات پورسانت و شناسایی کاربران مشکوک
 */
final class CommissionSettingsService
{
    public function __construct(private ?Database $db = null)
    {
        $this->db ??= Database::instance();
    }

    private static function amount(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }
        return Money::parseToman(is_string($value) ? $value : null) ?? 0;
    }

    /**
     * @return array{0:bool,1:array<int,string>}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $directReward = self::amount($input['direct_reward'] ?? 0);
        $maxLevels = (int) ($input['max_levels'] ?? 1);
        if ($maxLevels < 1 || $maxLevels > 8) {
            $errors[] = 'تعداد لایه‌ها باید بین ۱ تا ۸ باشد.';
        }
        $levels = $input['levels'] ?? [];
        if (!is_array($levels)) {
            $errors[] = 'ساختار لایه‌ها نامعتبر است.';
            $levels = [];
        }
        $totalPercent = 0.0;
        foreach ($levels as $level) {
            $num = (int) ($level['level'] ?? 0);
            $percent = (float) ($level['percent'] ?? 0);
            if ($percent < 0 || $percent > 100) {
                $errors[] = 'درصد لایه ' . $num . ' باید بین ۰ تا ۱۰۰ باشد.';
            }
            // لایه‌های خارج از تعداد لایه‌های فعال فقط وقتی خطا هستند که واقعاً فعال باشند
            if ($num > $maxLevels && !empty($level['enabled']) && $percent > 0) {
                $errors[] = 'لایه ' . $num . ' فعال است اما تعداد لایه‌های فعال ' . $maxLevels . ' در نظر گرفته شده است.';
            }
            if (!empty($level['enabled'])) {
                $totalPercent += $percent;
            }
        }
        if ($totalPercent > 100) {
            $errors[] = 'مجموع درصدهای لایه‌های فعال نمی‌تواند از ۱۰۰٪ بیشتر باشد (فعلی: ' . $totalPercent . '٪).';
        }
        if (!empty($input['multi_level_enabled']) && $totalPercent <= 0) {
            $errors[] = 'در حالت چندلایه، حداقل یک لایه باید درصد بزرگ‌تر از صفر داشته باشد.';
        }
        if (!empty($input['direct_enabled']) && $directReward <= 0) {
            $errors[] = 'در صورت فعال بودن پاداش مستقیم، مبلغ پاداش باید بیشتر از صفر باشد.';
        }
        $maxPayout = self::amount($input['max_payout_per_event'] ?? 0);
        if ($maxPayout > 0 && $directReward > $maxPayout) {
            $errors[] = 'سقف پرداخت هر رویداد باید بزرگ‌تر یا مساوی پاداش مستقیم باشد.';
        }
        $percentBase = (string) ($input['percent_base'] ?? 'direct_reward');
        if (!in_array($percentBase, ['direct_reward', 'fixed_base', 'event_amount'], true)) {
            $errors[] = 'مبنای محاسبه درصد نامعتبر است.';
        }
        if ($percentBase === 'fixed_base' && self::amount($input['fixed_base_amount'] ?? 0) <= 0) {
            $errors[] = 'در مبنای «مبلغ ثابت»، مبلغ پایه باید بیشتر از صفر باشد.';
        }
        return [$errors === [], $errors];
    }

    public function isSuspicious(int $userId): bool
    {
        $status = $this->db->value('SELECT status FROM users WHERE id = :id', ['id' => $userId]);
        $openEvents = (int) $this->db->value(
            "SELECT COUNT(*) FROM security_events WHERE user_id = :u AND status = 'open' AND severity IN ('high','medium')",
            ['u' => $userId]
        );
        return $status === 'suspicious' || $openEvents > 0;
    }
}

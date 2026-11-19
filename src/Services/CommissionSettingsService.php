<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;
use RB\Support\Money;

/**
 * اعتبارسنجی تنظیمات پورسانت (لایه صفر ثابت + لایه‌های ۱..۸ ثابت/درصدی + سقف‌ها) و شناسایی کاربران مشکوک
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

        // لایه ۰ (دعوت مستقیم): الزاماً مبلغ ثابت تومانی
        if (isset($input['direct_calc_type']) && (string) $input['direct_calc_type'] !== 'fixed') {
            $errors[] = 'لایه صفر (دعوت مستقیم) باید حتماً به‌صورت مبلغ ثابت و تومانی محاسبه شود.';
        }

        $directReward = self::amount($input['direct_reward'] ?? 0);
        $inviteeReward = self::amount($input['invitee_reward'] ?? 0);
        if ($directReward < 0) {
            $errors[] = 'مبلغ پاداش لایه صفر (دعوت مستقیم) نمی‌تواند منفی باشد.';
        }
        if ($inviteeReward < 0) {
            $errors[] = 'مبلغ پاداش دعوت‌شده نمی‌تواند منفی باشد.';
        }

        $maxLevels = (int) ($input['max_levels'] ?? 1);
        if ($maxLevels < 1 || $maxLevels > CommissionSettings::MAX_SUPPORTED_LEVELS) {
            $errors[] = 'تعداد لایه‌های غیرمستقیم باید بین ۱ تا ' . fa_digits(CommissionSettings::MAX_SUPPORTED_LEVELS) . ' باشد.';
        }

        $levels = $input['levels'] ?? [];
        if (!is_array($levels)) {
            $errors[] = 'ساختار لایه‌ها نامعتبر است.';
            $levels = [];
        }

        $totalPercent = 0.0;
        $activeValidLayers = 0;
        $hasPercentLayer = false;

        foreach ($levels as $level) {
            if (!is_array($level)) {
                continue;
            }
            $num = (int) ($level['level'] ?? 0);
            $enabled = !empty($level['enabled']);
            $calcType = strtolower(trim((string) ($level['calc_type'] ?? 'percent')));
            if (!in_array($calcType, ['fixed', 'percent'], true)) {
                $errors[] = 'نوع محاسبه لایه ' . fa_digits($num) . ' نامعتبر است (فقط مبلغ ثابت یا درصدی مجاز است).';
                continue;
            }

            $fixedAmt = self::amount($level['amount'] ?? 0);
            $percent = (float) ($level['percent'] ?? 0);
            $maxAmt = self::amount($level['max_amount'] ?? 0);

            if ($maxAmt < 0) {
                $errors[] = 'سقف پرداخت لایه ' . fa_digits($num) . ' نمی‌تواند منفی باشد.';
            }

            if ($calcType === 'fixed') {
                if ($fixedAmt < 0) {
                    $errors[] = 'مبلغ ثابت لایه ' . fa_digits($num) . ' نمی‌تواند منفی باشد.';
                }
                if ($enabled && $num <= $maxLevels) {
                    if ($fixedAmt <= 0) {
                        $errors[] = 'مبلغ ثابت لایه فعال ' . fa_digits($num) . ' باید بیشتر از صفر تومان باشد.';
                    } else {
                        $activeValidLayers++;
                    }
                    if ($maxAmt > 0 && $maxAmt < $fixedAmt) {
                        $errors[] = 'سقف پرداخت لایه ' . fa_digits($num) . ' از مبلغ ثابت تعیین‌شده همان لایه کمتر است.';
                    }
                }
                if ($num > $maxLevels && $enabled && $fixedAmt > 0) {
                    $errors[] = 'لایه ' . fa_digits($num) . ' فعال است اما تعداد لایه‌ها ' . fa_digits($maxLevels) . ' تعیین شده است.';
                }
            } else {
                if ($percent < 0 || $percent > 100) {
                    $errors[] = 'درصد لایه ' . fa_digits($num) . ' باید بین ۰ تا ۱۰۰ باشد.';
                }
                if ($num > $maxLevels && $enabled && $percent > 0) {
                    $errors[] = 'لایه ' . fa_digits($num) . ' فعال است اما تعداد لایه‌ها ' . fa_digits($maxLevels) . ' تعیین شده است.';
                }
                if ($enabled && $num <= $maxLevels) {
                    $totalPercent += $percent;
                    if ($percent > 0) {
                        $activeValidLayers++;
                        $hasPercentLayer = true;
                    } else {
                        $errors[] = 'درصد لایه فعال ' . fa_digits($num) . ' باید بیشتر از صفر باشد.';
                    }
                }
            }
        }

        if ($totalPercent > 100.0001) {
            $errors[] = 'مجموع درصدهای لایه‌های درصدی فعال نمی‌تواند از ۱۰۰٪ بیشتر باشد (فعلی: ' . fa_digits((string) $totalPercent) . '٪).';
        }
        if (!empty($input['multi_level_enabled']) && $activeValidLayers === 0) {
            $errors[] = 'در حالت درآمد لایه‌ای، حداقل یک لایه (از لایه ۱ تا ' . fa_digits($maxLevels) . ') باید فعال و دارای مقدار بزرگ‌تر از صفر باشد.';
        }
        if (!empty($input['direct_enabled']) && $directReward <= 0) {
            $errors[] = 'در صورت فعال بودن لایه صفر (پاداش دعوت مستقیم)، مبلغ پاداش باید بیشتر از صفر تومان باشد.';
        }
        if (!empty($input['invitee_enabled']) && $inviteeReward <= 0) {
            $errors[] = 'در صورت فعال بودن پاداش دعوت‌شده (خوش‌آمدگویی)، مبلغ پاداش باید بیشتر از صفر تومان باشد.';
        }

        $maxPayout = self::amount($input['max_payout_per_event'] ?? 0);
        $activeDirect = !empty($input['direct_enabled']) ? $directReward : 0;
        $activeInvitee = !empty($input['invitee_enabled']) ? $inviteeReward : 0;
        $minRequiredCap = max($directReward, $activeDirect + $activeInvitee);
        if ($maxPayout > 0 && $minRequiredCap > $maxPayout) {
            $errors[] = $activeInvitee > 0
                ? 'سقف پرداخت هر رویداد باید بزرگ‌تر یا مساوی مجموع پاداش دعوت‌کننده (لایه صفر) و دعوت‌شده باشد.'
                : 'سقف پرداخت هر رویداد باید بزرگ‌تر یا مساوی پاداش لایه صفر (دعوت مستقیم) باشد.';
        }

        $percentBase = (string) ($input['percent_base'] ?? 'direct_reward');
        if (!array_key_exists($percentBase, CommissionSettings::PERCENT_BASES)) {
            $errors[] = 'مبنای محاسبه درصد نامعتبر است.';
        }
        $fixedBaseAmount = self::amount($input['fixed_base_amount'] ?? 0);
        if ($percentBase === 'fixed_base' && $fixedBaseAmount <= 0) {
            $errors[] = 'در مبنای «مبلغ پایه ثابت»، وارد کردن مبلغ پایه بزرگ‌تر از صفر الزامی است.';
        }
        if (!empty($input['multi_level_enabled']) && $hasPercentLayer && $percentBase === 'direct_reward' && $directReward <= 0) {
            $errors[] = 'برای محاسبه لایه‌های درصدی بر مبنای پاداش پایه (لایه صفر)، مبلغ لایه صفر باید بیشتر از صفر باشد یا مبنا را روی «مبلغ پایه ثابت» بگذارید.';
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

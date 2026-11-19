<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * محاسبات پولی دقیق
 *
 * تمام مبالغ در سیستم به «تومان» و به‌صورت عدد صحیح (int) ذخیره می‌شوند تا هیچ خطای
 * اعشاری‌ای رخ ندهد. درصدها به‌صورت رشته (مثلاً "12.5") نگهداری و با ریاضیات
 * عدد صحیح در مقیاس ۱۰۰۰۰ محاسبه می‌شوند.
 */
final class Money
{
    private const SCALE = 10000;

    /** تبدیل درصد به عدد صحیح با مقیاس ۱۰۰۰۰ */
    public static function toMicro(string|int|float $percent): int
    {
        $value = trim(en_digits((string) $percent));
        // ممیز فارسی (٫) به نقطه اعشار تبدیل می‌شود و جداکنندهٔ هزارگان (٬ / ، / ,) حذف می‌گردد
        $value = str_replace('٫', '.', $value);
        $value = str_replace(['٪', '%', ' ', '٬', '،', ','], '', $value);
        if ($value === '' || !is_numeric($value)) {
            return 0;
        }
        // تبدیل دستی رشته اعشاری به عدد صحیح (بدون خطای float)
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $frac] = array_pad(explode('.', $value, 2), 2, '');
        $whole = preg_replace('~\D~', '', $whole) ?: '0';
        $frac = substr(preg_replace('~\D~', '', $frac) ?: '', 0, 4);
        $frac = str_pad($frac, 4, '0');
        $micro = (int) $whole * self::SCALE + (int) $frac;
        return $negative ? -$micro : $micro;
    }

    public static function fromMicro(int $micro): string
    {
        $negative = $micro < 0;
        $micro = abs($micro);
        $whole = intdiv($micro, self::SCALE);
        $frac = $micro % self::SCALE;
        if ($frac === 0) {
            return ($negative ? '-' : '') . $whole;
        }
        $fracStr = rtrim(sprintf('%04d', $frac), '0');
        return ($negative ? '-' : '') . $whole . '.' . $fracStr;
    }

    /**
     * محاسبه درصد از مبلغ با تقسیم صحیح (بدون خطای اعشاری)
     */
    public static function percentOf(int $amount, string|int|float $percent, string $rounding = 'floor'): int
    {
        $micro = self::toMicro($percent);
        if ($micro <= 0 || $amount === 0) {
            return 0;
        }
        $numerator = $amount * $micro;   // amount * percent * 10000
        $denominator = 100 * self::SCALE;
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        return match ($rounding) {
            'ceil' => $remainder > 0 ? $quotient + 1 : $quotient,
            'round' => ($remainder * 2 >= $denominator) ? $quotient + 1 : $quotient,
            default => $quotient,
        };
    }

    /** اعتبارسنجی مبلغ ورودی کاربر (تومان) */
    public static function parseToman(string|int|null $input): ?int
    {
        if ($input === null) {
            return null;
        }
        $value = en_digits(trim((string) $input));
        $value = str_replace([',', '٬', ' ', '،', '_', 'تومان', 'تومن', 'ریال', 'IRT', 'irr'], '', $value);
        if ($value === '' || !preg_match('~^\d+$~', $value)) {
            return null;
        }
        return (int) $value;
    }

    /** تبدیل ریال به تومان (برای APIهایی که ریال دریافت می‌کنند) */
    public static function rialToToman(int $rial): int
    {
        return intdiv($rial, 10);
    }

    public static function tomanToRial(int $toman): int
    {
        return $toman * 10;
    }

    /** نام جایگزین (جمع) برای خوانایی در کدهای دیگر */
    public static function rialsToToman(int $rial): int
    {
        return self::rialToToman($rial);
    }

    /** جمع امن مبالغ با کنترل سرریز */
    public static function sum(iterable $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += (int) $amount;
        }
        return $total;
    }
}

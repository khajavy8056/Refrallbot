<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * تبدیل تاریخ میلادی و شمسی (الگوریتم استاندارد، بدون وابستگی)
 */
final class Jalali
{
    /** @return array{0:int,1:int,2:int} */
    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return [$jy, $jm, $jd];
    }

    /** @return array{0:int,1:int,2:int} */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd
            + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $sal_a = [0, 31, (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28,
            31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 1;
        foreach ($sal_a as $i => $v) {
            if ($i === 0) {
                continue;
            }
            if ($gd <= $v) {
                $gm = $i;
                break;
            }
            $gd -= $v;
        }
        return [$gy, $gm, $gd];
    }

    /** تبدیل تاریخ میلادی «Y-m-d» یا timestamp به رشته شمسی با ارقام فارسی */
    public static function toJalaliString(string|int|null $value, bool $withTime = false): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $ts = is_numeric($value) && strlen((string) $value) <= 10
            ? (int) $value
            : strtotime((string) $value);
        if (!$ts) {
            return '';
        }
        [$jy, $jm, $jd] = self::toJalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
        $out = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        if ($withTime) {
            $out .= ' ' . date('H:i', $ts);
        }
        return en_digits($out) === $out ? fa_digits($out) : $out;
    }

    /** تبدیل رشته تاریخ شمسی «1403/05/12» به Y-m-d میلادی */
    public static function jalaliStringToGregorian(string $value): ?string
    {
        $value = str_replace(['-', '.'], '/', en_digits($value));
        if (!preg_match('~^(\d{4})/(\d{1,2})/(\d{1,2})$~', $value, $m)) {
            return null;
        }
        [$gy, $gm, $gd] = self::toGregorian((int) $m[1], (int) $m[2], (int) $m[3]);
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }
}

<?php
/**
 * توابع کمکی سراسری
 */

use RB\Support\Database;
use RB\Support\Csrf;
use RB\Support\Session;

if (!function_exists('e')) {
    /** escape برای خروجی HTML (جلوگیری از XSS) */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('json_out')) {
    function json_out(array $data, int $status = 200): void
    {
        // در SAPI غیروب (CLI/تست) کد وضعیت قابل خواندن نیست؛ برای تست‌ها
        // آن را در یک متغیر سراسری ثبت می‌کنیم.
        if (defined('RB_TEST_MODE')) {
            $GLOBALS['RB_LAST_JSON_STATUS'] = $status;
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('url')) {
    /** ساخت آدرس مطلق یا نسبی */
    function url(string $path = '', bool $absolute = false): string
    {
        $base = \RB\Support\Config::get('app.base_path', '');
        $path = '/' . ltrim($path, '/');
        $full = rtrim($base, '/') . $path;
        if ($full === '') {
            $full = '/';
        }
        if ($absolute) {
            return \RB\Support\Request::origin() . $full;
        }
        return $full;
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $clean = ltrim($path, '/');
        $base = url('assets/' . $clean);
        if (str_ends_with($clean, '.js') || str_ends_with($clean, '.css')) {
            $file = RB_ROOT . '/public/assets/' . $clean;
            $mtime = is_file($file) ? @filemtime($file) : false;
            if ($mtime !== false && $mtime > 0) {
                return $base . '?v=' . $mtime;
            }
        }
        return $base;
    }
}

if (!function_exists('wtoman')) {
    /** قالب‌بندی مبلغ به تومان با ارقام فارسی */
    function wtoman(int|string|null $amount, bool $fa = true): string
    {
        $amount = (int) ($amount ?? 0);
        $formatted = number_format($amount);
        if ($fa) {
            $formatted = fa_digits($formatted);
        }
        return $formatted . ' تومان';
    }
}

if (!function_exists('fa_digits')) {
    function fa_digits(string|int $text): string
    {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            (string) $text
        );
    }
}

if (!function_exists('en_digits')) {
    /** تبدیل ارقام فارسی/عربی به لاتین */
    function en_digits(string $text): string
    {
        return str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '،', '٬'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', ',', ''],
            $text
        );
    }
}

if (!function_exists('fa_date')) {
    /** تبدیل تاریخ میلادی به شمسی */
    function fa_date(?string $datetime, bool $withTime = true): string
    {
        if (!$datetime || $datetime === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = is_numeric($datetime) ? (int) $datetime : strtotime($datetime);
        if ($ts === false || $ts <= 0) {
            return '—';
        }
        [$jy, $jm, $jd] = \RB\Support\Jalali::toJalali(
            (int) date('Y', $ts),
            (int) date('n', $ts),
            (int) date('j', $ts)
        );
        $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
            'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
        $out = fa_digits($jd) . ' ' . $months[$jm - 1] . ' ' . fa_digits($jy);
        if ($withTime) {
            $out .= ' - ' . fa_digits(date('H:i', $ts));
        }
        return $out;
    }
}

if (!function_exists('db')) {
    function db(): Database
    {
        return Database::instance();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
    }
}

if (!function_exists('flash')) {
    function flash(): Session
    {
        return new Session('flash');
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed
    {
        $old = $_SESSION['_old'] ?? [];
        return $old[$key] ?? $default;
    }
}

if (!function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return \RB\Services\SettingsService::get($key, $default);
    }
}

if (!function_exists('render')) {
    /** رندر یک View با Layout */
    function render(string $view, array $data = [], string $layout = 'app'): void
    {
        echo \RB\Support\View::render($view, $data, $layout);
    }
}

if (!function_exists('request_is_post')) {
    function request_is_post(): bool
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }
}

if (!function_exists('input')) {
    function input(string $key, mixed $default = null): mixed
    {
        return \RB\Support\Request::field($key, $default);
    }
}

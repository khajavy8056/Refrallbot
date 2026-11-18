<?php
declare(strict_types=1);

namespace RB\Support;

/**
 * محدودکنندهٔ نرخ ساده و مبتنی بر فایل (بدون وابستگی به دیتابیس/Redis).
 *
 * برای جلوگیری از سیل پیام در ربات تلگرام استفاده می‌شود: هر کاربر
 * (با کلید یکتا مثل bot:<telegram_id>) در یک پنجرهٔ زمانی تعداد محدودی
 * درخواست دارد. از flock استفاده می‌کند تا در درخواست‌های همزمان
 * شمارنده خراب نشود.
 */
final class RateLimiter
{
    /** @var array<string,array{count:int,start:int}> کش درون‌درخواستی */
    private static array $memo = [];

    private static function dir(): string
    {
        $dir = RB_STORAGE . '/cache/ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function file(string $key): string
    {
        return self::dir() . '/' . preg_replace('~[^a-z0-9_\-]+~i', '_', $key) . '.json';
    }

    /**
     * آیا این کلید از حد مجاز گذشته است؟ (بدون افزایش شمارنده)
     */
    public static function exceeded(string $key, int $limit, int $windowSeconds): bool
    {
        $data = self::read($key, $windowSeconds);
        return $data['count'] >= $limit;
    }

    /**
     * یک درخواست ثبت می‌کند و می‌گوید آیا **قبل از** آن حد رد شده بود.
     * مقدار true یعنی «زیادی»: این درخواست نباید پردازش شود.
     */
    public static function tooMany(string $key, int $limit, int $windowSeconds = 60): bool
    {
        $data = self::read($key, $windowSeconds);
        if ($data['count'] >= $limit) {
            return true;
        }
        $data['count']++;
        self::$memo[$key] = $data;
        self::write($key, $data);
        return false;
    }

    /** بازنشانی یک کلید (مثلاً پس از پرداخت موفق) */
    public static function reset(string $key): void
    {
        unset(self::$memo[$key]);
        $file = self::file($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** @return array{count:int,start:int} */
    private static function read(string $key, int $windowSeconds): array
    {
        $now = time();
        if (isset(self::$memo[$key])) {
            $data = self::$memo[$key];
            if ($now - $data['start'] < $windowSeconds) {
                return $data;
            }
        }

        $file = self::file($key);
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            // اگر نتوانستیم فایل بسازیم، محدودیت را اعمال نمی‌کنیم (کاربر نباید قفل شود)
            return ['count' => 0, 'start' => $now];
        }
        @flock($handle, LOCK_SH);
        $raw = stream_get_contents($handle) ?: '';
        @flock($handle, LOCK_UN);
        @fclose($handle);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['start'], $decoded['count'])) {
            return ['count' => 0, 'start' => $now];
        }
        if ($now - (int) $decoded['start'] >= $windowSeconds) {
            return ['count' => 0, 'start' => $now];
        }
        return ['count' => (int) $decoded['count'], 'start' => (int) $decoded['start']];
    }

    private static function write(string $key, array $data): void
    {
        $file = self::file($key);
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return;
        }
        if (@flock($handle, LOCK_EX)) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data));
            fflush($handle);
            @flock($handle, LOCK_UN);
        }
        @fclose($handle);
        // جلوگیری از رشد بی‌پایان فایل‌ها در پنجره‌های خلوت
        if (random_int(1, 200) === 1) {
            self::cleanup(86400);
        }
    }

    private static function cleanup(int $maxAge): void
    {
        $now = time();
        foreach (glob(self::dir() . '/*.json') ?: [] as $file) {
            if ($now - (int) @filemtime($file) > $maxAge) {
                @unlink($file);
            }
        }
    }
}

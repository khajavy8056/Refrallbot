<?php
declare(strict_types=1);

/** چارچوب تست سبک بدون وابستگی خارجی */

final class T
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static array $failures = [];
    private static string $current = '';

    public static function suite(string $name): void
    {
        self::$current = $name;
        echo "\n▶ {$name}\n";
    }

    public static function ok(bool $condition, string $message): void
    {
        if ($condition) {
            self::$passed++;
            echo "  ✔ {$message}\n";
            return;
        }
        self::$failed++;
        self::$failures[] = self::$current . ' :: ' . $message;
        echo "  ✘ {$message}\n";
    }

    public static function eq(mixed $expected, mixed $actual, string $message): void
    {
        $same = $expected === $actual;
        if (!$same) {
            $message .= ' — انتظار: ' . var_export($expected, true) . ' | دریافت: ' . var_export($actual, true);
        }
        self::ok($same, $message);
    }

    public static function throws(callable $fn, string $message, ?string $contains = null): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($contains !== null && !str_contains($e->getMessage(), $contains)) {
                self::ok(false, $message . ' — پیام خطا: ' . $e->getMessage());
                return;
            }
            self::ok(true, $message);
            return;
        }
        self::ok(false, $message . ' — خطایی پرتاب نشد');
    }

    public static function summary(): int
    {
        echo "\n" . str_repeat('─', 60) . "\n";
        echo "نتیجه: " . self::$passed . " موفق، " . self::$failed . " ناموفق\n";
        if (self::$failures) {
            echo "\nشکست‌ها:\n";
            foreach (self::$failures as $failure) {
                echo " • {$failure}\n";
            }
        }
        return self::$failed === 0 ? 0 : 1;
    }
}

/** ساخت کاربر تست با داده‌های مشخص */
function makeUser(array $tg, ?string $refCode = null): array
{
    $service = new RB\Services\UserService();
    return $service->registerFromTelegram($tg, $refCode)['user'];
}

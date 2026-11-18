<?php
declare(strict_types=1);

namespace RB\Controllers;

use RB\Services\CronService;
use RB\Support\ErrorHandler;
use RB\Support\Request;

/**
 * Cron وب — برای هاست اشتراکی که crontab در اختیار کاربر نیست.
 *
 * آدرس: /cron/run?token=TOKEN&task=all
 *
 * توکن در اولین بازدید پنل مدیریت (بخش «ربات تلگرام») ساخته و نمایش داده می‌شود.
 * بدون توکن صحیح، پاسخ 403 است. مقایسه با hash_equals انجام می‌شود
 * تا در برابر حمله‌ی زمان‌سنجی مقاوم باشد.
 */
final class CronController
{
    public function run(): void
    {
        $expected = CronService::webToken();
        $provided = (string) ($_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));

        if ($expected === '') {
            json_out([
                'ok' => false,
                'error' => 'توکن Cron ساخته نشده است. ابتدا وارد پنل مدیریت شوید تا توکن ساخته شود.',
            ], 403);
            return;
        }

        if (!hash_equals($expected, $provided)) {
            // ثبت تلاش ناموفق برای دیده‌شدن در لاگ امنیتی
            if (!headers_sent()) {
                header('X-Robots-Tag: noindex');
            }
            json_out(['ok' => false, 'error' => 'توکن Cron نامعتبر است.'], 403);
            return;
        }

        $task = (string) ($_GET['task'] ?? 'all');
        if (!in_array($task, CronService::TASKS, true)) {
            $task = 'all';
        }

        try {
            $service = new CronService(null, static function (string $message): void {
                // لاگ هر کار در فایل لاگ برنامه
                ErrorHandler::write('INFO', 'cron: ' . $message);
            });
            $result = $service->run($task);
            json_out([
                'ok' => $result['ok'],
                'task' => $result['task'],
                'ms' => $result['ms'],
                'output' => $result['output'],
                'at' => now(),
            ], $result['ok'] ? 200 : 500);
        } catch (\Throwable $e) {
            json_out([
                'ok' => false,
                'error' => ErrorHandler::redact($e->getMessage()),
                'triggered_by' => Request::ip(),
            ], 500);
        }
    }
}

#!/usr/bin/env php
<?php
/**
 * اجرای وظایف زمان‌بندی‌شده — برای Cron خط فرمان روی هاست اشتراکی
 *
 * نمونه تنظیمات Crontab (ارسال همگانی هر ۲ دقیقه + وظایف اصلی هر ۱۰ دقیقه):
 *   *\/2 * * * * /usr/local/bin/php /home/USER/public_html/bin/cron.php broadcast >/dev/null 2>&1
 *   0,10,20,30,40,50 * * * * /usr/local/bin/php /home/USER/public_html/bin/cron.php all >/dev/null 2>&1
 *
 * اگر هاست به شما crontab نمی‌دهد، از Cron وب استفاده کنید
 * (آدرس و توکن آن در پنل مدیریت → اتصال به ربات → «کرون وب» نمایش داده می‌شود):
 *   https://YOUR-DOMAIN/cron/run?token=TOKEN&task=all
 *
 * منطق کارها در RB\Services\CronService است تا هر دو مسیر یک رفتار داشته باشند.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('این اسکریپت فقط از خط فرمان قابل اجراست.');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use RB\Services\CronService;
use RB\Services\SettingsService;
use RB\Services\TelegramService;
use RB\Support\Database;

$task = $argv[1] ?? 'all';
$quiet = in_array('--quiet', $argv, true) || in_array('-q', $argv, true);

$log = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    }
};

$log('شروع وظیفه: ' . $task);

try {
    $db = Database::instance();

    // وظیفه poll تکلیف جداست (نیازمند توکن ربات و نبود Webhook)
    if ($task === 'poll') {
        $tg = new TelegramService(null, $db);
        if (!$tg->isConfigured()) {
            $log('توکن ربات تنظیم نشده است. عملیات متوقف شد.');
            exit(1);
        }
        if (!empty(TelegramService::settings($db)['webhook_active'])) {
            $log('Webhook فعال است؛ استفاده هم‌زمان از Polling باعث تعارض می‌شود. ابتدا Webhook را حذف کنید.');
            exit(1);
        }
        $offset = (int) (SettingsService::get('poll_offset', '0') ?? 0);
        $handler = new RB\Bot\BotHandler($db);
        $response = $tg->getUpdates($offset, 30, 100);
        if (!($response['ok'] ?? false)) {
            $log('خطا در دریافت آپدیت‌ها: ' . ($response['description'] ?? 'نامشخص'));
            exit(1);
        }
        $processed = 0;
        foreach ((array) ($response['result'] ?? []) as $update) {
            $updateId = (int) ($update['update_id'] ?? 0);
            if ($updateId <= 0) {
                continue;
            }
            $handler->handle($update);
            $offset = $updateId + 1;
            $processed++;
        }
        SettingsService::set('poll_offset', (string) $offset, 'telegram');
        $log('پردازش شد: ' . $processed . ' آپدیت. آفست فعلی: ' . $offset);
        exit(0);
    }

    $result = (new CronService($db, $log))->run($task);
    $log('پایان ' . ($result['ok'] ? 'موفق' : 'ناموفق') . ' در ' . $result['ms'] . ' میلی‌ثانیه.');
    exit($result['ok'] ? 0 : 1);
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] خطا: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

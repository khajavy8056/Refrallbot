#!/usr/bin/env php
<?php
/**
 * دریافت پیام‌های تلگرام با روش getUpdates (Long Polling کوتاه)
 *
 * استفاده: وقتی HTTPS معتبر برای Webhook در دسترس نیست.
 * این اسکریپت را با Cron هر دقیقه اجرا کنید؛ هر بار حداکثر ۳۰ ثانیه درخواست را باز نگه می‌دارد.
 *
 *   * * * * * php /path/bin/poll.php --quiet
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('RB_TEST_MODE') && getenv('RB_TEST_MODE') !== '1' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit('این اسکریپت فقط از خط فرمان قابل اجراست.');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use RB\Bot\BotHandler;
use RB\Services\SettingsService;
use RB\Services\TelegramService;
use RB\Support\Database;

$quiet = in_array('--quiet', $argv, true) || in_array('-q', $argv, true);
$log = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    }
};

$db = Database::instance();
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
$handler = new BotHandler($db);
$processed = 0;

try {
    $response = $tg->getUpdates($offset, 30, 100);
    if (!($response['ok'] ?? false)) {
        $log('خطا در دریافت آپدیت‌ها: ' . ($response['description'] ?? 'نامشخص'));
        exit(1);
    }
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
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] خطا: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

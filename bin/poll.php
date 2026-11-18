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

// ------------------------------------------------------------------ قفل اجرا
//
// اگر Cron هر دقیقه اجرا شود و اجرای قبلی هنوز در حال Long-Polling باشد،
// دو نمونه هم‌زمان می‌توانند یک آپدیت را دو بار پردازش کنند. تلگرام برای
// همین حالت خطای 409 Conflict می‌دهد. قفل فایلی جلوی این وضعیت را می‌گیرد.
$lockPath = RB_STORAGE . '/cache/poll.lock';
if (!is_dir(dirname($lockPath))) {
    @mkdir(dirname($lockPath), 0775, true);
}
$lockHandle = @fopen($lockPath, 'c');
if ($lockHandle === false) {
    $log('قفل Polling باز نشد (دسترسی نوشتن storage/cache).');
    exit(1);
}
if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $log('یک نمونهٔ Polling دیگر در حال اجراست؛ این اجرا نادیده گرفته شد.');
    exit(0); // خروج ۰ تا Cron آن را خطا حساب نکند
}
register_shutdown_function(static function () use ($lockHandle): void {
    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
});

// ------------------------------------------------------------------ وضعیت واقعی Webhook
//
// Webhook و Polling هم‌زمان کار نمی‌کنند. وضعیت واقعی را از خود تلگرام
// می‌پرسیم (نه فقط از دیتابیس) تا اگر Webhook فعال است، Polling شروع نشود.
$webhookInfo = $tg->getWebhookInfo();
if (($webhookInfo['ok'] ?? false) && !empty($webhookInfo['result']['url'])) {
    $log('Webhook فعال است (' . $webhookInfo['result']['url'] . ')؛ Polling اجرا نمی‌شود.');
    exit(0);
}

$offset = (int) (SettingsService::get('poll_offset', '0') ?? 0);
$dedupe = new \RB\Services\UpdateDeduplicator($db);
$handler = new BotHandler($db);
$processed = 0;
$skipped = 0;

// تلاش با Backoff برای خطاهای موقت (429 / 5xx)
$maxAttempts = 3;
$delayMs = 1000;
$response = null;
for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $response = $tg->getUpdates($offset, 30, 100);
    if (($response['ok'] ?? false) === true) {
        break;
    }

    $errorCode = (int) ($response['error_code'] ?? 0);
    $retryAfter = (int) ($response['parameters']['retry_after'] ?? 0);

    if ($errorCode === 429) {
        $wait = $retryAfter > 0 ? $retryAfter : 5;
        $log('محدودیت نرخ تلگرام (429)؛ انتظار ' . $wait . ' ثانیه.');
        if ($attempt < $maxAttempts) {
            sleep($wait);
            continue;
        }
        exit(0);
    }
    if ($errorCode === 409) {
        $log('تلگرام 409 داد: Webhook یا نمونهٔ Polling دیگری فعال است. بررسی کنید.');
        exit(0);
    }
    if ($errorCode >= 500 || $errorCode === 0) {
        $jitter = random_int(0, 250);
        $log('خطای موقت تلگرام (' . $errorCode . '): ' . ($response['description'] ?? 'نامشخص'));
        if ($attempt < $maxAttempts) {
            usleep(($delayMs + $jitter) * 1000);
            $delayMs *= 2;
            continue;
        }
        exit(1);
    }

    // خطای قطعی (401 توکن نامعتبر، 400 درخواست بد)
    $log('خطای تلگرام (' . $errorCode . '): ' . ($response['description'] ?? 'نامشخص'));
    exit(1);
}

if (!is_array($response) || ($response['ok'] ?? false) !== true) {
    $log('دریافت آپدیت‌ها ناموفق بود.');
    exit(1);
}

foreach ((array) ($response['result'] ?? []) as $update) {
    $updateId = (int) ($update['update_id'] ?? 0);
    if ($updateId <= 0) {
        continue;
    }

    // ضدتکرار: اگر این آپدیت قبلاً پردازش شده، نادیده بگیر
    $claim = $dedupe->begin($updateId, $update);
    if (!$claim['proceed']) {
        $skipped++;
        // offset را جلو می‌بریم تا آپدیت تکراری دوباره دریافت نشود
        // (و ذخیره می‌کنیم؛ وگرنه هر اجرا همان آپدیت را باز می‌خواند)
        $offset = $updateId + 1;
        SettingsService::set('poll_offset', (string) $offset, 'telegram');
        continue;
    }

    try {
        $handler->handle($update);
        $dedupe->done($claim['id']);
        // offset فقط بعد از پردازش موفق ذخیره می‌شود
        $offset = $updateId + 1;
        SettingsService::set('poll_offset', (string) $offset, 'telegram');
        $processed++;
    } catch (\Throwable $e) {
        // آپدیت را از دست نمی‌دهیم: offset ثبت نمی‌شود تا دور بعد دوباره بیاید
        $dedupe->fail($claim['id'], $e->getMessage());
        $log('خطا در پردازش آپدیت ' . $updateId . ': ' . $e->getMessage());
        break;
    }
}

SettingsService::set('poll_offset', (string) $offset, 'telegram');

// پاک‌سازی دوره‌ای جدول ضدتکرار (بدون وابستگی به Cron دیگر)
try {
    $dedupe->prune();
} catch (\Throwable) {
    // بی‌اهمیت
}

$log('پردازش شد: ' . $processed . ' | تکراری: ' . $skipped . ' | آفست: ' . $offset);
exit(0);

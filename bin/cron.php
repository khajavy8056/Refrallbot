#!/usr/bin/env php
<?php
/**
 * اجرای وظایف زمان‌بندی‌شده — برای Cron روی هاست اشتراکی
 *
 * نمونه تنظیمات Crontab (ارسال همگانی هر ۲ دقیقه + وظایف اصلی هر ۱۰ دقیقه):
 *   *\/2 * * * * /usr/local/bin/php /home/USER/public_html/bin/cron.php broadcast >/dev/null 2>&1
 *   0,10,20,30,40,50 * * * * /usr/local/bin/php /home/USER/public_html/bin/cron.php all >/dev/null 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('این اسکریپت فقط از خط فرمان قابل اجراست.');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use RB\Services\BroadcastService;
use RB\Services\CampaignService;
use RB\Services\SecurityService;
use RB\Services\UserService;
use RB\Support\Database;

$task = $argv[1] ?? 'all';
$quiet = in_array('--quiet', $argv, true) || in_array('-q', $argv, true);
$startedAt = microtime(true);

$log = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    }
};

$log('شروع وظیفه: ' . $task);

try {
    $db = Database::instance();

    if (in_array($task, ['broadcast', 'all'], true)) {
        $service = new BroadcastService($db);
        $sent = $service->processQueue(25);
        $log('ارسال همگانی: ' . $sent . ' پیام پردازش شد.');

        // تلاش مجدد یک‌باره برای پیام‌های ناموفق کمپین‌های فعال
        $failedCampaigns = $db->all(
            "SELECT id FROM broadcast_campaigns WHERE status IN ('sending','failed') ORDER BY id DESC LIMIT 5"
        );
        foreach ($failedCampaigns as $row) {
            $retried = $service->retryFailed((int) $row['id']);
            if ($retried > 0) {
                $db->update('broadcast_campaigns', ['status' => 'queued', 'updated_at' => now()], 'id = :id', ['id' => (int) $row['id']]);
                $log('تلاش مجدد برای ' . $retried . ' پیام ناموفق کمپین #' . $row['id']);
            }
        }
    }

    if (in_array($task, ['campaigns', 'all'], true)) {
        $granted = (new CampaignService($db))->processRewards();
        $log('جوایز کمپین‌ها: ' . $granted . ' جایزه پرداخت شد.');
    }

    if (in_array($task, ['maintenance', 'all'], true)) {
        // پاکسازی نشست‌های منقضی ربات
        $db->run('DELETE FROM bot_sessions WHERE expires_at < :now', ['now' => now()]);
        // پاکسازی توکن‌های ورود یک‌بارمصرف منقضی
        $stmt = $db->all("SELECT id, `value` FROM system_settings WHERE `key` LIKE 'magic_login:%'");
        foreach ($stmt as $row) {
            $payload = json_decode((string) $row['value'], true) ?: [];
            if ((int) ($payload['expires'] ?? 0) < time()) {
                $db->delete('system_settings', 'id = :id', ['id' => (int) $row['id']]);
            }
        }
        // بازسازی شمارنده‌های شبکه در صورت ناسازگاری
        $users = new UserService($db);
        $users->refreshNetworkCounters();
        $log('نگهداری: پاکسازی و بازسازی شمارنده‌ها انجام شد.');
    }

    if (in_array($task, ['backup', 'all'], true)) {
        $dir = RB_STORAGE . '/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/refrallbot-db-' . date('Ymd') . '.sql';
        if (!is_file($file)) {
            $handle = fopen($file, 'wb');
            if ($handle !== false) {
                fwrite($handle, "-- RefrallBot automatic backup " . date('c') . "\n");
                foreach ($db->all("SELECT name FROM sqlite_master WHERE type='table'") as $row) {
                    fwrite($handle, '-- table ' . $row['name'] . "\n");
                }
                fclose($handle);
                $log('پشتیبان روزانه ساخته شد: ' . basename($file));
            }
        }
        // نگه‌داشتن فقط ۱۴ فایل آخر
        $files = glob($dir . '/refrallbot-db-*.sql') ?: [];
        sort($files);
        while (count($files) > 14) {
            @unlink((string) array_shift($files));
        }
    }

    if (in_array($task, ['security', 'all'], true)) {
        // ارزیابی مجدد کاربران با ریسک بالا
        $rows = $db->all("SELECT id FROM users WHERE (status = 'suspicious' OR risk_score >= 20) AND status <> 'blocked' LIMIT 200");
        foreach ($rows as $row) {
            SecurityService::evaluateUser((int) $row['id']);
        }
        $log('امنیت: ' . count($rows) . ' کاربر ارزیابی شد.');
    }

    $duration = round((microtime(true) - $startedAt) * 1000);
    $log('پایان موفق در ' . $duration . ' میلی‌ثانیه.');
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] خطا: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

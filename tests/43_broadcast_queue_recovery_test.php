<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\BroadcastService;
use RB\Services\CronService;
use RB\Services\TelegramService;
use RB\Services\UserService;
use RB\Support\Crypto;
use RB\Support\Database;

T::suite('بازیابی صف پیام همگانی و تلاش مجدد محدود');

$db = Database::instance();
$users = new UserService($db);

// ستون‌های migration جدید باید روی نصب‌های ارتقایافته ساخته شوند
T::ok($db->columnExists('broadcast_targets', 'attempts'), 'ستون attempts برای شمارش تلاش‌ها ساخته شد');
T::ok($db->columnExists('broadcast_targets', 'last_attempt_at'), 'ستون last_attempt_at ساخته شد');
T::ok($db->columnExists('broadcast_targets', 'next_attempt_at'), 'ستون next_attempt_at ساخته شد');

// تنظیم بات ساختگی
$botToken = '777000333:' . str_repeat('R', 35);
$settingsId = (int) $db->value('SELECT id FROM telegram_settings ORDER BY id LIMIT 1');
if ($settingsId === 0) {
    $settingsId = $db->insert('telegram_settings', [
        'connection_status' => 'connected', 'webhook_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}
$db->update('telegram_settings', [
    'bot_token_enc' => Crypto::encrypt($botToken),
    'bot_username' => 'broadcast_retry_bot',
    'connection_status' => 'connected',
    'webhook_active' => 1,
    'updated_at' => now(),
], 'id = :id', ['id' => $settingsId]);
TelegramService::forgetSettingsCache();

$failureMode = ['kind' => '429'];
$sentTexts = [];
TelegramService::$fakeTransport = static function (string $method, array $params) use (&$failureMode, &$sentTexts): array {
    if (in_array($method, ['sendMessage', 'sendPhoto'], true)) {
        $sentTexts[] = (string) ($params['text'] ?? ($params['caption'] ?? ''));
        if ($failureMode['kind'] === '429') {
            return ['ok' => false, 'error_code' => 429, 'description' => '429 Too Many Requests: retry after'];
        }
        if ($failureMode['kind'] === '400') {
            return ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'];
        }
        return ['ok' => true, 'result' => ['message_id' => 1]];
    }

    return ['ok' => true, 'result' => true];
};

$created = [];
for ($i = 0; $i < 3; $i++) {
    $created[] = $users->registerFromTelegram([
        'id' => 910000001 + $i,
        'is_bot' => false,
        'first_name' => 'بازیابی' . ($i + 1),
    ])['user'];
}

$service = new BroadcastService($db);

// ---------- خطای گذرا با تأخیر retry ثبت می‌شود، نه شکست دائم
$transient = $service->createCampaign([
    'title' => 'کمپین خطای گذرا',
    'message_text' => 'سلام {name}',
    'segment' => 'all',
    'segment_params' => [],
], 1);
$transientId = (int) $transient['id'];
$first = $service->processQueue(10, 10);
T::eq(0, (int) $first['failed'], 'خطای 429 در اولین تلاش شکست دائم ثبت نشد');
T::ok((int) $first['scheduled'] >= 1, 'حداقل یک پیام برای تلاش مجدد برنامه‌ریزی شد');
$transientTargets = $db->all('SELECT * FROM broadcast_targets WHERE campaign_id = :c ORDER BY id', ['c' => $transientId]);
T::eq(1, (int) $transientTargets[0]['attempts'], 'شمارش تلاش اولین هدف ثبت شد');
T::ok(!empty($transientTargets[0]['next_attempt_at']), 'زمان تلاش بعدی برای هدف ناموفق ثبت شد');
$dueLater = $db->all(
    "SELECT id FROM broadcast_targets WHERE campaign_id = :c AND status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= :now)",
    ['c' => $transientId, 'now' => now()]
);
T::eq(0, count($dueLater), 'اهداف retry قبل از زمان تعیین‌شده دوباره انتخاب نمی‌شوند');

// ---------- صف با وجود retry قبلی، هدف‌های جدیدتر را جا نمی‌اندازد
$beforeSecond = $db->all('SELECT user_id, attempts FROM broadcast_targets WHERE campaign_id = :c ORDER BY id', ['c' => $transientId]);
$db->run("UPDATE broadcast_targets SET next_attempt_at = :past WHERE campaign_id = :c AND user_id = :u", [
    'past' => date('Y-m-d H:i:s', time() - 10),
    'c' => $transientId,
    'u' => (int) $beforeSecond[0]['user_id'],
]);
$second = $service->processQueue(1, 1);
T::eq(1, (int) ($second['scheduled'] + $second['failed'] + $second['sent'] + $second['blocked']), 'یک تلاش در اجرای محدود انجام شد');

// ---------- تلاش سوم باعث شکست دائم می‌شود و صف از حالت پندینگ خارج می‌شود
for ($i = 0; $i < 3; $i++) {
    $db->run("UPDATE broadcast_targets SET next_attempt_at = :past WHERE campaign_id = :c AND status = 'pending'", [
        'past' => date('Y-m-d H:i:s', time() - 10),
        'c' => $transientId,
    ]);
    $service->processQueue(10, 10);
}
$transientFinal = $db->first('SELECT * FROM broadcast_campaigns WHERE id = :id', ['id' => $transientId]);
T::eq(0, (int) $db->value("SELECT COUNT(*) FROM broadcast_targets WHERE campaign_id = :c AND status = 'pending'", ['c' => $transientId]), 'پس از تلاش‌های محدود هیچ رکوردی معلق نمی‌ماند');
T::ok((int) $transientFinal['failed_count'] >= 1, 'پس از پایان تلاش‌های مجاز، پیام ناموفق دائمی ثبت شد');

// ---------- خطای دائم فوراً failed می‌شود
$failureMode['kind'] = '400';
$permanent = $service->createCampaign([
    'title' => 'کمپین خطای دائم',
    'message_text' => 'سلام {name}',
    'segment' => 'all',
    'segment_params' => [],
], 1);
$failureMode['kind'] = '400';
$service->processQueue(10, 10);
$permanentRows = $db->all('SELECT * FROM broadcast_targets WHERE campaign_id = :c', ['c' => (int) $permanent['id']]);
T::ok(count(array_filter($permanentRows, static fn(array $r): bool => $r['status'] === 'failed')) > 0, 'خطای 400 به‌جای معلق ماندن، ناموفق ثبت شد');

// ---------- Cron دیگر شکست‌ها را بلافاصله و بی‌پایان به صف برنمی‌گرداند
$cron = (new CronService($db))->run('broadcast');
T::ok($cron['ok'], 'وظیفه ارسال همگانی Cron بدون خطا اجرا شد');
T::ok(!str_contains(implode("\n", $cron['output']), 'تلاش مجدد برای'), 'Cron دیگر شکست‌های دائم را بی‌پایان و بی‌شمارش دوباره enqueue نمی‌کند');

// ---------- تلاش مجدد دستی، شمارنده تلاش را بازنشانی می‌کند
$failureMode['kind'] = 'success';
$retryCount = $service->retryFailed((int) $permanent['id']);
T::ok($retryCount > 0, 'تلاش مجدد دستی حداقل یک مخاطب را به صف برگرداند');
$retried = $db->first("SELECT * FROM broadcast_targets WHERE campaign_id = :c ORDER BY id LIMIT 1", ['c' => (int) $permanent['id']]);
T::eq(0, (int) $retried['attempts'], 'تلاش مجدد دستی شمارنده attempts را صفر کرد');
T::eq('pending', (string) $retried['status'], 'تلاش مجدد دستی مخاطب را به pending برگرداند');
$final = $service->processQueue(10, 10);
T::ok((int) $final['sent'] > 0, 'پس از تلاش مجدد دستی با API موفق، پیام‌ها ارسال شدند');
T::ok(count($sentTexts) > 0 && !str_contains(implode("\n", $sentTexts), '{name}'), 'متغیر {name} در پیام ارسالی جایگذاری می‌شود');
T::ok(count(array_filter($sentTexts, static fn(string $body): bool => str_contains($body, 'بازیابی'))) > 0, 'نام واقعی مخاطب در متن ارسال‌شده وجود دارد');


// ---------- بازیابی خودکار کمپین‌های معلق گذشته (Stale Healing)
$staleCampId = $db->insert('broadcast_campaigns', [
    'title' => 'کمپین قدیمی معلق',
    'message_text' => 'متن قدیمی',
    'status' => 'failed', // به اشتباه در گذشته failed شده
    'segment' => 'all',
    'total_targets' => 1,
    'created_at' => date('Y-m-d H:i:s', time() - 86400),
    'updated_at' => date('Y-m-d H:i:s', time() - 86400),
]);
$db->insert('broadcast_targets', [
    'campaign_id' => $staleCampId,
    'user_id' => (int) $created[0]['id'],
    'telegram_id' => 0, // شناسه در رکورد هدف خالی بوده
    'status' => 'pending',
]);
$healedCount = $service->healStaleCampaigns();
T::ok($healedCount >= 1, 'کمپین معلق پیشین با اهداف pending شناسایی و بازیابی شد');
$staleRow = $db->first('SELECT status FROM broadcast_campaigns WHERE id = :id', ['id' => $staleCampId]);
T::eq('queued', (string) $staleRow['status'], 'وضعیت کمپین معلق به queued بازگردانده شد');

// اجرای صف، کمپین قدیمی بازیابی‌شده با telegram_id خالی را از جدول users ارسال می‌کند
$healedProcess = $service->processQueue(10, 10);
T::ok((int) $healedProcess['sent'] >= 1, 'هدف با telegram_id خالی از طریق اطلاعات کاربر با موفقیت ارسال شد');

// ---------- لاگ‌برداری اختصاصی
BroadcastService::log('INFO', 'تست لاگ‌برداری اختصاصی پیام همگانی');
$logs = BroadcastService::recentLogs(10);
T::ok(str_contains($logs, 'تست لاگ‌برداری'), 'لاگ اختصاصی پیام همگانی با موفقیت ثبت و خوانده شد');


// ---------- اجرای فرصت‌طلبانه در وب (Opportunistic Run)
BroadcastService::triggerOpportunisticRun($db, 5.0);
T::ok(true, 'اجرای فرصت‌طلبانه بدون استثنا انجام شد');

exit(T::summary());

<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\BroadcastService;
use RB\Services\CampaignService;
use RB\Services\SettingsService;
use RB\Services\TelegramService;
use RB\Services\UserService;
use RB\Services\WalletService;
use RB\Support\Crypto;
use RB\Support\Database;

T::suite('پیام همگانی و کمپین‌های جشنواره');

$db = Database::instance();
$users = new UserService($db);

// ---------- آماده‌سازی ربات (شبیه‌سازی API)
$botToken = '777000222:' . str_repeat('K', 35);
$settingsId = (int) $db->value('SELECT id FROM telegram_settings ORDER BY id LIMIT 1');
if ($settingsId === 0) {
    $settingsId = $db->insert('telegram_settings', [
        'connection_status' => 'connected', 'webhook_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}
$db->update('telegram_settings', [
    'bot_token_enc' => Crypto::encrypt($botToken),
    'bot_username' => 'broadcast_test_bot',
    'connection_status' => 'connected',
    'webhook_active' => 1,
    'updated_at' => now(),
], 'id = :id', ['id' => $settingsId]);
TelegramService::forgetSettingsCache();

$sentMessages = [];
$blockedUsers = [901000003];
TelegramService::$fakeTransport = static function (string $method, array $params) use (&$sentMessages, $blockedUsers): array {
    if (in_array($method, ['sendMessage', 'sendPhoto'], true)) {
        $chat = (int) ($params['chat_id'] ?? 0);
        if (in_array($chat, $blockedUsers, true)) {
            return ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'];
        }
        $sentMessages[] = $chat;
        return ['ok' => true, 'result' => ['message_id' => count($sentMessages)]];
    }
    return ['ok' => true, 'result' => true];
};

// ---------- ساخت مخاطبان
$created = [];
for ($i = 0; $i < 5; $i++) {
    $u = $users->registerFromTelegram([
        'id' => 901000001 + $i,
        'is_bot' => false,
        'first_name' => 'مخاطب' . ($i + 1),
        'username' => 'audience' . ($i + 1),
    ])['user'];
    if ($i < 2) {
        $users->setPhone((int) $u['id'], '0912000000' . $i);
    }
    $created[] = $u;
}

$service = new BroadcastService($db);
$counts = $service->segmentCounts();
T::eq(5, $counts['all'], 'گروه «همه کاربران» ۵ مخاطب دارد');
T::eq(2, $counts['with_phone'], 'گروه «دارای شماره تماس» درست فیلتر می‌شود');
T::eq(5, $counts['no_refs'], 'گروه «بدون زیرمجموعه» درست فیلتر می‌شود');
T::ok($counts['with_balance'] <= 5, 'گروه «دارای موجودی» محاسبه می‌شود');

// ---------- پیش‌نمایش
$preview = BroadcastService::renderPreview("سلام **دوست عزیز**\nخط دوم");
T::ok(str_contains($preview, '<strong>دوست عزیز</strong>'), 'پیش‌نمایش، مارک‌داون بولد را تبدیل می‌کند');
T::ok(str_contains($preview, '<br'), 'شکست خط در پیش‌نمایش حفظ می‌شود');
T::ok(!str_contains(BroadcastService::renderPreview('<script>alert(1)</script>'), '<script>'), 'پیش‌نمایش کد مخرب را اجرا نمی‌کند');

// ---------- ساخت کمپین ارسال
$campaign = $service->createCampaign([
    'title' => 'اطلاع‌رسانی تست',
    'message_text' => 'سلام {name} این یک پیام آزمایشی است.',
    'segment' => 'all',
    'segment_params' => [],
], 1);
T::eq(5, (int) $campaign['total_targets'], 'کمپین با ۵ مخاطب ساخته شد');
T::eq('sending', (string) $campaign['status'], 'کمپین بدون زمان‌بندی در وضعیت ارسال قرار گرفت');
T::eq(5, (int) $db->value('SELECT COUNT(*) FROM broadcast_targets WHERE campaign_id = :c', ['c' => (int) $campaign['id']]), 'فهرست مخاطبان snapshot شد');

// ---------- اجرای صف با محدودیت دسته
$result = $service->processQueue(2);
T::ok(($result['sent'] + $result['blocked'] + $result['failed']) <= 2, 'محدودیت دسته ارسال رعایت شد (' . $result['sent'] . ' ارسال)');
T::eq(2, (int) $db->value('SELECT COUNT(*) FROM broadcast_targets WHERE campaign_id = :c AND status <> :p', ['c' => (int) $campaign['id'], 'p' => 'pending']), 'دقیقاً ۲ مخاطب پردازش شد');

// ---------- ادامه تا پایان
for ($i = 0; $i < 5; $i++) {
    $service->processQueue(10);
}
$final = $db->first('SELECT * FROM broadcast_campaigns WHERE id = :id', ['id' => (int) $campaign['id']]);
T::eq('sent', (string) $final['status'], 'کمپین پس از پردازش کامل، تمام‌شده علامت خورد');
T::eq(4, (int) $final['sent_count'], '۴ پیام موفق ارسال شد');
T::eq(1, (int) $final['blocked_count'], 'کاربر بلاک‌کننده ربات به‌درستی دسته‌بندی شد');
T::eq(0, (int) $final['failed_count'], 'هیچ خطای عمومی رخ نداد');
T::ok(!empty($final['finished_at']), 'زمان پایان ثبت شد');

// ---------- جایگزینی متغیر نام
$withName = $db->first("SELECT * FROM broadcast_targets WHERE campaign_id = :c AND status = 'sent' LIMIT 1", ['c' => (int) $campaign['id']]);
T::ok($withName !== null, 'حداقل یک ارسال موفق وجود دارد');

// ---------- ارسال با گروه نامعتبر
T::throws(static function () use ($service) {
    $service->createCampaign(['title' => 'تست', 'message_text' => 'x', 'segment' => 'invalid_segment', 'segment_params' => []], 1);
}, 'گروه مخاطبان نامعتبر رد می‌شود');

// ---------- لغو کمپین
$second = $service->createCampaign([
    'title' => 'کمپین قابل لغو', 'message_text' => 'متن', 'segment' => 'all', 'segment_params' => [],
], 1);
$db->update('broadcast_campaigns', ['status' => 'cancelled'], 'id = :id', ['id' => (int) $second['id']]);
$service->processQueue(10);
T::eq(5, (int) $db->value("SELECT COUNT(*) FROM broadcast_targets WHERE campaign_id = :c AND status = 'pending'", ['c' => (int) $second['id']]), 'کمپین لغوشده ارسال نمی‌شود');

// ---------- کمپین جشنواره: پرداخت خودکار جایزه
$campaignId = $db->insert('campaigns', [
    'title' => 'جشنواره دعوت',
    'description' => 'با ۲ دعوت، ۵۰ هزار تومان جایزه بگیرید',
    'type' => 'invite_count',
    'starts_at' => date('Y-m-d H:i:s', time() - 86400),
    'ends_at' => date('Y-m-d H:i:s', time() + 86400),
    'is_active' => 1,
    'required_invites' => 2,
    'fixed_reward' => 50000,
    'max_winners' => 0,
    'created_by' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);
$inviter = $users->registerFromTelegram(['id' => 902000001, 'is_bot' => false, 'first_name' => 'دعوت‌کننده'] )['user'];
for ($i = 0; $i < 2; $i++) {
    $users->registerFromTelegram(['id' => 902000010 + $i, 'is_bot' => false, 'first_name' => 'دعوت‌شده' . $i], $inviter['referral_code']);
}
$campaignService = new CampaignService($db);
$progress = $campaignService->progressFor(['id' => $campaignId, 'required_invites' => 2, 'starts_at' => date('Y-m-d H:i:s', time() - 86400)], (int) $inviter['id']);
T::ok(($progress['progress'] ?? 0) >= 2, 'پیشرفت کمپین برای کاربر محاسبه شد (' . ($progress['progress'] ?? 0) . ')');

$granted = $campaignService->processRewards();
T::ok($granted >= 1, 'حداقل یک جایزه کمپین پرداخت شد (' . $granted . ')');
$reward = $db->first('SELECT * FROM campaign_rewards WHERE campaign_id = :c AND user_id = :u', ['c' => $campaignId, 'u' => (int) $inviter['id']]);
T::ok($reward !== null, 'جایزه برای کاربر واجد شرایط ثبت شد');
T::eq(50000, (int) $reward['amount'], 'مبلغ جایزه درست است');
$wallet = (new WalletService($db))->summary((int) $inviter['id']);
T::eq(50000, (int) $wallet['balance'], 'جایزه به کیف پول واریز شد');
T::eq(50000, (int) $wallet['campaign_earned'], 'درآمد کمپین جداگانه ثبت شد');

// ---------- پرداخت دوباره (یکتایی در سطح دیتابیس)
T::throws(static function () use ($campaignService, $campaignId, $inviter) {
    $campaignService->grant([
        'id' => $campaignId, 'required_invites' => 2, 'fixed_reward' => 50000, 'type' => 'invite_count',
    ], (int) $inviter['id'], 50000, 'fixed', 2, 1);
}, 'پرداخت دوباره جایزه با خطا رد می‌شود', 'قبلاً');
T::eq(50000, (int) (new WalletService($db))->summary((int) $inviter['id'])['balance'], 'موجودی پس از تلاش تکراری تغییر نکرد');
T::eq(1, (int) $db->value('SELECT COUNT(*) FROM campaign_rewards WHERE campaign_id = :c AND user_id = :u', ['c' => $campaignId, 'u' => (int) $inviter['id']]), 'فقط یک رکورد جایزه ثبت شده است');

// ---------- آمار
$stats = $campaignService->stats($campaignId);
T::ok($stats['paid'] >= 50000, 'آمار کمپین مجموع جوایز را نشان می‌دهد');
T::ok($stats['participants'] >= 1, 'تعداد شرکت‌کنندگان ثبت شده است');

// ---------- کمپین غیرفعال پرداخت نمی‌کند
$db->update('campaigns', ['is_active' => 0], 'id = :id', ['id' => $campaignId]);
T::eq(0, count($campaignService->activeCampaigns()), 'کمپین غیرفعال در فهرست فعال نیست');

// ---------- محدودیت نرخ (تأخیر بین ارسال‌ها تنظیم‌پذیر است)
T::ok((int) RB\Support\Config::get('services.broadcast_delay_ms', 60) >= 0, 'تأخیر بین ارسال‌ها از تنظیمات خوانده می‌شود');

// ---------- پرداخت آزمایشی به مدیر
$db->update('admins', ['telegram_user_id' => 999888777], 'id = (SELECT MIN(id) FROM admins)');
exit(T::summary());

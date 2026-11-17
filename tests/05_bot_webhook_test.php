<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Bot\BotHandler;
use RB\Services\SettingsService;
use RB\Services\TelegramService;
use RB\Support\Crypto;
use RB\Support\Database;

T::suite('ربات تلگرام و Webhook (با شبیه‌ساز API)');

$db = Database::instance();

// ---------- ثبت توکن رمزنگاری‌شده
$token = '123456789:' . str_repeat('A', 35);
$enc = Crypto::encrypt($token);
T::ok(str_starts_with((string) $enc, 'v1:'), 'توکن با فرمت نسخه‌دار v1 رمزنگاری شد');
T::ok(!str_contains((string) $enc, $token), 'متن توکن در داده رمزنگاری‌شده دیده نمی‌شود');
T::eq($token, Crypto::decrypt($enc), 'رمزگشایی توکن درست است');

$settingsId = (int) $db->value('SELECT id FROM telegram_settings ORDER BY id LIMIT 1');
if ($settingsId === 0) {
    $settingsId = $db->insert('telegram_settings', [
        'connection_status' => 'disconnected',
        'webhook_active' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
$db->update('telegram_settings', [
    'bot_token_enc' => $enc,
    'bot_id' => 123456789,
    'bot_username' => 'refrallbot_test_bot',
    'bot_name' => 'ربات تست',
    'connection_status' => 'connected',
    'webhook_secret' => 'test-secret',
    'webhook_active' => 1,
    'webhook_url' => 'https://example.test/webhook/telegram',
    'updated_at' => now(),
], 'id = :id', ['id' => $settingsId]);
TelegramService::forgetSettingsCache();
T::ok(TelegramService::configured(), 'ربات متصل شناخته می‌شود');

// ---------- شبیه‌ساز API تلگرام
$sent = [];
$apiCalls = [];
TelegramService::$fakeTransport = static function (string $method, array $params) use (&$sent, &$apiCalls): array {
    $apiCalls[] = $method;
    if ($method === 'getMe') {
        return ['ok' => true, 'result' => ['id' => 123456789, 'is_bot' => true, 'first_name' => 'ربات تست', 'username' => 'refrallbot_test_bot']];
    }
    if ($method === 'getChatMember') {
        $status = $params['user_id'] ?? 0;
        // کاربران با شناسه زوج عضو کانال هستند
        return ['ok' => true, 'result' => ['status' => ((int) $status % 2 === 0) ? 'member' : 'left']];
    }
    if (in_array($method, ['sendMessage', 'sendPhoto', 'editMessageText', 'answerCallbackQuery'], true)) {
        $sent[] = ['method' => $method, 'params' => $params];
        return ['ok' => true, 'result' => ['message_id' => count($sent)]];
    }
    return ['ok' => true, 'result' => true];
};

$tg = new TelegramService();
$me = $tg->getMe();
T::ok(($me['ok'] ?? false) && $me['result']['username'] === 'refrallbot_test_bot', 'getMe از طریق ترانسپورت تست کار می‌کند');

// ---------- بررسی هدر راز Webhook
$secret = (string) $db->value('SELECT webhook_secret FROM telegram_settings WHERE id = :id', ['id' => $settingsId]);
T::eq('test-secret', $secret, 'راز Webhook ذخیره شده است');

// ---------- پردازش آپدیت /start توسط BotHandler
$handler = new BotHandler($db);
$before = (int) $db->value('SELECT COUNT(*) FROM users');
$handler->handle([
    'update_id' => 1,
    'message' => [
        'message_id' => 11,
        'from' => ['id' => 900000001, 'is_bot' => false, 'first_name' => 'علی', 'last_name' => 'رضایی', 'username' => 'ali_test'],
        'chat' => ['id' => 900000001, 'type' => 'private'],
        'date' => time(),
        'text' => '/start REFTEST',
    ],
]);
$after = (int) $db->value('SELECT COUNT(*) FROM users');
T::eq($before + 1, $after, 'پیام /start کاربر جدید را ثبت می‌کند');

$newUser = $db->first('SELECT * FROM users WHERE telegram_id = 900000001');
T::ok($newUser !== null, 'کاربر با شناسه عددی تلگرام ساخته شد');
T::ok(!empty($newUser['referral_code']), 'کد دعوت اختصاصی تولید شد');
T::ok(count($sent) > 0, 'پیام خوش‌آمد به کاربر ارسال شد (شبیه‌سازی)');

// ---------- اجرای دوباره /start نباید کاربر تکراری بسازد
$handler->handle([
    'update_id' => 2,
    'message' => [
        'message_id' => 12,
        'from' => ['id' => 900000001, 'is_bot' => false, 'first_name' => 'علی'],
        'chat' => ['id' => 900000001, 'type' => 'private'],
        'date' => time(),
        'text' => '/start',
    ],
]);
T::eq($after, (int) $db->value('SELECT COUNT(*) FROM users'), 'اجرای مجدد /start کاربر تکراری نمی‌سازد');

// ---------- شمارنده آپدیت‌ها
$updates = (int) $db->value('SELECT updates_count FROM telegram_settings WHERE id = :id', ['id' => $settingsId]);
T::ok($updates >= 2, 'شمارنده آپدیت‌های دریافتی افزایش یافت (' . $updates . ')');

// ---------- لاگ پیام‌ها
T::ok((int) $db->value('SELECT COUNT(*) FROM telegram_messages') > 0, 'پیام‌های ربات در جدول telegram_messages ثبت شدند');

// ---------- دکمه‌های منو
$handler->handle([
    'update_id' => 3,
    'message' => [
        'message_id' => 13,
        'from' => ['id' => 900000001, 'is_bot' => false, 'first_name' => 'علی'],
        'chat' => ['id' => 900000001, 'type' => 'private'],
        'date' => time(),
        'text' => 'کیف پول',
    ],
]);
T::ok(count($sent) > 0, 'دکمه کیبورد پاسخ داده شد');

// ---------- بررسی خطای API به‌عنوان «عضو نیست» تفسیر نشود
$membership = new RB\Services\MembershipService($db);
$db->insert('telegram_channels', [
    'title' => 'کانال تست',
    'chat_id' => '@test_channel',
    'invite_link' => 'https://t.me/test_channel',
    'is_active' => 1,
    'sort_order' => 1,
    'created_at' => now(),
]);
SettingsService::set('force_channel_membership', '1', 'telegram');

TelegramService::$fakeTransport = static function (string $method, array $params): array {
    if ($method === 'getChatMember') {
        return ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'];
    }
    return ['ok' => true, 'result' => true];
};
$gateError = $membership->gate($newUser);
T::ok(($gateError['passed'] ?? false) === true, 'خطای API تلگرام باعث «عدم عضویت» جعلی نمی‌شود');

TelegramService::$fakeTransport = static function (string $method, array $params): array {
    if ($method === 'getChatMember') {
        return ['ok' => true, 'result' => ['status' => 'left']];
    }
    return ['ok' => true, 'result' => true];
};
$gateLeft = $membership->gate($newUser);
T::eq(false, $gateLeft['passed'], 'کاربر واقعاً غیرعضو، رد می‌شود');
T::eq('channels', $gateLeft['reason'], 'دلیل رد، عضویت کانال است');

TelegramService::$fakeTransport = static function (string $method, array $params): array {
    if ($method === 'getChatMember') {
        return ['ok' => true, 'result' => ['status' => 'member']];
    }
    return ['ok' => true, 'result' => true];
};
$gateOk = $membership->gate($newUser);
T::eq(true, $gateOk['passed'], 'کاربر عضو کانال، عبور می‌کند');

// ---------- تنظیم Webhook و اعتبارسنجی HTTPS در کنترلر
$httpsCall = null;
TelegramService::$fakeTransport = static function (string $method, array $params) use (&$httpsCall): array {
    if ($method === 'setWebhook') {
        $httpsCall = $params['url'] ?? '';
        return ['ok' => true, 'result' => true, 'description' => 'Webhook was set'];
    }
    return ['ok' => true, 'result' => true];
};
$webhookResult = (new TelegramService())->setWebhook('https://example.test/webhook/telegram', ['secret_token' => 'abc']);
T::ok(($webhookResult['ok'] ?? false) === true, 'تنظیم Webhook با آدرس HTTPS موفق است');
T::eq('https://example.test/webhook/telegram', $httpsCall, 'آدرس Webhook به درستی ارسال شد');

// ---------- آپدیت بدون توکن نباید خطای مرگبار بدهد
TelegramService::$fakeTransport = null;
$db->update('telegram_settings', ['bot_token_enc' => null], 'id = :id', ['id' => $settingsId]);
TelegramService::forgetSettingsCache();
T::eq(false, TelegramService::configured(), 'پس از حذف توکن، ربات «متصل نیست» گزارش می‌شود');
$sendNoToken = (new TelegramService())->sendMessage(1, 'تست');
T::eq(false, $sendNoToken['ok'], 'ارسال بدون توکن خطای کنترل‌شده برمی‌گرداند');

exit(T::summary());

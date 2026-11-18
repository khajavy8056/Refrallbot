<?php
declare(strict_types=1);
/**
 * تست سرویس تلگرام: شکل یکسان خروجی، کد وضعیت، 429/retry_after،
 * پاک‌سازی توکن از لاگ، و بازگردانی وضعیت کانال‌ها.
 *
 * ⚠️ این تست از مسیر HTTP واقعی استفاده نمی‌کند و به API واقعی تلگرام
 * وصل نمی‌شود. برای تست HTTP از «ترنسپورت تزریقی» استفاده شده است.
 * تست واقعی با توکن آزمایشی و دامنهٔ HTTPS باید جداگانه توسط کارفرما
 * انجام شود (در گزارش صریحاً «انجام‌نشده» علامت خورده است).
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\MembershipService;
use RB\Services\TelegramService;
use RB\Support\Crypto;
use RB\Support\Database;

T::suite('سرویس تلگرام: خروجی یکسان، 429، SSL و وضعیت کانال');

$db = Database::instance();
$token = '333444555:' . str_repeat('K', 35);

$settingsId = (int) $db->value('SELECT id FROM telegram_settings ORDER BY id LIMIT 1');
if ($settingsId === 0) {
    $settingsId = $db->insert('telegram_settings', [
        'connection_status' => 'connected', 'webhook_active' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
$db->update('telegram_settings', [
    'bot_token_enc' => Crypto::encrypt($token),
    'bot_id' => 333444555,
    'bot_username' => 'service_test_bot',
    'connection_status' => 'connected',
    'updated_at' => now(),
], 'id = :id', ['id' => $settingsId]);
TelegramService::forgetSettingsCache();

$tg = new TelegramService(null, $db);

// ---------------------------------------------------------------- ۱) توکن هرگز نشت نکند
T::ok(!str_contains($tg->maskedToken(), str_repeat('K', 35)), 'توکن ماسک‌شده حاوی توکن کامل نیست');
T::ok(str_contains($tg->maskedToken(), '•'), 'نمایش توکن با کاراکتر ماسک است');
T::ok(substr_count($tg->maskedToken(), '•') >= 10, 'دست‌کم ۱۰ کاراکتر میانی توکن پوشانده شده است');
T::ok(!str_contains($tg->maskedToken(), substr($token, 6, 20)), 'بخش حساس توکن (بدنه) در نسخهٔ ماسک‌شده نیست');
$redacted = TelegramService::redact('request failed for https://api.telegram.org/bot' . $token . '/sendMessage');
T::ok(!str_contains($redacted, str_repeat('K', 35)), 'redact() توکن را از متن خطا حذف می‌کند');
T::ok(str_contains($redacted, 'bot<redacted>'), 'redact() جای توکن نشانهٔ redacted می‌گذارد');

// ---------------------------------------------------------------- ۲) خروجی موفق: شکل یکسان
TelegramService::$fakeTransport = static fn (string $m, array $p): array
    => ['ok' => true, 'result' => ['id' => 42, 'is_bot' => true, 'username' => 'service_test_bot', 'first_name' => 'سرویس']];
$me = $tg->getMe();
T::eq(true, $me['ok'] ?? null, 'getMe() موفق بود');
T::eq(42, $me['result']['id'] ?? null, 'نتیجهٔ getMe در result برگشت');
T::ok(array_key_exists('description', $me), 'خروجی موفق هم کلید description دارد (شکل یکسان)');
T::ok(array_key_exists('error_code', $me), 'خروجی موفق هم کلید error_code دارد');
T::ok(array_key_exists('http_status', $me), 'خروجی هم کلید http_status دارد');

// ---------------------------------------------------------------- ۳) خطای API
TelegramService::$fakeTransport = static fn (string $m, array $p): array
    => ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found', 'http_status' => 400];
$r = $tg->sendMessage(123, 'سلام');
T::eq(false, $r['ok'] ?? null, 'خطای API به‌درستی ok:false برمی‌گرداند');
T::eq(400, $r['error_code'] ?? null, 'کد خطای تلگرام حفظ شد');
T::ok(str_contains((string) ($r['description'] ?? ''), 'chat not found'), 'پیام خطای تلگرام حفظ شد');

// ---------------------------------------------------------------- ۴) 429
TelegramService::$fakeTransport = static fn (string $m, array $p): array
    => ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 7', 'parameters' => ['retry_after' => 7]];
$r = $tg->sendMessage(123, 'سلام');
T::eq(429, $r['error_code'] ?? null, 'کد 429 تشخیص داده شد');
T::eq(7, $r['retry_after'] ?? null, 'مقدار retry_after برگردانده شد');
T::eq(7, $r['parameters']['retry_after'] ?? null, 'retry_after در parameters هم هست (سازگاری)');

// 429 بدون retry_after → پیش‌فرض محافظه‌کارانه
TelegramService::$fakeTransport = static fn (string $m, array $p): array
    => ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests'];
$r = $tg->sendMessage(123, 'سلام');
T::ok(($r['retry_after'] ?? 0) >= 1, 'در 429 بدون retry_after یک مقدار پیش‌فرض محافظه‌کارانه گذاشته می‌شود');

// ---------------------------------------------------------------- ۵) خطای شبکه/SSL
TelegramService::$fakeTransport = static fn (string $m, array $p): array
    => ['ok' => false, 'description' => 'SSL certificate problem: unable to get local issuer certificate', 'error_code' => 0];
$r = $tg->call('getMe');
T::eq(false, $r['ok'] ?? null, 'خطای SSL به‌عنوان شکست گزارش می‌شود (نه موفقیت کاذب)');

// ---------------------------------------------------------------- ۶) توکن تنظیم نشده
TelegramService::$fakeTransport = null;
$empty = new TelegramService('', $db);
$r = $empty->getMe();
T::eq(false, $r['ok'] ?? null, 'بدون توکن، فراخوانی ناموفق است');
T::ok(str_contains((string) ($r['description'] ?? ''), 'توکن'), 'پیام خطا به نبود توکن اشاره دارد');

// ---------------------------------------------------------------- ۷) فایل ناموجود
TelegramService::$fakeTransport = null;
$r = $tg->sendFile('sendDocument', ['chat_id' => 1], 'document', '/tmp/definitely-not-here-' . bin2hex(random_bytes(4)) . '.pdf');
T::eq(false, $r['ok'] ?? null, 'ارسال فایل ناموجود رد می‌شود');

// ---------------------------------------------------------------- ۸) Webhook: آدرس HTTPS
TelegramService::$fakeTransport = static fn (string $m, array $p): array => $m === 'setWebhook'
    ? ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: bad webhook: HTTPS url must be provided']
    : ['ok' => true, 'result' => true];
$r = $tg->setWebhook('http://example.test/webhook');
T::eq(false, $r['ok'] ?? null, 'تلگرام آدرس HTTP را رد می‌کند و خطا شفاف برگردانده می‌شود');

// ---------------------------------------------------------------- ۹) deleteWebhook: موفق/ناموفق
TelegramService::$fakeTransport = static fn (string $m, array $p): array
    => ['ok' => false, 'error_code' => 500, 'description' => 'Internal Server Error'];
$r = $tg->deleteWebhook();
T::eq(false, $r['ok'] ?? null, 'حذف ناموفق Webhook خطا برمی‌گرداند (پنل نباید وضعیت را پاک کند)');

TelegramService::$fakeTransport = static fn (string $m, array $p): array => ['ok' => true, 'result' => true];
$r = $tg->deleteWebhook();
T::eq(true, $r['ok'] ?? null, 'حذف موفق Webhook تأیید می‌شود');

// ---------------------------------------------------------------- ۱۰) setMyCommands / setChatMenuButton
$calls = [];
TelegramService::$fakeTransport = static function (string $m, array $p) use (&$calls): array {
    $calls[] = $m;
    if ($m === 'setChatMenuButton') {
        return ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: BUTTON_URL_INVALID'];
    }
    return ['ok' => true, 'result' => true];
};
$r1 = $tg->setMyCommands([['command' => 'start', 'description' => 'شروع']]);
T::eq(true, $r1['ok'] ?? null, 'setMyCommands موفق بود');
$r2 = $tg->setChatMenuButton(['menu_button' => ['type' => 'web_app', 'text' => 'پنل', 'web_app' => ['url' => 'https://example.test/app']]]);
T::eq(false, $r2['ok'] ?? null, 'خطای setChatMenuButton جداگانه گزارش می‌شود (نه موفقیت کلی کاذب)');
T::ok(in_array('setMyCommands', $calls, true) && in_array('setChatMenuButton', $calls, true), 'هر دو متد فراخوانی شدند');

// ---------------------------------------------------------------- ۱۱) getWebhookInfo
TelegramService::$fakeTransport = static fn (string $m, array $p): array => ['ok' => true, 'result' => [
    'url' => 'https://example.test/webhook/telegram',
    'has_custom_certificate' => false,
    'pending_update_count' => 3,
    'last_error_message' => 'Wrong response from the webhook: 500',
]];
$info = $tg->getWebhookInfo();
T::eq('https://example.test/webhook/telegram', $info['result']['url'] ?? null, 'getWebhookInfo آدرس را برمی‌گرداند');
T::eq(3, $info['result']['pending_update_count'] ?? null, 'تعداد آپدیت‌های در انتظار خوانده می‌شود');
T::ok(!empty($info['result']['last_error_message']), 'آخرین خطای وبهوک از تلگرام خوانده می‌شود');

// ---------------------------------------------------------------- ۱۲) getUpdates
$seenParams = [];
TelegramService::$fakeTransport = static function (string $m, array $p) use (&$seenParams): array {
    $seenParams = $p;
    return ['ok' => true, 'result' => [
        ['update_id' => 900001, 'message' => ['text' => 'a']],
        ['update_id' => 900002, 'message' => ['text' => 'b']],
    ]];
};
$updates = $tg->getUpdates(900000, 30, 100);
T::eq(2, count($updates['result'] ?? []), 'getUpdates دو آپدیت برگرداند');
T::eq(900000, $seenParams['offset'] ?? null, 'offset درست ارسال شد');
T::ok(($seenParams['timeout'] ?? -1) <= 50, 'timeout در محدودهٔ مجاز تلگرام است');
T::ok(($seenParams['limit'] ?? 0) <= 100, 'limit در محدودهٔ مجاز تلگرام است');

// ---------------------------------------------------------------- ۱۳) وضعیت عضویت کانال
T::suite('عضویت کانال: هر وضعیت درست تفسیر شود');

$channel = ['id' => 1, 'title' => 'کانال تست', 'chat_id' => '@test_channel', 'invite_link' => null];

$withStatus = static function (array $result) use ($tg, $channel): array {
    TelegramService::$fakeTransport = static fn (string $m, array $p): array => $result;
    return $tg->checkMembership($channel, 5550001);
};

foreach (['creator' => true, 'administrator' => true, 'member' => true, 'left' => false, 'kicked' => false] as $status => $expected) {
    $r = $withStatus(['ok' => true, 'result' => ['status' => $status]]);
    T::eq($expected, $r['member'] ?? null, 'وضعیت ' . $status . ' → عضویت ' . ($expected ? 'دارد' : 'ندارد'));
    T::eq(true, $r['ok'] ?? null, 'وضعیت ' . $status . ' خطای API نیست');
}

$restrictedIn = $withStatus(['ok' => true, 'result' => ['status' => 'restricted', 'is_member' => true]]);
T::eq(true, $restrictedIn['member'] ?? null, 'restricted با is_member=true عضو است');
$restrictedOut = $withStatus(['ok' => true, 'result' => ['status' => 'restricted', 'is_member' => false]]);
T::eq(false, $restrictedOut['member'] ?? null, 'restricted با is_member=false عضو نیست');

// خطای دسترسی ≠ عدم عضویت
$permissionError = $withStatus(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found']);
T::eq(false, $permissionError['ok'] ?? null, 'خطای «chat not found» به‌عنوان خطای API گزارش می‌شود');
T::eq(null, $permissionError['member'] ?? null, 'خطای API هرگز member=false نمی‌دهد (کاربر مسدود نمی‌شود)');
T::ok(str_contains((string) ($permissionError['error'] ?? ''), 'کانال یافت نشد'), 'پیام خطای کانال شفاف است');

$rightsError = $withStatus(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: not enough rights to get chat member']);
T::eq(null, $rightsError['member'] ?? null, 'نبود حق دسترسی، عدم عضویت تلقی نمی‌شود');
T::ok(!empty($rightsError['permission_error']), 'خطای دسترسی علامت‌گذاری شد');
T::ok(str_contains((string) ($rightsError['error'] ?? ''), 'ادمین'), 'پیام راهنما دربارهٔ ادمین بودن ربات است');

$transient = $withStatus(['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway']);
T::eq(null, $transient['member'] ?? null, 'خطای موقت (5xx) عدم عضویت تلقی نمی‌شود');
T::ok(!empty($transient['transient']), 'خطای موقت علامت‌گذاری شد تا کاربر مسدود نشود');

$userNotFound = $withStatus(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: user not found']);
T::eq(false, $userNotFound['member'] ?? null, 'کاربر ناشناخته در کانال → عضو نیست');
T::eq(true, $userNotFound['ok'] ?? null, 'این خطای خاص به‌عنوان پاسخ معتبر «عضو نیست» تلقی می‌شود');

// ---------------------------------------------------------------- ۱۴) دسترسی ربات
TelegramService::$fakeTransport = static fn (string $m, array $p): array => ['ok' => true, 'result' => ['status' => 'administrator']];
$access = $tg->checkBotAccess($channel);
T::eq(true, $access['admin'] ?? null, 'ربات ادمین کانال است');

TelegramService::$fakeTransport = static fn (string $m, array $p): array => ['ok' => true, 'result' => ['status' => 'member']];
$access = $tg->checkBotAccess($channel);
T::eq(false, $access['admin'] ?? null, 'ربات فقط عضو است، نه ادمین');
T::ok(str_contains((string) ($access['message'] ?? ''), 'ادمین نیست'), 'پیام صریح است');

// ---------------------------------------------------------------- ۱۵) gate: خطای API کاربر را مسدود نمی‌کند
RB\Services\SettingsService::set('force_channel_membership', '1', 'telegram');
$db->insert('telegram_channels', [
    'title' => 'کانال اجباری', 'chat_id' => '@forced_channel', 'is_active' => 1,
    'sort_order' => 1, 'created_at' => now(),
]);
$user = makeUser(['id' => 777000111, 'is_bot' => false, 'first_name' => 'کاربر']);

TelegramService::$fakeTransport = static fn (string $m, array $p): array
    => ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot is not a member of the channel chat'];
$gate = (new MembershipService($db))->gate($user);
T::eq(true, $gate['passed'] ?? null, 'خطای API باعث مسدود شدن کاربر نمی‌شود (gate عبور می‌کند)');
T::ok(!empty($gate['errors']), 'خطا برای گزارش به مدیر برگردانده می‌شود');
T::ok((string) ($db->value("SELECT value FROM system_settings WHERE `key` = 'membership_last_error'") ?? '') !== '', 'آخرین خطای عضویت برای پنل مدیریت ذخیره شد');
T::eq(0, (int) $db->value('SELECT channels_verified FROM users WHERE id = :id', ['id' => (int) $user['id']]), 'با خطای API کاربر «تأییدشده» علامت نمی‌خورد');

TelegramService::$fakeTransport = static fn (string $m, array $p): array => ['ok' => true, 'result' => ['status' => 'left']];
$gate = (new MembershipService($db))->gate($user);
T::eq(false, $gate['passed'] ?? null, 'کاربری که واقعاً عضو نیست مسدود می‌شود');
T::eq('channels', $gate['reason'] ?? null, 'دلیل مسدودسازی channels است');

TelegramService::$fakeTransport = static fn (string $m, array $p): array => ['ok' => true, 'result' => ['status' => 'member']];
$gate = (new MembershipService($db))->gate($user);
T::eq(true, $gate['passed'] ?? null, 'عضو واقعی اجازهٔ عبور می‌گیرد');
T::eq(1, (int) $db->value('SELECT channels_verified FROM users WHERE id = :id', ['id' => (int) $user['id']]), 'پس از بررسی موفق، کاربر تأییدشده علامت خورد');

$db->update('telegram_channels', ['is_active' => 0], 'chat_id = :c', ['c' => '@forced_channel']);
$gate = (new MembershipService($db))->gate($user);
T::eq(true, $gate['passed'] ?? null, 'کانال غیرفعال در گیت نادیده گرفته می‌شود');

TelegramService::$fakeTransport = null;
exit(T::summary());

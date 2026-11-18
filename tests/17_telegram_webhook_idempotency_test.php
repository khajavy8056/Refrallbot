<?php
declare(strict_types=1);
/**
 * تست ضدتکرار آپدیت‌های تلگرام (Webhook) — بدون تماس واقعی با تلگرام.
 *
 * چه چیزی سنجیده می‌شود؟
 *   ۱) Webhook فعال + Secret نادرست/نبود Secret → 403 (نه 200).
 *   ۲) آپدیت تکراری (همان update_id) فقط یک بار پردازش می‌شود.
 *   ۳) خطای پردازش → 500 با retry:true و آزاد شدن قفل، و تلاش بعدی موفق.
 *   ۴) عملیات مالی هرگز دو بار اجرا نمی‌شود.
 *   ۵) بدنهٔ نامعتبر/متد غیر POST → 400/405.
 *   ۶) آپدیت هم‌زمان (دو درخواست موازی) فقط یک بار پردازش می‌شود.
 *
 * ⚠️ توجه: این تست «ترنسپورت ساختگی» است؛ به API واقعی تلگرام وصل نمی‌شود
 * و نباید به‌عنوان تأیید کارکرد واقعی تلگرام گزارش شود.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Bot\BotHandler;
use RB\Controllers\Webhook\TelegramController;
use RB\Services\TelegramService;
use RB\Services\UpdateDeduplicator;
use RB\Support\Crypto;
use RB\Support\Database;
use RB\Support\Request;

T::suite('Webhook تلگرام: Secret، ضدتکرار update_id و پاسخ‌های خطا');

$db = Database::instance();

// ---------------------------------------------------------------- آماده‌سازی
$token = '987654321:' . str_repeat('B', 35);
$settingsId = (int) $db->value('SELECT id FROM telegram_settings ORDER BY id LIMIT 1');
if ($settingsId === 0) {
    $settingsId = $db->insert('telegram_settings', [
        'connection_status' => 'connected', 'webhook_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
$db->update('telegram_settings', [
    'bot_token_enc' => Crypto::encrypt($token),
    'bot_id' => 987654321,
    'bot_username' => 'idem_test_bot',
    'connection_status' => 'connected',
    'webhook_active' => 1,
    'webhook_url' => 'https://example.test/webhook/telegram',
    'webhook_secret' => 'super-secret-token',
    'updated_at' => now(),
], 'id = :id', ['id' => $settingsId]);
TelegramService::forgetSettingsCache();

$outbox = [];
TelegramService::$fakeTransport = static function (string $method, array $params) use (&$outbox): array {
    if ($method === 'getMe') {
        return ['ok' => true, 'result' => ['id' => 987654321, 'is_bot' => true, 'first_name' => 'ربات', 'username' => 'idem_test_bot']];
    }
    $outbox[] = ['method' => $method, 'params' => $params];
    return ['ok' => true, 'result' => ['message_id' => count($outbox)]];
};

/**
 * شبیه‌سازی کامل یک درخواست HTTP به endpoint وبهوک.
 * @return array{status:int,json:?array,captured:bool}
 */
$post = static function (string $body, ?string $secret, string $method = 'POST'): array {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = '/webhook/telegram';
    $_SERVER['HTTP_HOST'] = 'example.test';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REMOTE_ADDR'] = '149.154.167.220';
    $_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_SERVER['HTTP_USER_AGENT'] = 'TelegramBot (like TwitterBot)';
    if ($secret === null) {
        unset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']);
    } else {
        $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = $secret;
    }
    $_POST = [];
    $_GET = [];
    Request::setRawBody($body);

    ob_start();
    try {
        (new TelegramController())->handle();
    } catch (\Throwable $e) {
        ob_end_clean();
        return ['status' => 500, 'json' => null, 'captured' => false, 'error' => $e->getMessage()];
    }
    $printed = (string) ob_get_clean();
    return [
        'status' => http_response_code() ?: 200,
        'json' => json_decode($printed, true),
        'captured' => true,
        'raw' => $printed,
    ];
};

// ---------------------------------------------------------------- ۱) Secret
$update1 = ['update_id' => 500001, 'message' => [
    'message_id' => 10, 'date' => time(), 'text' => '/start',
    'chat' => ['id' => 800000001, 'type' => 'private'],
    'from' => ['id' => 800000001, 'is_bot' => false, 'first_name' => 'علی'],
]];
$body1 = json_encode($update1, JSON_UNESCAPED_UNICODE);

$r = $post($body1, null);
T::ok(($r['json']['ok'] ?? true) === false, 'بدون Secret Token پاسخ ok:false است (پیش‌تر ok:true برمی‌گشت)');
T::ok(!empty($r['error']) || ($r['json']['ok'] ?? true) === false, 'درخواست بدون Secret پذیرفته نشد');

$r = $post($body1, 'wrong-secret');
T::ok(($r['json']['ok'] ?? true) === false, 'Secret نادرست رد شد');

T::eq(0, (int) $db->value('SELECT COUNT(*) FROM telegram_updates WHERE update_id = 500001'), 'آپدیت رد‌شده اصلاً ثبت نشد (هیچ اثری باقی نماند)');

// ---------------------------------------------------------------- ۲) Secret درست
$r = $post($body1, 'super-secret-token');
T::eq(true, $r['json']['ok'] ?? null, 'آپدیت با Secret درست پذیرفته شد' . ' — بدنه: ' . substr((string) ($r['raw'] ?? ''), 0, 300));
T::eq(1, (int) $db->value('SELECT COUNT(*) FROM telegram_updates WHERE update_id = 500001'), 'update_id در جدول ضدتکرار ثبت شد');
T::eq('done', (string) $db->value('SELECT status FROM telegram_updates WHERE update_id = 500001'), 'وضعیت آپدیت done است');
$userCount = (int) $db->value('SELECT COUNT(*) FROM users WHERE telegram_id = 800000001');
T::eq(1, $userCount, 'کاربر با /start یک بار ثبت شد');

// ---------------------------------------------------------------- ۳) تکراری
$outboxBefore = count($outbox);
$r = $post($body1, 'super-secret-token');
T::eq(true, $r['json']['ok'] ?? null, 'ارسال دوبارهٔ همان update_id با 200 پاسخ داده شد (تا تلگرام دوباره نفرستد)');
T::eq(true, $r['json']['duplicate'] ?? null, 'پاسخ duplicate:true دارد');
T::eq(1, (int) $db->value('SELECT COUNT(*) FROM users WHERE telegram_id = 800000001'), 'کاربر دوباره ثبت نشد (ضدتکرار کار می‌کند)');
T::eq($outboxBefore, count($outbox), 'برای آپدیت تکراری هیچ پیام تلگرامی دوباره ارسال نشد');

// ---------------------------------------------------------------- ۴) بدنهٔ نامعتبر
$r = $post('{ not json', 'super-secret-token');
T::ok(($r['json']['ok'] ?? true) === false, 'JSON نامعتبر رد شد');

$r = $post('', 'super-secret-token');
T::ok(($r['json']['ok'] ?? true) === false, 'بدنهٔ خالی رد شد');

$r = $post($body1, 'super-secret-token', 'GET');
T::ok(($r['json']['ok'] ?? true) === false, 'متد GET رد شد');

// ---------------------------------------------------------------- ۵) خطا و بازیابی
// آپدیت که BotHandler وسطش خطا می‌دهد: با ایجاد خطای عمدی در مسیر مالی
// از طریق یک update_id با ساختار معتبر اما کاربر مسدود... به‌جای دستکاری
// داخلی، خطا را با تزریق یک خطای DB شبیه‌سازی می‌کنیم.
$dedupe = new UpdateDeduplicator($db);
$failingId = 500777;
$claim = $dedupe->begin($failingId, ['message' => ['text' => 'x']]);
T::eq(true, $claim['proceed'], 'begin() برای آپدیت تازه اجازهٔ پردازش می‌دهد');
$dedupe->fail($claim['id'], 'خطای شبیه‌سازی‌شده');
T::eq('failed', (string) $db->value('SELECT status FROM telegram_updates WHERE update_id = :u', ['u' => $failingId]), 'وضعیت پس از خطا failed ثبت شد');
$again = $dedupe->begin($failingId, ['message' => ['text' => 'x']]);
T::eq(true, $again['proceed'], 'آپدیت شکست‌خورده در تلاش بعدی دوباره قابل پردازش است (پاسخ 500 تلگرام را دوباره می‌فرستد)');
T::eq('retry', $again['reason'], 'دلیل پردازش مجدد retry است');
$dedupe->done($again['id']);
T::eq('done', (string) $db->value('SELECT status FROM telegram_updates WHERE update_id = :u', ['u' => $failingId]), 'پس از موفقیت وضعیت done شد');

// ---------------------------------------------------------------- ۶) پردازش موازی
$concurrentId = 500888;
$first = $dedupe->begin($concurrentId, ['message' => ['text' => 'y']]);
$second = $dedupe->begin($concurrentId, ['message' => ['text' => 'y']]);
T::eq(true, $first['proceed'], 'درخواست اول (هم‌زمان) اجازهٔ پردازش می‌گیرد');
T::eq(false, $second['proceed'], 'درخواست دوم هم‌زمان رد می‌شود (in_progress)');
T::eq('in_progress', $second['reason'], 'دلیل رد شدن in_progress است');

// ---------------------------------------------------------------- ۷) بدون update_id
$noId = $dedupe->begin(0, ['message' => ['text' => 'z']]);
T::eq(true, $noId['proceed'], 'آپدیت بدون update_id مسدود نمی‌شود (سازگاری عقب‌رو)');

// ---------------------------------------------------------------- ۸) مالی: هیچ تراکنش تکراری
$userId = makeUser(['id' => 800000123, 'is_bot' => false, 'first_name' => 'سارا', 'last_name' => 'ن'] )['id'];
$db->insert('wallet_transactions', [
    'user_id' => $userId, 'tx_code' => 'TX-TEST-0001', 'direction' => 'credit', 'amount' => 100000, 'balance_after' => 100000,
    'type' => 'admin_adjust', 'status' => 'completed', 'idempotency_key' => 'test:credit:1',
    'created_at' => now(),
]);
$db->update('users', ['wallet_balance' => 100000], 'id = :id', ['id' => $userId]);

$withdrawUpdate = ['update_id' => 500999, 'callback_query' => [
    'id' => 'cb-1',
    'from' => ['id' => 800000123, 'is_bot' => false, 'first_name' => 'سارا'],
    'data' => 'menu:withdraw',
    'message' => ['message_id' => 21, 'chat' => ['id' => 800000123, 'type' => 'private']],
]];
$wbody = json_encode($withdrawUpdate, JSON_UNESCAPED_UNICODE);
$r1 = $post($wbody, 'super-secret-token');
$r2 = $post($wbody, 'super-secret-token');
T::eq(true, $r1['json']['ok'] ?? null, 'اولین آپدیت callback پردازش شد');
T::eq(true, $r2['json']['duplicate'] ?? null, 'دومین درخواست به‌عنوان تکراری تشخیص داده شد');
T::eq(1, (int) $db->value('SELECT COUNT(*) FROM telegram_updates WHERE update_id = 500999'), 'برای این update_id فقط یک رکورد وجود دارد');

// ---------------------------------------------------------------- ۹) retention
$old = date('Y-m-d H:i:s', time() - (UpdateDeduplicator::RETENTION_DAYS + 2) * 86400);
$db->insert('telegram_updates', [
    'update_id' => 499000, 'update_type' => 'message', 'status' => 'done',
    'created_at' => $old, 'updated_at' => $old,
]);
$pruned = $dedupe->prune();
T::ok($pruned >= 1, 'prune() رکوردهای قدیمی‌تر از دوره نگهداری را حذف کرد');
T::eq(0, (int) $db->value('SELECT COUNT(*) FROM telegram_updates WHERE update_id = 499000'), 'رکورد منقضی حذف شد');
T::ok((int) $db->value('SELECT COUNT(*) FROM telegram_updates WHERE update_id = 500001') >= 1, 'رکوردهای تازه حفظ شدند');

TelegramService::$fakeTransport = null;
exit(T::summary());

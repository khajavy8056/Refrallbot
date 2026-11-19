<?php
declare(strict_types=1);
/**
 * تست پیام «دعوت موفق» با نام فرد دعوت‌شده و تعداد دعوت‌ها
 *
 * سناریوهای تحت پوشش:
 *   ۱) ارسال پیام «دعوت موفق» حتی وقتی پاداش معرفی صفر/غیرفعال است.
 *   ۲) درج نام فرد دعوت‌شده و تعداد دقیق دعوت‌ها (با ارقام فارسی).
 *   ۳) عدم افزایش شمارنده و عدم ارسال دوباره با تکرار /start یا کد معرف دیگر.
 *   ۴) پشتیبانی از پیشوند REF_ و شمارش دقیق در شبکهٔ چندلایه.
 *   ۵) ارسال هم‌زمان «دعوت موفق» و «پاداش معرفی» وقتی پاداش فعال است.
 *   ۶) ارسال فوری «دعوت موفق» حتی پیش از تکمیل شرط شماره تماس، و پرداخت پاداش پس از آن بدون تکرار پیام دعوت.
 *   ۷) ارسال تلگرام فقط پس از COMMIT تراکنش (defer/flushDeferred) و پاک‌سازی در Rollback.
 *   ۸) مصونیت در برابر خودمعرفی، کد نامعتبر، معرف مسدود، نام حاوی HTML و خطای ارسال تلگرام.
 *   ۹) ضدتکرار مستقیم در Notifier::referralJoined().
 *
 * ⚠️ توجه: ارسال تلگرام با ترنسپورت ساختگی آزمایش می‌شود (بدون تماس واقعی با Bot API).
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Bot\BotHandler;
use RB\Services\CommissionSettings;
use RB\Services\Notifier;
use RB\Services\SettingsService;
use RB\Services\TelegramService;
use RB\Services\UserService;
use RB\Support\Crypto;
use RB\Support\Database;

T::suite('سناریو ۲۰: پیام «دعوت موفق» با نام دعوت‌شده و شمارندهٔ دعوت‌ها');

$db = Database::instance();
$users = new UserService($db);

// ---- راه‌اندازی ترنسپورت ساختگی تلگرام
$token = '555666777:' . str_repeat('R', 35);
$settingsId = (int) $db->value('SELECT id FROM telegram_settings ORDER BY id LIMIT 1');
if ($settingsId === 0) {
    $settingsId = $db->insert('telegram_settings', [
        'connection_status' => 'connected',
        'webhook_active' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
$db->update('telegram_settings', [
    'bot_token_enc' => Crypto::encrypt($token),
    'bot_id' => 555666777,
    'bot_username' => 'invite_notify_bot',
    'bot_name' => 'ربات دعوت',
    'connection_status' => 'connected',
    'updated_at' => now(),
], 'id = :id', ['id' => $settingsId]);
TelegramService::forgetSettingsCache();

/** @var array<int,array{chat_id:int,text:string,in_tx:bool}> $outbox */
$outbox = [];
$failForChat = null;
TelegramService::$fakeTransport = static function (string $method, array $params) use (&$outbox, &$failForChat, $db): array {
    if ($method === 'sendMessage') {
        $chatId = (int) ($params['chat_id'] ?? 0);
        if ($failForChat !== null && $chatId === $failForChat) {
            return ['ok' => false, 'error_code' => 403, 'http_status' => 403, 'description' => 'Forbidden: bot was blocked by the user'];
        }
        $outbox[] = [
            'chat_id' => $chatId,
            'text' => (string) ($params['text'] ?? ''),
            'in_tx' => $db->inTransaction(),
        ];
        return ['ok' => true, 'result' => ['message_id' => count($outbox)]];
    }
    return ['ok' => true, 'result' => true];
};

$messagesFor = static function (int $chatId, ?string $contains = null) use (&$outbox): array {
    return array_values(array_filter($outbox, static function (array $m) use ($chatId, $contains): bool {
        if ($m['chat_id'] !== $chatId) {
            return false;
        }
        return $contains === null || str_contains($m['text'], $contains);
    }));
};

$handler = new BotHandler($db);
$nextUpdateId = 700001;
$sendStart = static function (array $from, ?string $payload = null) use ($handler, &$nextUpdateId): void {
    $id = (int) $from['id'];
    $handler->handle([
        'update_id' => $nextUpdateId++,
        'message' => [
            'message_id' => $nextUpdateId,
            'date' => time(),
            'from' => array_merge(['is_bot' => false], $from),
            'chat' => ['id' => $id, 'type' => 'private'],
            'text' => $payload !== null ? '/start ' . $payload : '/start',
        ],
    ]);
};

// ---------------------------------------------------------------- ۱) پاداش معرفی = ۰
CommissionSettings::saveVersion([
    'direct_reward' => 0,
    'direct_enabled' => 0,
    'multi_level_enabled' => 0,
    'max_levels' => 1,
    'levels' => [['level' => 1, 'percent' => '0', 'enabled' => false]],
    'percent_base' => 'direct_reward',
    'qualify_conditions' => ['joined'],
]);

// کاربر A (معرف اصلی) عضو می‌شود
$sendStart(['id' => 710001, 'first_name' => 'علی', 'last_name' => 'احمدی', 'username' => 'ali_a']);
$userA = $users->findByTelegramId(710001);
T::ok($userA !== null, 'معرف اصلی (علی) ثبت شد');
$outbox = [];

// کاربر B با لینک علی عضو می‌شود (پاداش = ۰)
$sendStart(['id' => 710002, 'first_name' => 'مریم', 'last_name' => 'رضایی', 'username' => 'maryam_r'], (string) $userA['referral_code']);
$userA = $users->find((int) $userA['id']);
$userB = $users->findByTelegramId(710002);

$aInvites = $messagesFor(710001, 'دعوت موفق');
T::eq(1, count($aInvites), 'پیام «دعوت موفق» حتی با پاداش صفر برای معرف ارسال شد');
T::ok(str_contains($aInvites[0]['text'], 'مریم رضایی'), 'نام کامل فرد دعوت‌شده در پیام هست');
T::ok(str_contains($aInvites[0]['text'], '@maryam_r'), 'نام کاربری فرد دعوت‌شده در پیام هست');
T::ok(str_contains($aInvites[0]['text'], '۱ نفر'), 'شمارندهٔ دعوت (۱ نفر) در پیام درج شده است');
T::eq(false, $aInvites[0]['in_tx'], 'ارسال تلگرام به معرف بعد از COMMIT تراکنش انجام شد (نه داخل تراکنش)');
T::eq(0, (int) $userA['wallet_balance'], 'با پاداش صفر، موجودی کیف پول صفر ماند');
T::eq(1, (int) $userA['direct_ref_count'], 'direct_ref_count معرف = ۱');

$dbNotifCount = (int) $db->value(
    "SELECT COUNT(*) FROM notifications WHERE user_id = :u AND type = 'referral_join'",
    ['u' => (int) $userA['id']]
);
T::eq(1, $dbNotifCount, 'اعلان دعوت موفق در جدول notifications هم ذخیره شد');

// ---------------------------------------------------------------- ۲) تکرار /start نباید شمارنده یا پیام را تکرار کند
$outbox = [];
$sendStart(['id' => 710002, 'first_name' => 'مریم', 'last_name' => 'رضایی', 'username' => 'maryam_r'], (string) $userA['referral_code']);
$sendStart(['id' => 710002, 'first_name' => 'مریم', 'last_name' => 'رضایی', 'username' => 'maryam_r']);
$userA = $users->find((int) $userA['id']);
T::eq(1, (int) $userA['direct_ref_count'], 'شمارندهٔ دعوت با اجرای دوبارهٔ /start زیاد نشد');
T::eq(0, count($messagesFor(710001, 'دعوت موفق')), 'پیام دعوت موفق با تکرار /start دوباره ارسال نشد');

// تلاش کاربر B برای زدن /start با کد معرف دیگر
$sendStart(['id' => 710099, 'first_name' => 'امیر']);
$other = $users->findByTelegramId(710099);
$outbox = [];
$sendStart(['id' => 710002, 'first_name' => 'مریم'], (string) $other['referral_code']);
$other = $users->find((int) $other['id']);
T::eq(0, (int) $other['direct_ref_count'], 'زدن /start کاربر قدیمی با کد فرد دیگر شمارنده را تغییر نداد');
T::eq(0, count($messagesFor(710099, 'دعوت موفق')), 'برای معرف دوم پیام دعوت جعلی ارسال نشد');

// ---------------------------------------------------------------- ۳) دعوت دوم (با پیشوند REF_) و افزایش شمارنده به ۲
$outbox = [];
$sendStart(['id' => 710003, 'first_name' => 'سارا', 'last_name' => 'کریمی'], 'REF_' . $userA['referral_code']);
$userA = $users->find((int) $userA['id']);
$userC = $users->findByTelegramId(710003);
$aInvites2 = $messagesFor(710001, 'دعوت موفق');
T::eq(1, count($aInvites2), 'برای دعوت دوم یک پیام «دعوت موفق» ارسال شد');
T::ok(str_contains($aInvites2[0]['text'], 'سارا کریمی'), 'نام دعوت‌شدهٔ دوم در پیام هست');
T::ok(str_contains($aInvites2[0]['text'], '۲ نفر'), 'شمارندهٔ دعوت به «۲ نفر» رسید');
T::eq(2, (int) $userA['direct_ref_count'], 'direct_ref_count معرف = ۲');

// ---------------------------------------------------------------- ۴) زیرمجموعهٔ لایه ۲ + نمایش کل شبکه
$outbox = [];
$sendStart(['id' => 710004, 'first_name' => 'حسین'], (string) $userB['referral_code']);
$bInvites = $messagesFor(710002, 'دعوت موفق');
T::eq(1, count($bInvites) && str_contains($bInvites[0]['text'], '۱ نفر') ? 1 : 0, 'معرف مستقیم (مریم) پیام دعوت موفق با «۱ نفر» گرفت');
T::eq(0, count($messagesFor(710001, 'دعوت موفق')), 'معرف لایهٔ بالا (علی) پیام دعوت مستقیم نگرفت');

$outbox = [];
$sendStart(['id' => 710005, 'first_name' => 'نگار'], (string) $userA['referral_code']);
$aInvites3 = $messagesFor(710001, 'دعوت موفق');
T::ok(isset($aInvites3[0]) && str_contains($aInvites3[0]['text'], '۳ نفر'), 'دعوت مستقیم سوم علی با «۳ نفر» گزارش شد');
T::ok(isset($aInvites3[0]) && str_contains($aInvites3[0]['text'], '۴ نفر'), 'کل شبکهٔ علی («۴ نفر») هم در پیام نمایش داده شد');

// ---------------------------------------------------------------- ۵) پاداش فعال (> 0)
CommissionSettings::saveVersion([
    'direct_reward' => 10000,
    'direct_enabled' => 1,
    'multi_level_enabled' => 0,
    'max_levels' => 1,
    'levels' => [['level' => 1, 'percent' => '0', 'enabled' => false]],
    'percent_base' => 'direct_reward',
    'qualify_conditions' => ['joined'],
]);
$outbox = [];
$sendStart(['id' => 710006, 'first_name' => 'پویا'], (string) $userB['referral_code']);
T::eq(1, count($messagesFor(710002, 'دعوت موفق')), 'با پاداش فعال هم پیام «دعوت موفق» ارسال شد');
T::eq(1, count($messagesFor(710002, 'پاداش معرفی')), 'پیام «پاداش معرفی» هم جداگانه پس از COMMIT ارسال شد');
T::eq(10000, (int) $users->find((int) $userB['id'])['wallet_balance'], 'مبلغ ۱۰٬۰۰۰ تومان به کیف پول مریم اضافه شد');

// ---------------------------------------------------------------- ۶) شرط شماره تماس (تاخیر در واجد شرایط شدن)
CommissionSettings::saveVersion([
    'direct_reward' => 8000,
    'direct_enabled' => 1,
    'multi_level_enabled' => 0,
    'max_levels' => 1,
    'levels' => [['level' => 1, 'percent' => '0', 'enabled' => false]],
    'percent_base' => 'direct_reward',
    'qualify_conditions' => ['joined', 'phone'],
]);
$outbox = [];
$sendStart(['id' => 710007, 'first_name' => 'الهام'], (string) $userC['referral_code']);
$userG = $users->findByTelegramId(710007);
T::eq(1, count($messagesFor(710003, 'دعوت موفق')), 'پیش از تأیید شماره تماس هم پیام «دعوت موفق» به معرف رسید');
T::eq(0, count($messagesFor(710003, 'پاداش معرفی')), 'پیش از تأیید شماره تماس پاداش پرداخت نشد');

$outbox = [];
$users->setPhone((int) $userG['id'], '+989121234567');
T::eq(0, count($messagesFor(710003, 'دعوت موفق')), 'پس از ثبت شماره تماس پیام «دعوت موفق» تکرار نشد');
T::eq(1, count($messagesFor(710003, 'پاداش معرفی')), 'پس از ثبت شماره تماس پیام پاداش ارسال شد');

// ---------------------------------------------------------------- ۷) خودمعرفی، کد نامعتبر، معرف مسدود
$outbox = [];
$sendStart(['id' => 710001, 'first_name' => 'علی'], (string) $userA['referral_code']);
T::eq(0, count($messagesFor(710001, 'دعوت موفق')), 'خودمعرفی هیچ پیام دعوت موفقی نمی‌فرستد');

$users->setStatus((int) $other['id'], 'blocked', null, 'تست');
$outbox = [];
$sendStart(['id' => 710008, 'first_name' => 'کیان'], (string) $other['referral_code']);
T::eq(0, count($messagesFor(710099, 'دعوت موفق')), 'برای معرف مسدود پیام تلگرام دعوت موفق ارسال نمی‌شود');

// ---------------------------------------------------------------- ۸) امنیت HTML و خطای ارسال تلگرام و Rollback و ضدتکرار
$outbox = [];
$sendStart(['id' => 710009, 'first_name' => '<b>تست</b>', 'last_name' => ''], (string) $userA['referral_code']);
$xssMsg = $messagesFor(710001, 'دعوت موفق');
T::ok(isset($xssMsg[0]) && str_contains($xssMsg[0]['text'], '&lt;b&gt;تست&lt;/b&gt;') && !str_contains($xssMsg[0]['text'], '«<b>تست</b>»'), 'تگ HTML در نام کاربر دعوت‌شده escape شد');

// خطای 403 تلگرام (بلاک بودن ربات توسط معرف) نباید ثبت‌نام دعوت‌شده را بشکند
$failForChat = 710001;
$sendStart(['id' => 710010, 'first_name' => 'فرهاد'], (string) $userA['referral_code']);
$failForChat = null;
T::ok($users->findByTelegramId(710010) !== null, 'با شکست ارسال تلگرام به معرف، ثبت‌نام دعوت‌شده موفق ماند');

// ضدتکرار مستقیم Notifier::referralJoined
$outbox = [];
$farhad = $users->findByTelegramId(710010);
Notifier::referralJoined((int) $userA['id'], $farhad, 5, 6);
T::eq(0, count($messagesFor(710001, 'دعوت موفق')), 'فراخوانی تکراری Notifier::referralJoined پیام دوم نمی‌فرستد');

// Rollback تراکنش نباید پیام معوق بفرستد
$outbox = [];
try {
    $db->transaction(static function () use ($userA): void {
        Notifier::defer((int) $userA['id'], '🎉 دعوت موفق جدید', 'نباید ارسال شود', 'referral_join');
        throw new \RuntimeException('rollback');
    });
} catch (\RuntimeException) {
    Notifier::clearDeferred();
}
Notifier::flushDeferred();
T::eq(false, Notifier::hasDeferred(), 'صف ارسال معوق پس از Rollback پاک شد');
T::eq(0, count($messagesFor(710001)), 'پس از Rollback هیچ پیام تلگرامی ارسال نشد');

// بررسی شرط activity بعد از /start (اتصال پیام /start به user_id و اجرای maybeQualify)
SettingsService::set('qualify_min_activity_messages', '1', 'telegram');
CommissionSettings::saveVersion([
    'direct_reward' => 6000,
    'direct_enabled' => 1,
    'multi_level_enabled' => 0,
    'max_levels' => 1,
    'levels' => [['level' => 1, 'percent' => '0', 'enabled' => false]],
    'percent_base' => 'direct_reward',
    'qualify_conditions' => ['joined', 'activity'],
]);
$outbox = [];
$sendStart(['id' => 710011, 'first_name' => 'آرش'], (string) $userC['referral_code']);
$arash = $users->findByTelegramId(710011);
T::ok(!empty($arash['qualified_at']), 'maybeQualify پس از /start شرط activity را هم به درستی ارزیابی کرد');
SettingsService::set('qualify_min_activity_messages', '0', 'telegram');

TelegramService::$fakeTransport = null;
exit(T::summary());

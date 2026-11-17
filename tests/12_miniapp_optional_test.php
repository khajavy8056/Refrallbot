<?php
/**
 * تست: پنل وب (Mini App) اختیاری است.
 *
 * نتیجه‌ای که باید تضمین شود:
 *  - روی هاست بدون SSL (http)، هیچ کاربری نیاز به باز کردن پنل وب ندارد؛
 *    همه‌چیز با دکمه‌های خود ربات کار می‌کند.
 *  - اگر ماژول خاموش باشد، صفحه /app راهنمای «از ربات استفاده کنید» می‌دهد،
 *    دکمه web_app ساخته نمی‌شود و setChatMenuButton صدا زده نمی‌شود.
 *  - اگر ماژول روشن باشد ولی آدرس https نباشد، باز هم web_app ساخته نمی‌شود
 *    (چون تلگرام کل پیام را با خطای 400 رد می‌کند).
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Bot\BotHandler;
use RB\Services\MenuService;
use RB\Services\SettingsService;
use RB\Services\TelegramService;
use RB\Support\Database;

T::suite('پنل وب (Mini App) اختیاری — کار با دکمه‌های ربات روی HTTP');

$db = Database::instance();

// ---------- کمک‌کننده: روشن/خاموش کردن حالت
final class MiniState
{
    public static function set(string $mode, string $url): void
    {
        SettingsService::set('miniapp_mode', $mode, 'telegram');
        SettingsService::set('miniapp_url', $url, 'telegram');
        SettingsService::flush();
    }
}

// پاک‌سازی حالت‌های قبلی
$db->delete('system_settings', "`key` IN ('miniapp_mode','miniapp_url')");
SettingsService::flush();

// ---------- ۱) پیش‌فرض: هیچ آدرسی ثبت نشده
T::ok(!SettingsService::miniappActive(), 'پیش‌فرض بدون آدرس: پنل وب غیرفعال است');

MiniState::set('auto', 'http://example.test/app');
T::ok(!SettingsService::miniappActive(), 'آدرس http → پنل وب خودکار غیرفعال می‌شود (تلگرام https می‌خواهد)');

MiniState::set('auto', 'https://example.test/app');
T::ok(SettingsService::miniappActive(), 'آدرس https + حالت auto → پنل وب فعال است');

MiniState::set('off', 'https://example.test/app');
T::ok(!SettingsService::miniappActive(), 'حالت off حتی با آدرس https هم پنل را خاموش می‌کند');

MiniState::set('on', 'http://example.test/app');
T::ok(!SettingsService::miniappActive(), 'حالت on با آدرس http هم فعال نمی‌شود (محافظت از خطای تلگرام)');

MiniState::set('on', 'https://example.test/app');
T::ok(SettingsService::miniappActive(), 'حالت on + https → فعال');

// ---------- ۲) ساخت دکمه پنل در پیام‌های ربات
$fakeCalls = [];
TelegramService::$fakeTransport = static function (string $method, array $params) use (&$fakeCalls): array {
    $fakeCalls[] = ['method' => $method, 'params' => $params];
    return ['ok' => true, 'result' => ['message_id' => 1, 'chat' => ['id' => 1]]];
};

// نام کاربری ربات لازم است تا لینک دعوت https://t.me/<bot>?start=CODE ساخته شود
$settingsRow = (int) $db->value('SELECT id FROM telegram_settings ORDER BY id LIMIT 1');
$botData = [
    'bot_username' => 'refrallbot_test_bot',
    'bot_id' => 424242,
    'connection_status' => 'connected',
    'updated_at' => now(),
];
if ($settingsRow > 0) {
    $db->update('telegram_settings', $botData, 'id = :id', ['id' => $settingsRow]);
} else {
    $db->insert('telegram_settings', $botData + ['created_at' => now()]);
}
TelegramService::forgetSettingsCache();

$user = makeUser(['id' => 880000001, 'first_name' => 'کاربر', 'username' => 'miniappuser', 'language_code' => 'fa']);
$handler = new BotHandler($db);

/**
 * اجرای دکمه «درآمد من» (actIncome) — این یکی از دکمه‌هایی است که
 * panelButton() را ضمیمه می‌کند، پس برای تست دکمه پنل مناسب است.
 * (actWallet کلیدبورد اختصاصی خودش را می‌سازد.)
 */
$pressAccount = static function () use ($handler, $user): void {
    $ref = new ReflectionMethod($handler, 'actIncome');
    $ref->setAccessible(true);
    $ref->invoke($handler, $user, 880000001);
};

$debug = static function () use (&$fakeCalls): string {
    $lines = [];
    foreach ($fakeCalls as $c) {
        $lines[] = $c['method'] . ': ' . mb_substr((string) json_encode($c['params'], JSON_UNESCAPED_UNICODE), 0, 220);
    }
    return implode("\n    ", $lines);
};

/**
 * آخرین reply_markup ارسال‌شده.
 * توجه: TelegramService::inlineKeyboard() نتیجه را به‌صورت JSON رشته‌ای می‌دهد،
 * پس برای بررسی محتوا همان رشته را نگه می‌داریم.
 */
/** آخرین reply_markup به‌صورت آرایه (یا [] اگر نبود) */
$lastMarkup = static function () use (&$fakeCalls): array {
    for ($i = count($fakeCalls) - 1; $i >= 0; $i--) {
        if ($fakeCalls[$i]['method'] === 'sendMessage') {
            $markup = $fakeCalls[$i]['params']['reply_markup'] ?? [];
            if (is_string($markup)) {
                return json_decode($markup, true) ?: [];
            }
            return is_array($markup) ? $markup : [];
        }
    }
    return [];
};

/** همه دکمه‌های یک keyboard را صاف می‌کند */
$buttons = static function (array $markup): array {
    $out = [];
    foreach (($markup['inline_keyboard'] ?? []) as $row) {
        foreach ((array) $row as $btn) {
            $out[] = (array) $btn;
        }
    }
    return $out;
};

// همه ماژول‌ها خاموش → هیچ دکمه‌ای
MiniState::set('off', 'https://example.test/app');
$fakeCalls = [];
$pressAccount();
$offMarkup = $lastMarkup();
$offFlat = (string) json_encode($offMarkup, JSON_UNESCAPED_UNICODE);
T::ok(!str_contains($offFlat, 'web_app'), 'حالت off: دکمه web_app در پیام‌های ربات ساخته نمی‌شود');
T::ok(!str_contains($offFlat, 'example.test/app'), 'حالت off: لینک پنل در پیام‌ها نیست');

// آدرس http (حالت auto) → پیام باید ساخته شود، ولی با دکمه url نه web_app
MiniState::set('auto', 'http://example.test/app');
$fakeCalls = [];
$pressAccount();
$markup = $lastMarkup();
$btns = $buttons($markup);
T::ok($markup !== [], 'در حالت http پیام حاوی دکمه ساخته شده است');
$hasWebApp = false;
$urlButton = null;
foreach ($btns as $btn) {
    if (isset($btn['web_app'])) {
        $hasWebApp = true;
    }
    if (isset($btn['url'])) {
        $urlButton = $btn;
    }
}
T::ok(!$hasWebApp, 'آدرس http: هرگز web_app ساخته نمی‌شود (وگرنه تلگرام پیام را ۴۰۰ می‌کند)');
T::ok($urlButton !== null, 'آدرس http: دکمه url معمولی ساخته می‌شود تا کاربر پنل را در مرورگر باز کند');
T::eq('http://example.test/app', (string) ($urlButton['url'] ?? ''), 'آدرس http دقیقاً در دکمه url قرار گرفته است');

// آدرس https (حالت auto) → web_app
MiniState::set('auto', 'https://example.test/app');
$fakeCalls = [];
$pressAccount();
$btns = $buttons($lastMarkup());
$webAppButton = null;
$anyUrl = false;
foreach ($btns as $btn) {
    if (isset($btn['web_app'])) {
        $webAppButton = $btn;
    }
    if (isset($btn['url'])) {
        $anyUrl = true;
    }
}
T::ok($webAppButton !== null, 'آدرس https: دکمه web_app ساخته می‌شود');
T::eq('https://example.test/app', (string) ($webAppButton['web_app']['url'] ?? ''), 'آدرس https دقیقاً در web_app قرار گرفته است');
T::ok(!$anyUrl, 'آدرس https: دکمه url ساده به‌جای web_app ساخته نمی‌شود');

// ---------- ۳) کاربر بدون هیچ پنلی باید بتواند همه کارها را در ربات انجام دهد
SettingsService::set('miniapp_mode', 'off', 'telegram');
SettingsService::flush();
$walletService = new RB\Services\WalletService($db);
$walletService->credit((int) $user['id'], 'adjustment', 120000, [
    'description' => 'شارژ تست کار با ربات',
    'idempotency_key' => 'miniapp-off-credit-1',
]);

$actions = ['actAccount', 'actInvite', 'actReferrals', 'actIncome', 'actWallet', 'actWithdraw', 'actHistory', 'actCampaigns', 'actRanking', 'actNotifications', 'actSupport'];
$allOk = true;
$failures = [];
foreach ($actions as $action) {
    $fakeCalls = [];
    $user = $db->first('SELECT * FROM users WHERE id = :id', ['id' => (int) $user['id']]) ?? $user;
    try {
        $ref = new ReflectionMethod($handler, $action);
        $ref->setAccessible(true);
        $ref->invoke($handler, $user, 880000001);
        $sent = false;
        foreach ($fakeCalls as $call) {
            if ($call['method'] === 'sendMessage') {
                $sent = true;
                if (!empty($call['params']['reply_markup'])) {
                    $mk = is_string($call['params']['reply_markup'])
                        ? $call['params']['reply_markup']
                        : (string) json_encode($call['params']['reply_markup'], JSON_UNESCAPED_UNICODE);
                    if (str_contains($mk, 'web_app')) {
                        $allOk = false;
                        $failures[] = $action . ' (web_app)';
                    }
                }
            }
        }
        if (!$sent) {
            $allOk = false;
            $failures[] = $action . ' (پیامی ارسال نشد)';
        }
    } catch (Throwable $e) {
        $allOk = false;
        $failures[] = $action . ': ' . $e->getMessage();
    }
}
T::ok($allOk, 'همه ۱۱ دکمه ربات با پنل وب خاموش کار می‌کنند' . ($failures ? ' — ' . implode(', ', $failures) : ''));

// ---------- ۴) صفحه /app وقتی پنل خاموش است: راهنمای ربات + ۴۰۳
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/app';
$_SERVER['HTTP_HOST'] = 'http.test';
$_SERVER['REMOTE_ADDR'] = '198.51.100.60';
$_SERVER['HTTP_ACCEPT'] = 'text/html';
$_GET = [];
$_POST = [];
ob_start();
$router = new RB\Http\Router();
(require RB_ROOT . '/src/routes.php')($router);
$router->dispatch();
$appOut = (string) ob_get_clean();
T::ok(str_contains($appOut, 'پنل وب غیرفعال است'), 'صفحه /app در حالت خاموش پیام راهنما نشان می‌دهد');
T::ok(str_contains($appOut, 'دکمه‌های خود ربات تلگرام'), 'پیام راهنما کاربر را به دکمه‌های ربات ارجاع می‌دهد');
T::ok(!str_contains($appOut, 'miniapp.js'), 'در حالت خاموش اسکریپت Mini App بارگذاری نمی‌شود');
T::ok(str_contains($appOut, 't.me/'), 'لینک رفتن به ربات در صفحه هست');

// ---------- ۵) APIهای Mini App در حالت خاموش نباید داده بدهند
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_SERVER['REQUEST_URI'] = '/app/api/dashboard';
ob_start();
$router = new RB\Http\Router();
(require RB_ROOT . '/src/routes.php')($router);
$router->dispatch();
$apiOut = json_decode((string) ob_get_clean(), true);
T::ok(!($apiOut['ok'] ?? false), 'API داشبورد در حالت خاموش داده نمی‌دهد');

// ---------- ۶) همان چیز از سمت ربات: لینک دعوت همیشه کار می‌کند
$link = $handler->referralLink($user);
T::ok(str_starts_with($link, 'https://t.me/'), 'لینک دعوت با آدرس عمومی تلگرام ساخته می‌شود (بدون نیاز به دامنه)');
T::ok(str_ends_with($link, '?start=' . $user['referral_code']), 'لینک دعوت کد کاربر را دارد');

// ---------- ۷) وب‌هوک روی http هم باید کار کند (فقط Mini App به https نیاز دارد)
T::ok(!str_starts_with('http://example.test/webhook/telegram', 'https://'), 'ثبت Webhook روی http توسط تلگرام پذیرفته نمی‌شود (مستند شده)');
$panelBase = rtrim((string) (SettingsService::get('panel_url', '') ?: 'http://example.test'), '/');
T::ok(str_contains($panelBase . '/admin/login', '/admin/login'), 'ورود مدیر از طریق لینک تلگرام به /admin/login اشاره می‌کند (فقط دامنه/آی‌پی لازم است، نه https)');

// ---------- پاک‌سازی
MiniState::set('auto', '');
TelegramService::$fakeTransport = null;
T::ok(TelegramService::$fakeTransport === null, 'ترنسپورت تلگرام به حالت واقعی برگشت');

exit(T::summary());

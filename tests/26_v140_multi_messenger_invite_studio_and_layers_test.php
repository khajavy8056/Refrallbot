<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Bot\BotHandler;
use RB\Http\Router;
use RB\Messengers\AbstractMessengerDriver;
use RB\Messengers\MessengerCapabilities;
use RB\Messengers\MessengerRegistry;
use RB\Services\AdminAuth;
use RB\Services\BroadcastService;
use RB\Services\CommissionService;
use RB\Services\CommissionSettings;
use RB\Services\CommissionSettingsService;
use RB\Services\CronService;
use RB\Services\InviteContentService;
use RB\Services\MembershipService;
use RB\Services\SettingsService;
use RB\Services\StatsService;
use RB\Services\TelegramService;
use RB\Services\UserService;
use RB\Services\WalletService;
use RB\Services\WebAppAuthService;
use RB\Support\Csrf;
use RB\Support\Database;
use RB\Support\Migrator;
use RB\Support\Request;
use RB\Support\SchemaBuilder;
use RB\Support\Session;

T::suite('v1.4.0 — یکپارچگی ۴ پیام‌رسان، استودیوی دعوت و بنر، حالت‌های نمایش و لایه‌های ۰ تا ۸');

$db = Database::instance();
$users = new UserService($db);
$wallets = new WalletService($db);

// =========================================================================
// ۱) بررسی Migration 004، سازگاری کامل MySQL 8 Strict + utf8mb4 و Idempotency
// =========================================================================
$expectedTables = [
    'messenger_connections',
    'user_messenger_accounts',
    'user_link_tokens',
    'messenger_processed_updates',
    'invite_messages',
    'invite_banners',
];
foreach ($expectedTables as $tbl) {
    T::ok($db->tableExists($tbl), "جدول جدید {$tbl} ساخته شد");
}

$expectedCols = [
    ['users', 'entry_platform'],
    ['users', 'referral_source_platform'],
    ['telegram_channels', 'platform'],
    ['telegram_channels', 'check_mode'],
    ['referral_events', 'platform'],
    ['telegram_messages', 'platform'],
    ['broadcast_campaigns', 'target_platform'],
];
foreach ($expectedCols as [$tbl, $col]) {
    T::ok($db->columnExists($tbl, $col), "ستون جدید {$tbl}.{$col} ساخته شد");
}

// اجرای مجدد Migrator باید کاملاً Idempotent باشد
$rerun = (new Migrator($db))->run();
T::eq([], $rerun['applied'] ?? [], 'اجرای مجدد Migration 004 کاملاً Idempotent است');

// بررسی خروجی DDL واقعی MySQL برای Migration 004
$mysqlSchema = new SchemaBuilder('mysql');
$mig004 = require RB_ROOT . '/database/migrations/004_multi_messenger_invite_studio_and_layers.php';
$mig004($mysqlSchema);
$stmts = $mysqlSchema->statements();
$createStmts = array_values(array_filter($stmts, static fn($s) => str_starts_with((string) $s, 'CREATE TABLE')));
$alterStmts = array_values(array_filter($stmts, static fn($s) => str_starts_with((string) $s, 'ALTER TABLE')));
T::eq(6, count($createStmts), 'Migration 004 دقیقاً ۶ جدول جدید MySQL تولید می‌کند');
T::eq(7, count($alterStmts), 'Migration 004 دقیقاً ۷ دستور ALTER TABLE برای MySQL تولید می‌کند');
foreach ($createStmts as $sql) {
    T::ok(
        str_contains($sql, 'CHARSET=utf8mb4') && str_contains($sql, 'COLLATE=utf8mb4_unicode_ci'),
        'جدول MySQL در Migration 004 دارای utf8mb4_unicode_ci است'
    );
}
foreach ($alterStmts as $sql) {
    T::ok(
        (bool) preg_match('/^ALTER TABLE `\w+` ADD COLUMN `\w+` /', $sql),
        'دستور ALTER TABLE معتبر MySQL در Migration 004'
    );
}

// بررسی فایل VERSION
T::ok(version_compare(trim((string) file_get_contents(RB_ROOT . '/VERSION')), '1.4.0', '>='), 'نسخه سامانه به 1.4.0 یا بالاتر ارتقا یافته است');

// =========================================================================
// ۲) شبیه‌ساز ترنسپورت برای هر ۴ پیام‌رسان + ثبت و رمزنگاری امن توکن‌ها
// =========================================================================
$outbox = [
    'telegram' => [],
    'bale'     => [],
    'rubika'   => [],
    'eitaa'    => [],
];
$channelMembership = [
    'telegram' => true,
    'bale'     => true,
];
$baleShouldFail = false;
$rubikaQueuedUpdates = [];

$tgToken  = '111111111:AAFakeTokenTelegramForAutomatedTests1';
$baleTok  = '222222222:BBFakeTokenBaleBotForAutomatedTests12';
$rubTok   = 'CCFakeTokenRubikaBotApiV3ForAutomatedTests_123456';
$eitaaTok = '333333333:DDFakeTokenEitaaAppForAutomatedTests1';
$eitaaCh  = 'eitaayar_channel_api_token_test_777';

TelegramService::$fakeTransport = static function (string $method, array $params) use (&$outbox, &$channelMembership): array {
    $outbox['telegram'][] = ['method' => $method, 'params' => $params];
    return match ($method) {
        'getMe' => ['ok' => true, 'result' => ['id' => 111111111, 'is_bot' => true, 'first_name' => 'ربات تلگرام تست', 'username' => 'tg_v140_bot']],
        'setWebhook', 'deleteWebhook', 'setMyCommands', 'setChatMenuButton', 'answerCallbackQuery' => ['ok' => true, 'result' => true],
        'getWebhookInfo' => ['ok' => true, 'result' => ['url' => 'https://app.example.ir/webhook/telegram', 'pending_update_count' => 0]],
        'getChat' => ['ok' => true, 'result' => ['id' => -100111, 'title' => 'کانال تلگرام', 'type' => 'channel']],
        'getChatMember' => ['ok' => true, 'result' => ['status' => $channelMembership['telegram'] ? 'member' : 'left']],
        'sendPhoto' => ['ok' => true, 'result' => ['message_id' => count($outbox['telegram']), 'photo' => [['file_id' => 'tg_cached_photo_1']]]],
        default => ['ok' => true, 'result' => ['message_id' => count($outbox['telegram'])]],
    };
};

AbstractMessengerDriver::setFakeTransport('telegram', static fn(string $m, array $p): array => (TelegramService::$fakeTransport)($m, $p));

AbstractMessengerDriver::setFakeTransport('bale', static function (string $method, array $params) use (&$outbox, &$channelMembership, &$baleShouldFail): array {
    if ($baleShouldFail) {
        return ['ok' => false, 'error_code' => 502, 'description' => 'Bale upstream temporary timeout'];
    }
    $outbox['bale'][] = ['method' => $method, 'params' => $params];
    return match ($method) {
        'getMe' => ['ok' => true, 'result' => ['id' => 222222222, 'is_bot' => true, 'first_name' => 'بازوی بله تست', 'username' => 'bale_v140_bot']],
        'setWebhook', 'deleteWebhook', 'answerCallbackQuery' => ['ok' => true, 'result' => true],
        'getWebhookInfo' => ['ok' => true, 'result' => ['url' => 'https://app.example.ir/webhook/bale/sec', 'pending_update_count' => 0]],
        'getChat' => ['ok' => true, 'result' => ['id' => 500111, 'title' => 'کانال بله', 'type' => 'channel']],
        'getChatMember' => ['ok' => true, 'result' => ['status' => $channelMembership['bale'] ? 'member' : 'left']],
        'sendPhoto' => ['ok' => true, 'result' => ['message_id' => count($outbox['bale']), 'photo' => [['file_id' => 'bale_cached_photo_1']]]],
        default => ['ok' => true, 'result' => ['message_id' => count($outbox['bale'])]],
    };
});

AbstractMessengerDriver::setFakeTransport('rubika', static function (string $method, array $params) use (&$outbox, &$rubikaQueuedUpdates): array {
    $outbox['rubika'][] = ['method' => $method, 'params' => $params];
    return match ($method) {
        'getMe' => [
            'ok' => true,
            'status' => 'OK',
            'data' => ['bot' => [
                'bot_id' => 'b0RubikaGuidV140',
                'bot_title' => 'بات روبیکا تست',
                'username' => 'rubika_v140_bot',
                'description' => 'بات رسمی دعوت دوستان در روبیکا',
                'start_message' => 'خوش آمدید',
                'share_url' => 'https://rubika.ir/rubika_v140_bot',
            ]],
        ],
        'updateBotEndpoints' => ['ok' => true, 'status' => 'OK', 'data' => ['status' => 'Done']],
        'requestSendFile' => ['ok' => true, 'status' => 'OK', 'data' => ['upload_url' => 'https://upload.rubika.test/put']],
        'uploadFile' => ['ok' => true, 'status' => 'OK', 'data' => ['file_id' => 'rub_cached_file_99']],
        'sendFile', 'sendMessage' => ['ok' => true, 'status' => 'OK', 'data' => ['message_id' => 'rm_' . count($outbox['rubika'])]],
        'getUpdates' => (static function () use (&$rubikaQueuedUpdates): array {
            $batch = $rubikaQueuedUpdates;
            $rubikaQueuedUpdates = [];
            return ['ok' => true, 'status' => 'OK', 'data' => ['updates' => $batch, 'next_offset_id' => 'next_off_42']];
        })(),
        default => ['ok' => true, 'status' => 'OK', 'data' => []],
    };
});

AbstractMessengerDriver::setFakeTransport('eitaa', static function (string $method, array $params) use (&$outbox): array {
    $outbox['eitaa'][] = ['method' => $method, 'params' => $params];
    return match ($method) {
        'getMe' => ['ok' => true, 'result' => ['id' => 333333333, 'first_name' => 'برنامک ایتا تست', 'username' => 'eitaa_v140_bot']],
        default => ['ok' => true, 'result' => ['message_id' => count($outbox['eitaa'])]],
    };
});

SettingsService::set('app_name', 'رفرال‌پلاس', 'general');
SettingsService::set('base_url', 'https://app.example.ir', 'general');
SettingsService::set('webhook_base_url', 'https://app.example.ir', 'telegram');
SettingsService::set('miniapp_url', 'https://app.example.ir/app', 'telegram');
SettingsService::set('miniapp_mode', 'auto', 'telegram');
SettingsService::set('bot_ui_mode', 'hybrid', 'telegram');

$registry = new MessengerRegistry($db);

// ذخیره و فعال‌سازی هر ۴ پیام‌رسان
$tgSave = $registry->saveCredentials('telegram', ['bot_token' => $tgToken, 'is_enabled' => 1]);
$baleSave = $registry->saveCredentials('bale', ['bot_token' => $baleTok, 'is_enabled' => 1]);
$rubSave = $registry->saveCredentials('rubika', ['bot_token' => $rubTok, 'is_enabled' => 1, 'polling_enabled' => 1]);
$eitaaSave = $registry->saveCredentials('eitaa', [
    'bot_token' => $eitaaTok,
    'secondary_token' => $eitaaCh,
    'bot_username' => 'eitaa_v140_bot',
    'bot_name' => 'برنامک ایتا رفرال‌پلاس',
    'app_slug' => 'app',
    'is_enabled' => 1,
]);

T::eq(true, $tgSave['ok'], 'اتصال تلگرام با موفقیت ثبت و تست شد');
T::eq(true, $baleSave['ok'], 'اتصال بازوی بله با موفقیت ثبت و تست شد');
T::eq(true, $rubSave['ok'], 'اتصال بات روبیکا با موفقیت ثبت و تست شد');
T::eq(true, $eitaaSave['ok'], 'اتصال برنامک ایتا با موفقیت ثبت و تست شد');

// بررسی رمزنگاری توکن‌ها در دیتابیس (عدم ذخیره plaintext)
foreach (['telegram' => $tgToken, 'bale' => $baleTok, 'rubika' => $rubTok, 'eitaa' => $eitaaTok] as $plat => $plain) {
    $rawRow = $db->first('SELECT bot_token_enc FROM messenger_connections WHERE platform = :p', ['p' => $plat]);
    $enc = (string) ($rawRow['bot_token_enc'] ?? '');
    T::ok(str_starts_with($enc, 'v1:'), "توکن {$plat} با الگوریتم نسخه‌دار AES-256-GCM ذخیره شد");
    T::ok(!str_contains($enc, $plain), "متن خام توکن {$plat} در دیتابیس افشا نشده است");
}

// ثبت Webhook برای تلگرام، بله و روبیکا (هر دو Endpoint روبیکا)
T::eq(true, $registry->registerWebhook('telegram')['ok'], 'وب‌هوک تلگرام ثبت شد');
T::eq(true, $registry->registerWebhook('bale')['ok'], 'وب‌هوک بله ثبت شد');
$outbox['rubika'] = [];
T::eq(true, $registry->registerWebhook('rubika')['ok'], 'وب‌هوک روبیکا ثبت شد');
$rubEndpoints = array_values(array_filter($outbox['rubika'], static fn($c) => $c['method'] === 'updateBotEndpoints'));
T::eq(2, count($rubEndpoints), 'روبیکا هر دو Endpoint رسمی ReceiveUpdate و ReceiveInlineMessage را ثبت کرد');

// بررسی لینک‌های دعوت اختصاصی هر ۴ پیام‌رسان
$allLinks = $registry->allReferralLinksForCode('ABC12345');
T::eq(4, count($allLinks), 'لینک دعوت اختصاصی برای هر ۴ پیام‌رسان فعال تولید شد');
$linkByPlat = array_column($allLinks, 'link', 'platform');
T::eq('https://t.me/tg_v140_bot?start=ABC12345', $linkByPlat['telegram'] ?? '', 'لینک دعوت تلگرام دقیق است');
T::eq('https://ble.ir/bale_v140_bot?start=ABC12345', $linkByPlat['bale'] ?? '', 'لینک دعوت بله (ble.ir) دقیق است');
T::eq('https://rubika.ir/rubika_v140_bot?st=ABC12345', $linkByPlat['rubika'] ?? '', 'لینک دعوت روبیکا (rubika.ir?st=) دقیق است');
T::eq('https://eitaa.com/eitaa_v140_bot/app?startapp=ABC12345', $linkByPlat['eitaa'] ?? '', 'لینک دعوت برنامک ایتا (startapp=) دقیق است');

// بررسی صداقت ماتریس قابلیت‌ها طبق مستندات رسمی
T::eq(true, MessengerCapabilities::supports('telegram', 'miniapp'), 'تلگرام از Mini App پشتیبانی می‌کند');
T::eq(true, MessengerCapabilities::supports('bale', 'miniapp'), 'بله از Mini App پشتیبانی می‌کند');
T::eq(false, MessengerCapabilities::supports('rubika', 'miniapp'), 'روبیکا به‌صداقت فاقد Mini App است');
T::eq(true, MessengerCapabilities::supports('eitaa', 'miniapp'), 'ایتا از Mini App (برنامک) پشتیبانی می‌کند');
T::eq(false, MessengerCapabilities::supports('eitaa', 'private_messages'), 'ایتا به‌صداقت فاقد پیام خصوصی رباتی است');
T::eq(false, MessengerCapabilities::supports('rubika', 'channel_check_api'), 'روبیکا فاقد API بررسی عضویت کانال است');

// =========================================================================
// ۳) تست ۳ حالت نمایش (miniapp / buttons / hybrid) × ۴ پیام‌رسان + Fallback امن HTTP
// =========================================================================
SettingsService::set('bot_ui_mode', 'miniapp', 'telegram');
SettingsService::set('miniapp_mode', 'auto', 'telegram');
SettingsService::set('miniapp_url', 'https://app.example.ir/app', 'telegram');
T::eq('miniapp', SettingsService::resolveUiModeFor('telegram')['effective'], 'حالت miniapp روی HTTPS → تلگرام: miniapp');
T::eq('miniapp', SettingsService::resolveUiModeFor('bale')['effective'], 'حالت miniapp روی HTTPS → بله: miniapp');
T::eq('miniapp', SettingsService::resolveUiModeFor('eitaa')['effective'], 'حالت miniapp روی HTTPS → ایتا: miniapp');
$rubMode = SettingsService::resolveUiModeFor('rubika');
T::eq('buttons', $rubMode['effective'], 'حالت miniapp → روبیکا به‌طور خودکار به buttons برمی‌گردد');
T::eq(true, $rubMode['fallback_used'], 'Fallback خودکار روبیکا با شفافیت ثبت شد');

SettingsService::set('bot_ui_mode', 'buttons', 'telegram');
T::eq('buttons', SettingsService::resolveUiModeFor('telegram')['effective'], 'حالت buttons → تلگرام: buttons');
T::eq('buttons', SettingsService::resolveUiModeFor('bale')['effective'], 'حالت buttons → بله: buttons');
T::eq('buttons', SettingsService::resolveUiModeFor('rubika')['effective'], 'حالت buttons → روبیکا: buttons');
T::eq('miniapp', SettingsService::resolveUiModeFor('eitaa')['effective'], 'حالت buttons → ایتا به‌صورت امن روی برنامک می‌ماند');

// تست Fallback روی HTTP بدون SSL
SettingsService::set('bot_ui_mode', 'miniapp', 'telegram');
SettingsService::set('miniapp_url', 'http://insecure.example.ir/app', 'telegram');
SettingsService::set('base_url', 'http://insecure.example.ir', 'general');
T::eq('buttons', SettingsService::resolveUiModeFor('telegram')['effective'], 'روی HTTP بدون SSL، حالت miniapp تلگرام خودکار به buttons برمی‌گردد');
T::eq('buttons', SettingsService::resolveUiModeFor('bale')['effective'], 'روی HTTP بدون SSL، حالت miniapp بله خودکار به buttons برمی‌گردد');

// بازگردانی HTTPS و حالت ترکیبی (Hybrid)
SettingsService::set('base_url', 'https://app.example.ir', 'general');
SettingsService::set('miniapp_url', 'https://app.example.ir/app', 'telegram');
SettingsService::set('bot_ui_mode', 'hybrid', 'telegram');
SettingsService::set('miniapp_mode', 'auto', 'telegram');
T::eq('hybrid', SettingsService::resolveUiModeFor('telegram')['effective'], 'حالت hybrid روی HTTPS → تلگرام: hybrid');
T::eq('hybrid', SettingsService::resolveUiModeFor('bale')['effective'], 'حالت hybrid روی HTTPS → بله: hybrid');

// =========================================================================
// ۴) موتور پورسانت لایه ۰ (ثابت، بدون پرداخت مضاعف) + لایه‌های ۱ تا ۵ (ترکیب درصدی، ثابت، سقف و غیرفعال)
//    در زنجیره واقعی بین ۴ پیام‌رسان مختلف (تلگرام ← بله ← روبیکا ← ایتا ← بله ← روبیکا ← تلگرام)
// =========================================================================
SettingsService::set('contact_required', '0', 'telegram');
SettingsService::set('force_channel_membership', '0', 'telegram');

// اعتبارسنجی ترکیب ثابت و درصدی
[$isValid, $valErrs] = CommissionSettingsService::validate([
    'direct_reward' => 20000,
    'direct_enabled' => 1,
    'invitee_reward' => 8000,
    'invitee_enabled' => 1,
    'multi_level_enabled' => 1,
    'max_levels' => 5,
    'levels' => [
        ['level' => 1, 'calc_type' => 'percent', 'percent' => '25', 'amount' => 0,    'max_amount' => 0,    'enabled' => true],  // 25% of 20k = 5,000
        ['level' => 2, 'calc_type' => 'fixed',   'percent' => '0',  'amount' => 3500, 'max_amount' => 0,    'enabled' => true],  // fixed 3,500
        ['level' => 3, 'calc_type' => 'percent', 'percent' => '20', 'amount' => 0,    'max_amount' => 2500, 'enabled' => true],  // 20%=4,000 capped to 2,500
        ['level' => 4, 'calc_type' => 'fixed',   'percent' => '0',  'amount' => 9000, 'max_amount' => 0,    'enabled' => false], // disabled = 0
        ['level' => 5, 'calc_type' => 'fixed',   'percent' => '0',  'amount' => 1000, 'max_amount' => 0,    'enabled' => true],  // fixed 1,000
    ],
    'percent_base' => 'direct_reward',
    'qualify_conditions' => ['joined'],
]);
T::eq(true, $isValid, 'تنظیمات ترکیبی درصدی/ثابت/سقف‌دار لایه‌های ۱ تا ۵ معتبر است: ' . implode(', ', $valErrs));

CommissionSettings::saveVersion([
    'direct_reward' => 20000,
    'direct_enabled' => 1,
    'invitee_reward' => 8000,
    'invitee_enabled' => 1,
    'multi_level_enabled' => 1,
    'max_levels' => 5,
    'levels' => [
        ['level' => 1, 'calc_type' => 'percent', 'percent' => '25', 'amount' => 0,    'max_amount' => 0,    'enabled' => true],
        ['level' => 2, 'calc_type' => 'fixed',   'percent' => '0',  'amount' => 3500, 'max_amount' => 0,    'enabled' => true],
        ['level' => 3, 'calc_type' => 'percent', 'percent' => '20', 'amount' => 0,    'max_amount' => 2500, 'enabled' => true],
        ['level' => 4, 'calc_type' => 'fixed',   'percent' => '0',  'amount' => 9000, 'max_amount' => 0,    'enabled' => false],
        ['level' => 5, 'calc_type' => 'fixed',   'percent' => '0',  'amount' => 1000, 'max_amount' => 0,    'enabled' => true],
    ],
    'percent_base' => 'direct_reward',
    'qualify_conditions' => ['joined'],
]);

// بررسی پیش‌نمایش محاسبات لایه‌ای
$curCom = CommissionSettings::current($db, true);
T::eq(20000, $curCom->effectiveDirectReward(), 'پیش‌نمایش: پاداش لایه ۰ مستقیم = ۲۰٬۰۰۰ تومان');
T::eq(8000, $curCom->effectiveInviteeReward(), 'پیش‌نمایش: هدیه خوش‌آمدگویی = ۸٬۰۰۰ تومان');
T::eq(5000 + 3500 + 2500 + 0 + 1000, $curCom->estimatedIndirectTotal(), 'پیش‌نمایش: مجموع لایه‌های غیرمستقیم = ۱۲٬۰۰۰ تومان');
T::eq(40000, $curCom->effectiveDirectReward() + $curCom->effectiveInviteeReward() + $curCom->estimatedIndirectTotal(), 'پیش‌نمایش: مجموع کل پرداخت هر رویداد = ۴۰٬۰۰۰ تومان');

// U1: عضویت از طریق ربات تلگرام
(new BotHandler($db))->handle([
    'update_id' => 940001,
    'message' => [
        'message_id' => 1,
        'from' => ['id' => 810001, 'first_name' => 'آرش', 'last_name' => 'تلگرامی', 'username' => 'arash_tg'],
        'chat' => ['id' => 810001, 'type' => 'private'],
        'text' => '/start',
    ],
]);
$u1 = $users->findByMessenger('telegram', '810001');
T::ok($u1 !== null && $u1['entry_platform'] === 'telegram', 'U1 در تلگرام ثبت شد');

// U2: عضویت در بله با کد دعوت U1
(new BotHandler($db, $registry->driver('bale')))->handle([
    'update_id' => 940002,
    'message' => [
        'message_id' => 10,
        'from' => ['id' => 820002, 'first_name' => 'بهار', 'last_name' => 'بله‌ای', 'username' => 'bahar_bale'],
        'chat' => ['id' => 820002, 'type' => 'private'],
        'text' => '/start ' . $u1['referral_code'],
    ],
]);
$u2 = $users->findByMessenger('bale', '820002');
T::ok($u2 !== null && $u2['entry_platform'] === 'bale', 'U2 در پیام‌رسان بله با کد دعوت U1 ثبت شد');
T::ok((int) $u2['telegram_id'] < 0, 'کاربر بله شناسه سنتتیک منفی دارد و با telegram_id واقعی تداخل ندارد');
T::eq((int) $u1['id'], (int) $u2['referred_by'], 'معرف مستقیم U2 برابر U1 (بین‌پلتفرمی تلگرام←بله) ثبت شد');
T::eq(20000, (int) $users->find((int) $u1['id'])['direct_earned'], 'U1 دقیقاً ۲۰٬۰۰۰ مستقیم گرفت');
T::eq(0, (int) $users->find((int) $u1['id'])['layer_earned'], 'U1 هیچ پورسانت غیرمستقیم مضاعفی از دعوت مستقیم نگرفت');
T::eq(8000, (int) $users->find((int) $u2['id'])['welcome_earned'], 'U2 دقیقاً ۸٬۰۰۰ هدیه خوش‌آمدگویی گرفت');

// U3: عضویت در روبیکا با aux_data.start_id رسمی (لینک rubika.ir/bot?st=CODE) از U2
(new BotHandler($db, $registry->driver('rubika')))->handle([
    'type' => 'NewMessage',
    'chat_id' => 'b0UserRubikaChat03',
    'new_message' => [
        'message_id' => 'msg_rub_001',
        'text' => '/start',
        'time' => (string) time(),
        'sender_type' => 'User',
        'sender_id' => 'u0RubikaSender03',
        'aux_data' => ['start_id' => $u2['referral_code'], 'button_id' => null],
    ],
]);
$u3 = $users->findByMessenger('rubika', 'u0RubikaSender03');
T::ok($u3 !== null && $u3['entry_platform'] === 'rubika', 'U3 در روبیکا با GUID رشته‌ای و start_id رسمی ثبت شد');
T::eq((int) $u2['id'], (int) $u3['referred_by'], 'معرف مستقیم U3 برابر U2 (بله←روبیکا) ثبت شد');
T::eq(5000, (int) $users->find((int) $u1['id'])['layer_earned'], 'U1 بابت U3 (لایه ۱ غیرمستقیم) دقیقاً ۵٬۰۰۰ تومان (۲۵٪) دریافت کرد');

// U4: عضویت در برنامک ایتا با احراز هویت رمزنگاری‌شده HMAC-SHA256 و start_param از U3
$eitaaInit = WebAppAuthService::buildSignedInitData(
    ['id' => 840004, 'first_name' => 'الناز', 'last_name' => 'ایتایی', 'username' => 'elnaz_eitaa'],
    $eitaaTok,
    time(),
    (string) $u3['referral_code']
);
$authEitaa = WebAppAuthService::authenticate($eitaaInit, 'eitaa', null, $db);
T::eq(true, $authEitaa['ok'], 'احراز هویت HMAC-SHA256 برنامک ایتا موفق بود');
$u4 = $authEitaa['user'];
T::eq('eitaa', (string) $u4['entry_platform'], 'U4 در برنامک ایتا ثبت شد');
T::eq((int) $u3['id'], (int) $u4['referred_by'], 'معرف مستقیم U4 برابر U3 (روبیکا←ایتا) ثبت شد');

// U5: عضویت در مینی‌اپ بله با احراز هویت HMAC-SHA256 و start_param از U4
$baleInit = WebAppAuthService::buildSignedInitData(
    ['id' => 850005, 'first_name' => 'پویا', 'last_name' => 'مینی‌بله'],
    $baleTok,
    time(),
    (string) $u4['referral_code']
);
$authBale = WebAppAuthService::authenticate($baleInit, 'bale', null, $db);
T::eq(true, $authBale['ok'], 'احراز هویت HMAC-SHA256 مینی‌اپ بله موفق بود');
$u5 = $authBale['user'];

// عکس‌برداری قبل از عضویت U6 برای بررسی دقیق تک‌تک لایه‌ها
$snap = [];
foreach ([1 => $u1, 2 => $u2, 3 => $u3, 4 => $u4, 5 => $u5] as $idx => $usr) {
    $fresh = $users->find((int) $usr['id']);
    $snap[$idx] = [
        'direct' => (int) $fresh['direct_earned'],
        'layer'  => (int) $fresh['layer_earned'],
        'bal'    => (int) $fresh['wallet_balance'],
    ];
}

// U6: عضویت در روبیکا با کد دعوت U5 → بررسی تمام لایه‌های ۰ تا ۴
$u6 = $users->registerFromMessenger(
    'rubika',
    ['id' => 'u0RubikaSender06', 'first_name' => 'سارا', 'last_name' => 'روبیکا'],
    (string) $u5['referral_code'],
    ['source_platform' => 'rubika', 'chat_id' => 'b0ChatRubika06']
)['user'];

$u6Fresh = $users->find((int) $u6['id']);
$u5Fresh = $users->find((int) $u5['id']);
$u4Fresh = $users->find((int) $u4['id']);
$u3Fresh = $users->find((int) $u3['id']);
$u2Fresh = $users->find((int) $u2['id']);
$u1Fresh = $users->find((int) $u1['id']);

T::eq(8000, (int) $u6Fresh['welcome_earned'], 'U6: هدیه خوش‌آمدگویی ۸٬۰۰۰ تومان واریز شد');
T::eq(20000, (int) $u5Fresh['direct_earned'] - $snap[5]['direct'], 'U5 (لایه ۰ مستقیم): پاداش ثابت ۲۰٬۰۰۰ تومان واریز شد');
T::eq(0, (int) $u5Fresh['layer_earned'] - $snap[5]['layer'], 'U5 (لایه ۰ مستقیم): پورسانت غیرمستقیم = ۰ (بدون دوباره‌پرداخت)');
T::eq(5000, (int) $u4Fresh['layer_earned'] - $snap[4]['layer'], 'U4 (لایه ۱ غیرمستقیم — ایتا): ۲۵٪ = ۵٬۰۰۰ تومان واریز شد');
T::eq(3500, (int) $u3Fresh['layer_earned'] - $snap[3]['layer'], 'U3 (لایه ۲ غیرمستقیم — روبیکا): مبلغ ثابت ۳٬۵۰۰ تومان واریز شد');
T::eq(2500, (int) $u2Fresh['layer_earned'] - $snap[2]['layer'], 'U2 (لایه ۳ غیرمستقیم — بله): ۲۰٪ با سقف ۲٬۵۰۰ تومان دقیق اعمال شد');
T::eq(0, (int) $u1Fresh['layer_earned'] - $snap[1]['layer'], 'U1 (لایه ۴ غیرمستقیم غیرفعال): ۰ تومان');

// U7: عضویت در تلگرام با کد U6 → U1 اکنون در لایه ۵ غیرمستقیم (depth=6) است و باید ۱٬۰۰۰ ثابت بگیرد!
$u1LayerBefore7 = (int) $u1Fresh['layer_earned'];
$u7 = $users->registerFromMessenger(
    'telegram',
    ['id' => '870007', 'first_name' => 'کیان', 'last_name' => 'تلگرامی'],
    (string) $u6['referral_code'],
    ['source_platform' => 'telegram']
)['user'];
T::eq(1000, (int) $users->find((int) $u1['id'])['layer_earned'] - $u1LayerBefore7, 'U1 (لایه ۵ غیرمستقیم): مبلغ ثابت ۱٬۰۰۰ تومان واریز شد');

// تطبیق ۱۰۰٪ دفتر کل مالی (Ledger Reconciliation) برای هر ۷ کاربر در ۴ پیام‌رسان
foreach ([$u1, $u2, $u3, $u4, $u5, $u6, $u7] as $idx => $usr) {
    $rec = $wallets->reconcile((int) $usr['id']);
    T::eq(true, $rec['match'], 'تطبیق دفتر کل مالی کاربر U' . ($idx + 1) . ' کاملاً دقیق است');
}

// بررسی شفافیت تفکیک لایه ۰ و لایه‌های ۱+ در خلاصه کاربر
$u1Summary = $users->layerEarningsSummary((int) $u1['id']);
T::eq(20000, $u1Summary['direct_paid'], 'خلاصه U1: درآمد لایه ۰ مستقیم = ۲۰٬۰۰۰ تومان');
T::eq(1, $u1Summary['direct_count'], 'خلاصه U1: تعداد دعوت مستقیم (لایه ۰) = ۱ نفر');
// U1 از U3 لایه ۱ (5000) + از U4 لایه ۲ (3500) + از U5 لایه ۳ (2500) + از U6 لایه ۴ (0) + از U7 لایه ۵ (1000) = 12000
T::eq(12000, $u1Summary['indirect_paid'], 'خلاصه U1: مجموع درآمد لایه‌های غیرمستقیم (۱ تا ۵) = ۱۲٬۰۰۰ تومان');
T::eq(5, $u1Summary['indirect_count'], 'خلاصه U1: تعداد زیرمجموعه‌های غیرمستقیم = ۵ نفر');

// =========================================================================
// ۵) ایزوله‌سازی خطا بین پیام‌رسان‌ها (Fault Isolation)
// =========================================================================
$baleShouldFail = true;
$balePing = $registry->driver('bale')->sendMessage('820002', 'تست خطا');
T::eq(false, (bool) ($balePing['ok'] ?? true), 'خرابی موقت API بله شبیه‌سازی شد');

// در همان لحظه، تلگرام و روبیکا باید بدون کوچک‌ترین اختلال کار کنند!
$outbox['rubika'] = [];
(new BotHandler($db, $registry->driver('rubika')))->handle([
    'type' => 'NewMessage',
    'chat_id' => 'b0UserRubikaChat03',
    'new_message' => [
        'message_id' => 'msg_rub_income_check',
        'text' => '📈 درآمد من',
        'time' => (string) time(),
        'sender_type' => 'User',
        'sender_id' => 'u0RubikaSender03',
        'aux_data' => ['button_id' => 'menu:income'],
    ],
]);
T::ok(count($outbox['rubika']) >= 1, 'هنگام قطعی بله، بات روبیکا بدون هیچ اختلالی پاسخ داد');
$baleShouldFail = false;

// =========================================================================
// ۶) اتصال امن چند پیام‌رسان به یک کیف پول واحد + جلوگیری از ادغام خودکار و تقلب
// =========================================================================
// دو کاربر با شماره موبایل یکسان در دو پیام‌رسان هرگز نباید خودکار ادغام شوند
$users->setPhone((int) $u1['id'], '+989121110000');
$samePhoneBale = $users->registerFromMessenger('bale', ['id' => '829999', 'first_name' => 'هم‌شماره'], null)['user'];
$users->setPhone((int) $samePhoneBale['id'], '+989121110000');
T::ok((int) $samePhoneBale['id'] !== (int) $u1['id'], 'تشابه صرف شماره موبایل باعث ادغام خودکار حساب‌ها نمی‌شود');

// تولید کد یک‌بارمصرف تأیید اتصال برای U1 و اتصال هویت جدید بله به همان کیف پول
$linkTok = $users->createLinkToken((int) $u1['id']);
T::ok(str_starts_with($linkTok['code'], 'LNK-'), 'کد یک‌بارمصرف اتصال حساب با پیشوند LNK- ساخته شد');
$u1BalanceBefore = (int) $users->find((int) $u1['id'])['wallet_balance'];

$redeemOk = $users->redeemLinkToken($linkTok['code'], 'bale', ['id' => '821111', 'first_name' => 'آرش در بله'], '821111');
T::eq(true, $redeemOk['ok'], 'اتصال تأییدشده حساب بله به هویت مرکزی U1 موفق بود');
T::eq((int) $u1['id'], (int) ($users->findByMessenger('bale', '821111')['id'] ?? 0), 'هویت بله به همان user_id مرکزی U1 متصل شد');
T::eq($u1BalanceBefore, (int) $users->findByMessenger('bale', '821111')['wallet_balance'], 'موجودی کیف پول واحد بین تلگرام و بله U1 یکسان است');
T::eq(2, count($users->messengerAccounts((int) $u1['id'])), 'U1 اکنون ۲ پیام‌رسان متصل تأییدشده دارد');

// استفاده مجدد از همان توکن باید رد شود (One-Time Token)
$redeemReplay = $users->redeemLinkToken($linkTok['code'], 'rubika', ['id' => 'u0ReplayTest', 'first_name' => 'تست'], 'b0Replay');
T::eq(false, $redeemReplay['ok'], 'استفاده مجدد از کد یک‌بارمصرف اتصال رد شد');

// تلاش برای ادغام حساب دارای موجودی/زیرمجموعه (U2) با کد U1 باید به شکل امن رد شود
$linkTok2 = $users->createLinkToken((int) $u1['id']);
$unsafeMerge = $users->redeemLinkToken($linkTok2['code'], 'bale', ['id' => '820002', 'first_name' => 'بهار'], '820002');
T::eq(false, $unsafeMerge['ok'], 'ادغام خودکار حساب دارای موجودی/تراکنش/زیرمجموعه برای حفظ یکپارچگی مالی رد شد');

// =========================================================================
// ۷) امنیت احراز هویت WebApp (رد امضای دستکاری‌شده، پلتفرم اشتباه، تاریخ منقضی و Replay)
// =========================================================================
$tampered = $eitaaInit . 'x';
T::eq(false, WebAppAuthService::authenticate($tampered, 'eitaa', null, $db)['ok'], 'امضای دستکاری‌شده WebApp رد شد');

$crossPlatform = WebAppAuthService::buildSignedInitData(['id' => 999111, 'first_name' => 'تست'], $baleTok);
T::eq(false, WebAppAuthService::authenticate($crossPlatform, 'telegram', null, $db)['ok'], 'initData امضاشده با توکن بله در تلگرام رد شد');

$expiredInit = WebAppAuthService::buildSignedInitData(['id' => 999112, 'first_name' => 'منقضی'], $tgToken, time() - 90000);
T::eq(false, WebAppAuthService::authenticate($expiredInit, 'telegram', null, $db)['ok'], 'initData منقضی‌شده رد شد');

$freshTgInit = WebAppAuthService::buildSignedInitData(['id' => 810001, 'first_name' => 'آرش'], $tgToken);
T::eq(true, WebAppAuthService::authenticate($freshTgInit, 'telegram', null, $db)['ok'], 'اولین ارسال initData معتبر پذیرفته شد');
WebAppAuthService::logout(); // شبیه‌سازی نشست مهاجم با همان initData ضبط‌شده
T::eq(false, WebAppAuthService::authenticate($freshTgInit, 'telegram', null, $db)['ok'], 'ارسال تکراری همان initData در نشست جدید (Replay Attack) مسدود شد');

// =========================================================================
// ۸) استودیوی پیام دعوت و بنرهای تبلیغاتی (۴ حالت اشتراک + تضمین حضور لینک اختصاصی)
// =========================================================================
$invite = new InviteContentService($db);
$msgs = $invite->listMessages(true);
$banners = $invite->listBanners(true);
T::ok(count($msgs) >= 3, 'حداقل ۳ قالب پیام دعوت پیش‌فرض فارسی ساخته شد');
T::ok(count($banners) >= 3, 'حداقل ۳ بنر تبلیغاتی گرافیکی پیش‌فرض ساخته شد');

// بررسی وجود واقعی فایل‌های گرافیکی بنر روی دیسک
foreach ($banners as $bn) {
    $abs = $invite->bannerFilePath($bn);
    T::ok($abs !== null && is_file($abs) && filesize($abs) > 10000, 'فایل گرافیکی بنر «' . $bn['title'] . '» معتبر و موجود است');
}

// ایجاد قالب سفارشی بدون {referral_link} → سامانه باید لینک اختصاصی کاربر را خودکار به انتهای پیام اضافه کند!
$customMsgId = $invite->createMessage([
    'title' => 'قالب تست بدون متغیر لینک',
    'body' => 'سلام دوست من! به {app_name} بیا و {invitee_reward} هدیه بگیر! معرف تو: {name}',
    'is_enabled' => 1,
    'is_default' => 0,
]);
$u1Link = $registry->buildReferralLink('telegram', (string) $u1['referral_code']);
$customMsgRow = $invite->findMessage($customMsgId);
$renderedHtml = $invite->renderMessage($customMsgRow, $u1, $u1Link, 'telegram', true);
$renderedPlain = $invite->renderMessage($customMsgRow, $u1, $u1Link, 'telegram', false);
T::ok(str_contains($renderedHtml, $u1Link) && str_contains($renderedPlain, $u1Link), 'حتی بدون نوشتن {referral_link} در قالب، لینک اختصاصی کاربر به‌طور تضمینی درج شد');
T::ok(str_contains($renderedPlain, wtoman(8000)), 'مبلغ پویای هدیه خوش‌آمدگویی در پیام دعوت جایگذاری شد');

// ارسال بنر + کپشن + لینک اختصاصی در هر ۳ پیام‌رسان رباتی (تلگرام، بله، روبیکا)
$defaultBanner = $banners[0];
$defaultBannerId = (int) $defaultBanner['id'];
foreach (['telegram' => ['u' => $u1, 'chat' => '810001'], 'bale' => ['u' => $u2, 'chat' => '820002'], 'rubika' => ['u' => $u3, 'chat' => 'b0UserRubikaChat03']] as $plat => $info) {
    $drv = $registry->driver($plat);
    $platLink = $registry->buildReferralLink($plat, (string) $info['u']['referral_code']);
    $capHtml = $invite->renderBannerCaption($defaultBanner, $info['u'], $platLink, $plat, true, true);
    $photoPath = (string) $invite->bannerFilePath($defaultBanner);
    $res = $drv->sendPhoto($info['chat'], $photoPath, $capHtml);
    T::eq(true, (bool) ($res['ok'] ?? false), "ارسال بنر گرافیکی دعوت + کپشن و لینک اختصاصی در {$plat} موفق بود");
    if (!empty($res['file_id'])) {
        $invite->rememberFileId($defaultBannerId, $plat, (string) $res['file_id']);
    }
}
// بررسی کش شدن file_id بنر برای ارسال سریع بعدی
$refreshedBanner = $invite->findBanner($defaultBannerId);
T::eq('tg_cached_photo_1', (string) $invite->cachedFileId($refreshedBanner, 'telegram'), 'file_id بنر در تلگرام کش شد');
T::eq('bale_cached_photo_1', (string) $invite->cachedFileId($refreshedBanner, 'bale'), 'file_id بنر در بله کش شد');
T::eq('rub_cached_file_99', (string) $invite->cachedFileId($refreshedBanner, 'rubika'), 'file_id بنر در روبیکا کش شد');

// بسته کامل استودیوی دعوت برای Mini App (هر ۴ حالت)
$pkg = $invite->buildUserStudioPayload($u4, 'eitaa');
T::eq('https://eitaa.com/eitaa_v140_bot/app?startapp=' . $u4['referral_code'], $pkg['primary_link'], 'بسته دعوت برنامک ایتا لینک دقیق اختصاصی برگرداند');
T::ok(count($pkg['messages']) >= 3 && count($pkg['banners']) >= 3, 'بسته دعوت Mini App شامل همه پیام‌ها و بنرهای فعال با لینک رندرشده است');
T::ok(str_contains((string) ($pkg['banners'][0]['caption'] ?? ''), $pkg['primary_link']), 'کپشن بنر در ایتا شامل لینک اختصاصی دعوت کاربر است');

// =========================================================================
// ۹) کانال اجباری چندپیام‌رسانی (API در تلگرام/بله + تأیید کاربر در روبیکا/ایتا)
// =========================================================================
$rubChId = $db->insert('telegram_channels', [
    'platform' => 'rubika',
    'check_mode' => 'self_confirm',
    'title' => 'کانال رسمی روبیکا',
    'chat_id' => '@rubika_official_ch',
    'invite_link' => 'https://rubika.ir/rubika_official_ch',
    'is_active' => 1,
    'sort_order' => 1,
    'created_at' => now(),
]);
$baleChId = $db->insert('telegram_channels', [
    'platform' => 'bale',
    'check_mode' => 'api',
    'title' => 'کانال رسمی بله',
    'chat_id' => '@bale_official_ch',
    'invite_link' => 'https://ble.ir/bale_official_ch',
    'is_active' => 1,
    'sort_order' => 2,
    'created_at' => now(),
]);

$memSvc = new MembershipService($db);
T::eq(1, count($memSvc->activeChannels('rubika')), 'کانال روبیکا فقط برای کاربران روبیکا فیلتر می‌شود');
T::eq(1, count($memSvc->activeChannels('bale')), 'کانال بله فقط برای کاربران بله فیلتر می‌شود');

SettingsService::set('force_channel_membership', '1', 'telegram');
$channelMembership['bale'] = false;
T::eq(false, $memSvc->gate($u2, 'bale', '820002')['passed'], 'عدم عضویت در کانال بله از طریق getChatMember بله تشخیص داده شد');
$channelMembership['bale'] = true;
T::eq(true, $memSvc->gate($u2, 'bale', '820002')['passed'], 'عضویت در کانال بله از طریق getChatMember بله تأیید شد');

$rubGateBefore = $memSvc->gate($u3, 'rubika', 'u0RubikaSender03');
T::eq(false, $rubGateBefore['passed'], 'قبل از تأیید کاربر در روبیکا، کانال تاییدنشده است');
$users->markChannelsVerified((int) $u3['id']);
T::eq(true, $memSvc->gate($users->find((int) $u3['id']), 'rubika', 'u0RubikaSender03')['passed'], 'با تأیید عضویت (self_confirm) در روبیکا، کانال ثبت و تأیید شد');

// پاک‌سازی کانال‌های تست
SettingsService::set('force_channel_membership', '0', 'telegram');
$db->delete('telegram_channels', 'id IN (' . (int) $rubChId . ',' . (int) $baleChId . ')');

// =========================================================================
// ۱۰) پیام همگانی با فیلتر پیام‌رسان + Polling خودکار روبیکا در Cron
// =========================================================================
$bc = new BroadcastService($db);
$rubCamp = $bc->createCampaign([
    'title' => 'اطلاعیه ویژه کاربران روبیکا',
    'message_text' => 'سلام {name}! این پیام مخصوص روبیکا است.',
    'segment' => 'all',
    'target_platform' => 'rubika',
    'segment_params' => ['target_platform' => 'rubika'],
], 1);
$outbox['rubika'] = [];
$outbox['telegram'] = [];
$bc->processQueue(20);
$rubCampAfter = $db->first('SELECT * FROM broadcast_campaigns WHERE id = :id', ['id' => (int) $rubCamp['id']]);
T::eq(2, (int) $rubCampAfter['sent_count'], 'پیام همگانی با فیلتر روبیکا دقیقاً به ۲ کاربر روبیکا (U3 و U6) ارسال شد');
T::eq(0, count($outbox['telegram']), 'در پیام همگانی روبیکا هیچ پیامی به تلگرام ارسال نشد');

// تست Polling خودکار روبیکا از طریق CronService
$rubikaQueuedUpdates = [[
    'type' => 'NewMessage',
    'chat_id' => 'b0PolledChat99',
    'new_message' => [
        'message_id' => 'msg_polled_01',
        'text' => '/start ' . $u1['referral_code'],
        'time' => (string) time(),
        'sender_type' => 'User',
        'sender_id' => 'u0PolledRubikaUser99',
        'aux_data' => ['start_id' => $u1['referral_code']],
    ],
]];
$cronRes = (new CronService($db))->run('messengers');
T::eq(true, $cronRes['ok'], 'وظیفه Cron پیام‌رسان‌ها بدون خطا اجرا شد');
$polledUser = $users->findByMessenger('rubika', 'u0PolledRubikaUser99');
T::ok($polledUser !== null && (int) $polledUser['referred_by'] === (int) $u1['id'], 'کاربر جدید روبیکا از طریق Polling خودکار Cron جذب و به معرف متصل شد');

// =========================================================================
// ۱۱) گزارش‌های تحلیلی لایه‌ها و پیام‌رسان‌ها + رندر کامل تمام صفحات پنل مدیریت و درگاه /r/{code}
// =========================================================================
$layerReport = StatsService::layerFinancialReport();
T::ok(($layerReport['direct_paid'] ?? 0) > 0, 'گزارش مالی لایه‌ها: درآمد لایه ۰ مستقیم محاسبه شد');
T::ok(($layerReport['indirect_paid'] ?? 0) > 0, 'گزارش مالی لایه‌ها: درآمد لایه‌های غیرمستقیم محاسبه شد');
T::ok(($layerReport['welcome']['paid_amount'] ?? 0) > 0, 'گزارش مالی لایه‌ها: هدیه خوش‌آمدگویی محاسبه شد');

$msgReport = StatsService::messengerPerformanceReport();
T::eq(4, count($msgReport), 'گزارش عملکرد پیام‌رسان‌ها هر ۴ پلتفرم را برمی‌گرداند');
foreach (['telegram', 'bale', 'rubika', 'eitaa'] as $p) {
    T::ok(($msgReport[$p]['registered_users'] ?? 0) >= 1, "گزارش عملکرد {$p} حداقل ۱ کاربر ثبت‌شده دارد");
}

// ایجاد مدیر کل و تست رندر HTTP واقعی همه صفحات پنل مدیریت و عمومی بدون خطا
$roleId = (int) $db->value("SELECT id FROM admin_roles WHERE slug = 'superadmin' LIMIT 1");
if ($roleId === 0) {
    $roleId = $db->insert('admin_roles', ['name' => 'مدیر کل', 'slug' => 'superadmin', 'permissions' => '["*"]', 'created_at' => now()]);
}
$adminId = $db->insert('admins', [
    'role_id' => $roleId,
    'username' => 'admin_v140',
    'name' => 'مدیر کل تست',
    'password_hash' => password_hash('StrongPass!140', PASSWORD_BCRYPT),
    'is_active' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);
SettingsService::set('installed_at', now(), 'system');
Session::start();
$_SESSION['admin_id'] = $adminId;
$_SESSION['admin_role'] = 'superadmin';
$_SESSION['admin_login_at'] = time();

$dispatchGet = static function (string $uri, array $query = []): string {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $uri . ($query ? '?' . http_build_query($query) : '');
    $_SERVER['HTTP_ACCEPT'] = 'text/html';
    $_GET = $query;
    $_POST = [];
    Request::resetCaches();
    @http_response_code(200);
    ob_start();
    $router = new Router();
    (require RB_ROOT . '/src/routes.php')($router);
    $router->dispatch();
    return (string) ob_get_clean();
};

$pagesToTest = [
    ['/admin', [], 'پیام‌رسان‌ها'],
    ['/admin/messengers', ['tab' => 'telegram'], 'مدیریت پیام‌رسان‌ها'],
    ['/admin/messengers', ['tab' => 'bale'], 'بله'],
    ['/admin/messengers', ['tab' => 'rubika'], 'روبیکا'],
    ['/admin/messengers', ['tab' => 'eitaa'], 'ایتا'],
    ['/admin/invite-content', [], 'استودیوی محتوای دعوت'],
    ['/admin/commissions', [], 'لایه ۰'],
    ['/admin/settings', [], 'حالت نمایش'],
    ['/admin/channels', [], 'کانال‌های اجباری'],
    ['/admin/broadcast', [], 'پیام‌رسان مقصد'],
    ['/admin/bot-profile', ['platform' => 'telegram'], 'پروفایل'],
    ['/admin/bot-profile', ['platform' => 'rubika'], 'روبیکا'],
    ['/admin/bot-profile', ['platform' => 'eitaa'], 'ایتا'],
    ['/admin/reports/layers', [], 'لایه ۰'],
    ['/admin/reports/messengers', [], 'روبیکا'],
    ['/admin/users', ['platform' => 'rubika'], 'سارا'],
    ['/admin/users/' . (int) $u1['id'], [], 'پیام‌رسان‌های متصل'],
    ['/admin/network/' . (int) $u1['id'], [], 'آرش'],
    ['/', [], 'رفرال‌پلاس'],
    ['/app', [], 'miniapp.js'],
    ['/r/' . $u1['referral_code'], [], 'rubika.ir/rubika_v140_bot'],
];

foreach ($pagesToTest as [$path, $q, $needle]) {
    $html = $dispatchGet($path, $q);
    $label = $path . ($q ? '?' . http_build_query($q) : '');
    T::ok(
        $html !== '' && str_contains($html, $needle) && !str_contains($html, '[خطا]'),
        "صفحه {$label} بدون خطا رندر شد و شامل «{$needle}» است"
    );
}

// تست خروجی CSV گزارش لایه‌ها و پیام‌رسان‌ها
$csvLayers = $dispatchGet('/admin/reports/layers/export');
T::ok(str_starts_with($csvLayers, "\xEF\xBB\xBF") && str_contains($csvLayers, 'لایه ۰'), 'خروجی CSV گزارش لایه‌ها با BOM استاندارد ساخته شد');
$csvMsgs = $dispatchGet('/admin/reports/messengers/export');
T::ok(str_starts_with($csvMsgs, "\xEF\xBB\xBF") && str_contains($csvMsgs, 'روبیکا'), 'خروجی CSV گزارش پیام‌رسان‌ها با BOM استاندارد ساخته شد');

// تست Webhook واقعی بله (رد راز اشتباه + پذیرش راز صحیح)
$baleSecret = (string) $registry->rawConnection('bale')['webhook_secret'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/webhook/bale/wrong_secret_token';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
Request::setRawBody(json_encode(['update_id' => 990001]));
ob_start();
$r1 = new Router();
(require RB_ROOT . '/src/routes.php')($r1);
$r1->dispatch();
$badSecretOut = json_decode((string) ob_get_clean(), true);
T::eq(false, (bool) ($badSecretOut['ok'] ?? true), 'وب‌هوک بله با توکن مخفی اشتباه رد شد');

$_SERVER['REQUEST_URI'] = '/webhook/rubika/' . $registry->rawConnection('rubika')['webhook_secret'] . '/receiveUpdate';
Request::setRawBody((string) json_encode([
    'type' => 'NewMessage',
    'chat_id' => 'b0WebhookRubChat',
    'new_message' => [
        'message_id' => 'msg_wh_rub_1',
        'text' => '/start ' . $u1['referral_code'],
        'time' => (string) time(),
        'sender_type' => 'User',
        'sender_id' => 'u0WebhookRubUser',
        'aux_data' => ['start_id' => $u1['referral_code']],
    ],
], JSON_UNESCAPED_UNICODE));
@http_response_code(200);
ob_start();
$r2 = new Router();
(require RB_ROOT . '/src/routes.php')($r2);
$r2->dispatch();
$goodRubOut = json_decode((string) ob_get_clean(), true);
T::eq(true, (bool) ($goodRubOut['ok'] ?? false), 'وب‌هوک رسمی روبیکا (/receiveUpdate) آپدیت را با موفقیت پردازش کرد');
T::ok($users->findByMessenger('rubika', 'u0WebhookRubUser') !== null, 'کاربر واردشده از وب‌هوک /receiveUpdate روبیکا ثبت شد');

// ارسال مجدد دقیقاً همان آپدیت روبیکا (Idempotency)
$balBeforeDup = (int) $users->find((int) $u1['id'])['wallet_balance'];
ob_start();
$r3 = new Router();
(require RB_ROOT . '/src/routes.php')($r3);
$r3->dispatch();
ob_end_clean();
T::eq($balBeforeDup, (int) $users->find((int) $u1['id'])['wallet_balance'], 'ارسال تکراری وب‌هوک روبیکا هیچ پاداش مضاعفی ایجاد نکرد');

// تست APIهای جدید Mini App (bootstrap + track + link-token) با سشن احراز هویت‌شده ایتا
$_SESSION['miniapp_user_id'] = (int) $u4['id'];
$_SESSION['miniapp_platform'] = 'eitaa';
$_SESSION['miniapp_auth_at'] = time();
$csrf = Csrf::token();

$bootJson = json_decode($dispatchGet('/app/api/bootstrap'), true);
T::eq(true, (bool) ($bootJson['ok'] ?? false), 'API بوت‌استرپ Mini App برای کاربر ایتا موفق بود');
T::eq('eitaa', (string) ($bootJson['platform'] ?? ''), 'پلتفرم فعال سشن در bootstrap برابر eitaa برگشت');

$dashJson = json_decode($dispatchGet('/app/api/dashboard'), true);
T::eq(true, (bool) ($dashJson['ok'] ?? false), 'API داشبورد کامل Mini App برای کاربر ایتا موفق بود');
T::eq('eitaa', (string) ($dashJson['platform']['code'] ?? ''), 'پلتفرم فعال سشن در dashboard برابر eitaa برگشت');
T::ok(isset($dashJson['invite_studio']['banners']) && count($dashJson['invite_studio']['banners']) >= 3, 'بنرهای استودیوی دعوت در dashboard Mini App برگشتند');
T::ok(isset($dashJson['analytics']['levels']) && is_array($dashJson['analytics']['levels']), 'تفکیک درآمد لایه ۰ و غیرمستقیم در dashboard Mini App برگشت');

$refsJson = json_decode($dispatchGet('/app/api/referrals'), true);
T::eq(true, (bool) ($refsJson['ok'] ?? false), 'API فهرست زیرمجموعه‌ها و لایه‌ها در Mini App موفق بود');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/app/api/link-token';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_SERVER['HTTP_X_CSRF_TOKEN'] = (string) ($bootJson['csrf'] ?? $csrf);
Request::setRawBody('{}');
ob_start();
$r4 = new Router();
(require RB_ROOT . '/src/routes.php')($r4);
$r4->dispatch();
$ltJson = json_decode((string) ob_get_clean(), true);
T::eq(true, (bool) ($ltJson['ok'] ?? false), 'API ساخت کد اتصال چندپیام‌رسانی در Mini App موفق بود');
T::ok(str_starts_with((string) ($ltJson['token'] ?? ''), 'LNK-'), 'کد LNK- معتبر به کاربر Mini App تحویل داده شد');

// تست API ارسال بنر به گفتگو برای کاربر بله از داخل Mini App
$_SESSION['miniapp_user_id'] = (int) $u2['id'];
$_SESSION['miniapp_platform'] = 'bale';
$_SESSION['miniapp_external_id'] = '820002';
$_SESSION['miniapp_auth_at'] = time();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/app/api/invite/send-banner';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();
$_POST = ['banner_id' => $defaultBannerId, 'full_caption' => 1];
Request::resetCaches();
ob_start();
$r5 = new Router();
(require RB_ROOT . '/src/routes.php')($r5);
$r5->dispatch();
$sbJson = json_decode((string) ob_get_clean(), true);
T::eq(true, (bool) ($sbJson['ok'] ?? false), 'API ارسال بنر تبلیغاتی به گفتگو از Mini App بله موفق بود');

AbstractMessengerDriver::clearFakeTransports();
TelegramService::$fakeTransport = null;
Request::resetCaches();

exit(T::summary());

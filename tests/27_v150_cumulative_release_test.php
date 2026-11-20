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
use RB\Services\CommissionSettingsService;
use RB\Services\MembershipService;
use RB\Services\SettingsService;
use RB\Services\StatsService;
use RB\Services\TelegramService;
use RB\Services\UpdateService;
use RB\Services\UserService;
use RB\Services\WalletService;
use RB\Services\WithdrawalService;
use RB\Support\Crypto;
use RB\Support\Csrf;
use RB\Support\Database;
use RB\Support\Migrator;
use RB\Support\Request;
use RB\Support\SchemaBuilder;
use RB\Support\Session;

T::suite('v1.5.0 — اعتبارسنجی کارت بانکی، ارتقای یک‌مرحله‌ای از v1.2، نمایش هوشمند پیام‌رسان‌ها، گزارش تفکیکی و کانال میان‌پیام‌رسانی');

$db = Database::instance();
$users = new UserService($db);
$wallets = new WalletService($db);
$withdrawals = new WithdrawalService($db);

// =========================================================================
// ۱) نسخه تجمیعی v1.5.0 و Migration 005 (سازگار با MySQL 8 Strict + utf8mb4)
// =========================================================================
T::ok(version_compare(trim((string) file_get_contents(RB_ROOT . '/VERSION')), '1.5.0', '>='), 'نسخه فعلی شامل قابلیت‌های نسخه 1.5.0 است');
T::ok($db->tableExists('user_channel_memberships'), 'جدول user_channel_memberships ایجاد شده است');
T::ok($db->tableExists('messenger_error_logs'), 'جدول messenger_error_logs ایجاد شده است');
T::ok($db->columnExists('telegram_channels', 'target_platforms'), 'ستون telegram_channels.target_platforms ایجاد شده است');
T::ok($db->columnExists('telegram_channels', 'cross_rule_note'), 'ستون telegram_channels.cross_rule_note ایجاد شده است');
T::ok($db->columnExists('telegram_channels', 'updated_at'), 'ستون telegram_channels.updated_at ایجاد شده است');

$mig = new Migrator($db, RB_ROOT . '/database/migrations');
T::eq([], $mig->run()['applied'], 'اجرای مجدد زنجیره Migrationهای 001..005 کاملاً Idempotent است');

$mig005 = require RB_ROOT . '/database/migrations/005_cross_messenger_channels_and_error_logs.php';
$mysqlSchema = new SchemaBuilder('mysql');
$mig005($mysqlSchema, $db);
$mysqlStmts = $mysqlSchema->statements();
$creates005 = array_values(array_filter($mysqlStmts, static fn(string $s): bool => str_starts_with($s, 'CREATE TABLE')));
$alters005 = array_values(array_filter($mysqlStmts, static fn(string $s): bool => str_starts_with($s, 'ALTER TABLE')));
T::eq(2, count($creates005), 'Migration 005 دقیقاً ۲ جدول جدید MySQL ایجاد می‌کند');
T::eq(3, count($alters005), 'Migration 005 دقیقاً ۳ دستور ALTER TABLE برای MySQL ایجاد می‌کند');
foreach ($creates005 as $cs) {
    T::ok(str_contains($cs, 'utf8mb4_unicode_ci'), 'جدول MySQL در Migration 005 دارای utf8mb4_unicode_ci است');
    T::ok(!preg_match('/\bTEXT\s+NOT\s+NULL\b/i', $cs), 'هیچ ستون TEXT NOT NULL ناسازگار با MySQL Strict وجود ندارد');
}

// =========================================================================
// ۲) شبیه‌سازی دقیق ارتقای تک‌مرحله‌ای v1.2.0 → v1.5.0 با حفظ ۱۰۰٪ داده‌ها
// =========================================================================
$tmpV12 = sys_get_temp_dir() . '/rb_v12_upgrade_' . bin2hex(random_bytes(4)) . '.sqlite';
@unlink($tmpV12);
$dbV12 = new Database(['driver' => 'sqlite', 'database' => $tmpV12]);

// اجرای فقط Migrationهای نسخه 1.2.0 (001 + 002)
$v12Dir = sys_get_temp_dir() . '/rb_v12_migs_' . bin2hex(random_bytes(4));
@mkdir($v12Dir, 0775, true);
copy(RB_ROOT . '/database/migrations/001_initial_schema.php', $v12Dir . '/001_initial_schema.php');
copy(RB_ROOT . '/database/migrations/002_telegram_idempotency.php', $v12Dir . '/002_telegram_idempotency.php');
$applied12 = (new Migrator($dbV12, $v12Dir))->run()['applied'];
T::eq(['001_initial_schema.php', '002_telegram_idempotency.php'], $applied12, 'اسکیمای واقعی نسخه 1.2.0 بازسازی شد');
T::eq(false, $dbV12->tableExists('messenger_connections'), 'در v1.2.0 جدول messenger_connections وجود ندارد');
T::eq(false, $dbV12->columnExists('users', 'entry_platform'), 'در v1.2.0 ستون users.entry_platform وجود ندارد');

// درج داده‌های واقعی کاربر، کیف پول، تراکنش، معرفی، ربات تلگرام و کانال در v1.2.0
$legacyTokEnc = Crypto::encrypt('123456789:AAFakeTokenLegacyV12ForAutomatedTests');
$dbV12->insert('telegram_settings', [
    'bot_name' => 'ربات قدیمی v1.2',
    'bot_username' => 'legacy_v12_bot',
    'bot_id' => 123456789,
    'bot_token_enc' => $legacyTokEnc,
    'connection_status' => 'connected',
    'webhook_secret' => 'legacy_sec_v12_abc',
    'webhook_url' => 'https://legacy.example.ir/webhook/telegram',
    'webhook_active' => 1,
    'updates_count' => 42,
    'messages_sent' => 118,
    'created_at' => now(),
    'updated_at' => now(),
]);
$dbV12->insert('telegram_channels', [
    'title' => 'کانال قدیمی تلگرام',
    'chat_id' => '@legacy_tg_channel',
    'invite_link' => 'https://t.me/legacy_tg_channel',
    'is_active' => 1,
    'sort_order' => 1,
    'created_at' => now(),
]);
$u12a = $dbV12->insert('users', [
    'telegram_id' => 9001001,
    'username' => 'ali_v12',
    'first_name' => 'علی',
    'last_name' => 'قدیمی',
    'phone' => '09121112233',
    'referral_code' => 'ALIV1201',
    'wallet_balance' => 85000,
    'total_earned' => 135000,
    'total_withdrawn' => 50000,
    'direct_ref_count' => 1,
    'total_ref_count' => 1,
    'status' => 'active',
    'channels_verified' => 1,
    'joined_at' => now(),
    'created_at' => now(),
]);
$u12b = $dbV12->insert('users', [
    'telegram_id' => 9001002,
    'username' => 'reza_v12',
    'first_name' => 'رضا',
    'last_name' => 'معرفی‌شده',
    'referral_code' => 'REZV1202',
    'referred_by' => $u12a,
    'wallet_balance' => 15000,
    'total_earned' => 15000,
    'status' => 'active',
    'qualified_at' => now(),
    'joined_at' => now(),
    'created_at' => now(),
]);
$dbV12->insert('referral_relations', [
    'ancestor_id' => $u12a,
    'descendant_id' => $u12b,
    'depth' => 1,
    'created_at' => now(),
]);
$dbV12->insert('wallet_transactions', [
    'tx_code' => 'TX-V12-0001',
    'user_id' => $u12a,
    'type' => 'commission_direct',
    'direction' => 'credit',
    'amount' => 85000,
    'balance_after' => 85000,
    'status' => 'completed',
    'idempotency_key' => 'v12-tx-1',
    'description' => 'پاداش معرفی مستقیم v1.2',
    'created_at' => now(),
]);
$dbV12->insert('system_settings', [
    'key' => 'app_name',
    'value' => 'باشگاه وفاداری v1.2',
    'group_name' => 'general',
    'created_at' => now(),
    'updated_at' => now(),
]);

// بررسی متدهای مستقل از Database::columnExists در Migrator (علت ریشه‌ای خطای v1.2 → v1.3)
T::eq(false, Migrator::pdoColumnExists($dbV12->pdo(), true, 'users', 'entry_platform'), 'Migrator::pdoColumnExists بدون وابستگی به Database::columnExists ستون ناموجود را تشخیص داد');
T::eq(true, Migrator::pdoColumnExists($dbV12->pdo(), true, 'users', 'wallet_balance'), 'Migrator::pdoColumnExists ستون موجود را از PDO خام تشخیص داد');

// ارتقای مستقیم یک‌مرحله‌ای 003 + 004 + 005 روی دیتابیس v1.2.0
$appliedUpgrade = (new Migrator($dbV12, RB_ROOT . '/database/migrations'))->run()['applied'];
T::eq([
    '003_two_way_referral_and_bot_profile.php',
    '004_multi_messenger_invite_studio_and_layers.php',
    '005_cross_messenger_channels_and_error_logs.php',
    '006_user_levels.php',
    '007_withdrawal_card_details.php',
    '008_pending_verification_queue.php',
    '009_security_responsible_user.php',
], $appliedUpgrade, 'ارتقای مستقیم از v1.2.0 همه Migrationهای بعدی را به ترتیب اجرا کرد');

// اجرای مجدد برای تضمین Idempotency کامل پس از ارتقا
T::eq([], (new Migrator($dbV12, RB_ROOT . '/database/migrations'))->run()['applied'], 'اجرای مجدد پس از ارتقای v1.2.0→v1.5.0 بدون خطا و Idempotent است');

// تأیید حفظ ۱۰۰٪ داده‌های قبلی پس از ارتقا
$afterA = $dbV12->first('SELECT * FROM users WHERE id = :id', ['id' => $u12a]);
$afterB = $dbV12->first('SELECT * FROM users WHERE id = :id', ['id' => $u12b]);
T::eq(85000, (int) $afterA['wallet_balance'], 'موجودی کیف پول کاربر قدیمی پس از ارتقا دست‌نخورده باقی ماند');
T::eq(135000, (int) $afterA['total_earned'], 'درآمد کل کاربر قدیمی پس از ارتقا حفظ شد');
T::eq('ALIV1201', (string) $afterA['referral_code'], 'کد دعوت کاربر قدیمی حفظ شد');
T::eq('telegram', (string) $afterA['entry_platform'], 'پیام‌رسان کاربر قدیمی به‌صورت خودکار telegram مقداردهی شد');
T::eq((int) $u12a, (int) $afterB['referred_by'], 'زنجیره معرف کاربر دوم پس از ارتقا حفظ شد');
T::eq(1, (int) $dbV12->value('SELECT COUNT(*) FROM referral_relations WHERE ancestor_id = :a AND descendant_id = :d', ['a' => $u12a, 'd' => $u12b]), 'جدول referral_relations دست‌نخورده است');
T::eq(85000, (int) $dbV12->value("SELECT amount FROM wallet_transactions WHERE tx_code = 'TX-V12-0001'"), 'تراکنش مالی قبلی دست‌نخورده باقی ماند');
T::eq('باشگاه وفاداری v1.2', (string) $dbV12->value("SELECT `value` FROM system_settings WHERE `key` = 'app_name'"), 'تنظیمات قبلی سیستم بازنویسی نشد');
T::eq('same', (string) $dbV12->value("SELECT target_platforms FROM telegram_channels WHERE chat_id = '@legacy_tg_channel'"), 'کانال قدیمی تلگرام با هدف پیش‌فرض same حفظ شد');

// اتصال تلگرام قدیمی بدون قطع شدن به‌عنوان «فعال و متصل» شناخته شد و بقیه مخفی‌اند
$regV12 = new MessengerRegistry($dbV12);
$activeV12 = $regV12->activeConnectedPlatforms();
T::eq(['telegram'], array_keys($activeV12), 'پس از ارتقا از v1.2، ربات تلگرام قبلی بدون نیاز به پیکربندی مجدد فعال و متصل است و ۳ پیام‌رسان دیگر غیرفعال‌اند');
$migratedTgEnc = (string) $dbV12->value("SELECT bot_token_enc FROM messenger_connections WHERE platform = 'telegram'");
T::eq('123456789:AAFakeTokenLegacyV12ForAutomatedTests', Crypto::decrypt($migratedTgEnc), 'توکن رمزنگاری‌شده تلگرام v1.2 به جدول چندپیام‌رسانی منتقل شد');

// بررسی ایمنی پشتیبان و Rollback در صورت خرابی بسته به‌روزرسانی
if (class_exists(\ZipArchive::class)) {
    $badTarget = sys_get_temp_dir() . '/rb_rollback_test_' . bin2hex(random_bytes(4));
    @mkdir($badTarget . '/src', 0775, true);
    @mkdir($badTarget . '/storage/uploads', 0775, true);
    file_put_contents($badTarget . '/VERSION', "1.2.0\n");
    file_put_contents($badTarget . '/.env', "APP_KEY=preserve_secret_v12\n");
    file_put_contents($badTarget . '/storage/uploads/keep.txt', 'user_upload_data');
    file_put_contents($badTarget . '/src/marker.php', "<?php return 'v1.2.0';\n");
    $brokenZip = $badTarget . '/broken.zip';
    $za = new \ZipArchive();
    $za->open($brokenZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $za->addFromString('VERSION', "1.5.0\n");
    $za->addFromString('CHECKSUMS.sha256', str_repeat('f', 64) . "  VERSION\n");
    $za->close();
    $updRes = (new UpdateService($dbV12))->applyArchive($brokenZip, 'upload', null, ['target_root' => $badTarget]);
    T::eq(false, $updRes['ok'], 'بسته معیوب به‌صورت ایمن متوقف شد');
    T::eq("1.2.0\n", (string) file_get_contents($badTarget . '/VERSION'), 'نسخه قبلی پس از خطا دست‌نخورده باقی ماند');
    T::eq("APP_KEY=preserve_secret_v12\n", (string) file_get_contents($badTarget . '/.env'), 'فایل .env در حین خطای آپدیت کاملاً محفوظ ماند');
    T::eq('user_upload_data', (string) file_get_contents($badTarget . '/storage/uploads/keep.txt'), 'پوشه storage در حین خطای آپدیت کاملاً محفوظ ماند');
    T::eq(85000, (int) $dbV12->value('SELECT wallet_balance FROM users WHERE id = :id', ['id' => $u12a]), 'کیف پول کاربران در حین خطای آپدیت هیچ آسیبی ندید');
}

@unlink($tmpV12);
@unlink($v12Dir . '/001_initial_schema.php');
@unlink($v12Dir . '/002_telegram_idempotency.php');
@rmdir($v12Dir);

// =========================================================================
// ۳) شماره کارت بدون اعتبارسنجی بانکی و با حفظ ورودی برای بررسی مدیر
// =========================================================================
foreach (['123', '6037997512345671', '1111111111111111', 'IR062960000000100324200001', '۶۰۳۷-۹۹۷۵-۱۲۳۴-۵۶۷۰', '6037-ABCD-1234'] as $input) {
    $result = WithdrawalService::validateCard($input);
    T::eq(true, $result['valid'], 'ورودی غیرخالی بدون بررسی بانکی پذیرفته شد');
    T::eq($input, $result['normalized'], 'ورودی همان‌طور که ارسال شد حفظ می‌شود');
}
T::eq(false, WithdrawalService::validateCard('')['valid'], 'ورودی خالی رد می‌شود');
SettingsService::set('withdrawal_enabled', '1', 'withdrawal');
$cardUser = $users->registerFromTelegram(['id' => 7700101, 'first_name' => 'مریم', 'last_name' => 'حسینی', 'username' => 'maryam_card'])['user'];
$wallets->credit((int) $cardUser['id'], 'adjustment', 100000, ['idempotency_key' => 'card-test-credit-1', 'description' => 'شارژ تست برداشت']);
$rawCard = "\u{200E}۶۰۳۷ - ۹۹۷۵ - ۱۲۳۴ - ۵۶۷۱\u{200F}";
$goodWd = $withdrawals->create((int) $cardUser['id'], 60000, $rawCard, 'مریم حسینی');
T::eq('reviewing', $goodWd['status'], 'درخواست با ورودی بدون اعتبارسنجی در انتظار بررسی مدیر ثبت شد');
T::eq('5671', (string) $goodWd['card_last4'], 'چهار رقم آخر برای نمایش به‌دست آمد');
T::eq($rawCard, WithdrawalService::decryptedCard($goodWd), 'ورودی اصلی رمزگذاری و بدون تغییر نگهداری شد');

// =========================================================================
// ۴) نمایش هوشمند تنظیمات و تفکیک آمار فقط برای پیام‌رسان‌های فعال و متصل
// =========================================================================
$tgTok = '111111111:AAFakeTokenTelegramV150AutomatedTest1';
$baleTok = '222222222:BBFakeTokenBaleBotV150AutomatedTest12';
$rubTok = 'CCFakeTokenRubikaBotV150AutomatedTests_987654';

$outbox = ['telegram' => [], 'bale' => [], 'rubika' => []];
$tgMemberMap = []; // external_user_id => bool
$baleMemberMap = [];

TelegramService::$fakeTransport = static function (string $method, array $params) use (&$outbox, &$tgMemberMap): array {
    $outbox['telegram'][] = ['method' => $method, 'params' => $params];
    if ($method === 'getChatMember') {
        $uid = (string) ($params['user_id'] ?? '');
        $isMem = $tgMemberMap[$uid] ?? false;
        return ['ok' => true, 'result' => ['status' => $isMem ? 'member' : 'left']];
    }
    return match ($method) {
        'getMe' => ['ok' => true, 'result' => ['id' => 111111111, 'is_bot' => true, 'first_name' => 'ربات تلگرام ۱.۵', 'username' => 'tg_v150_bot']],
        'setWebhook', 'deleteWebhook', 'setMyCommands', 'setChatMenuButton', 'answerCallbackQuery' => ['ok' => true, 'result' => true],
        'getWebhookInfo' => ['ok' => true, 'result' => ['url' => 'https://club.example.ir/webhook/telegram', 'pending_update_count' => 0]],
        'getChat' => ['ok' => true, 'result' => ['id' => -100555, 'title' => 'کانال رسمی تلگرام', 'type' => 'channel']],
        default => ['ok' => true, 'result' => ['message_id' => count($outbox['telegram'])]],
    };
};
AbstractMessengerDriver::setFakeTransport('telegram', static fn(string $m, array $p): array => (TelegramService::$fakeTransport)($m, $p));

AbstractMessengerDriver::setFakeTransport('bale', static function (string $method, array $params) use (&$outbox, &$baleMemberMap): array {
    $outbox['bale'][] = ['method' => $method, 'params' => $params];
    if ($method === 'getChatMember') {
        $uid = (string) ($params['user_id'] ?? '');
        $isMem = $baleMemberMap[$uid] ?? false;
        return ['ok' => true, 'result' => ['status' => $isMem ? 'member' : 'left']];
    }
    return match ($method) {
        'getMe' => ['ok' => true, 'result' => ['id' => 222222222, 'is_bot' => true, 'first_name' => 'بازوی بله ۱.۵', 'username' => 'bale_v150_bot']],
        'setWebhook', 'deleteWebhook', 'answerCallbackQuery' => ['ok' => true, 'result' => true],
        'getChat' => ['ok' => true, 'result' => ['id' => 600555, 'title' => 'کانال رسمی بله', 'type' => 'channel']],
        default => ['ok' => true, 'result' => ['message_id' => count($outbox['bale'])]],
    };
});

AbstractMessengerDriver::setFakeTransport('rubika', static function (string $method, array $params) use (&$outbox): array {
    $outbox['rubika'][] = ['method' => $method, 'params' => $params];
    return match ($method) {
        'getMe' => ['ok' => true, 'status' => 'OK', 'data' => ['bot' => [
            'bot_id' => 'b0RubikaV150',
            'bot_title' => 'بات روبیکا ۱.۵',
            'username' => 'rubika_v150_bot',
            'share_url' => 'https://rubika.ir/rubika_v150_bot',
        ]]],
        'updateBotEndpoints' => ['ok' => true, 'status' => 'OK', 'data' => ['status' => 'Done']],
        'sendMessage' => ['ok' => true, 'status' => 'OK', 'data' => ['message_id' => 'rm_' . count($outbox['rubika'])]],
        default => ['ok' => true, 'status' => 'OK', 'data' => []],
    };
});

// ایجاد ادمین و ورود به نشست مدیریتی برای رندر واقعی صفحات پنل
$adminAuth = new AdminAuth($db);
$roleId = (int) $db->value("SELECT id FROM admin_roles WHERE slug = 'superadmin'");
if ($roleId <= 0) {
    $roleId = (int) $db->insert('admin_roles', ['name' => 'مدیر کل', 'slug' => 'superadmin', 'permissions' => '["*"]', 'created_at' => now()]);
}
$adminId = (int) $db->insert('admins', [
    'username' => 'admin_v150',
    'password_hash' => password_hash('StrongPass#V150!', PASSWORD_BCRYPT),
    'name' => 'مدیر ارشد',
    'role_id' => $roleId,
    'is_active' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);
SettingsService::set('installed_at', now(), 'system');
Session::start();
$_SESSION['admin_id'] = $adminId;
$_SESSION['admin_role'] = 'superadmin';
$_SESSION['admin_login_at'] = time();

$renderAdminGet = static function (string $path, array $query = []): string {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $path . ($query ? '?' . http_build_query($query) : '');
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['HTTP_ACCEPT'] = 'text/html';
    unset($_SERVER['HTTP_X_REQUESTED_WITH']);
    $_GET = $query;
    $_POST = [];
    Request::resetCaches();
    @http_response_code(200);
    $router = new Router();
    (require RB_ROOT . '/src/routes.php')($router);
    ob_start();
    $router->dispatch();
    return (string) ob_get_clean();
};

$dispatchAdminPost = static function (string $path, array $post): array {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_GET = [];
    $post['_csrf'] = Csrf::token();
    $_POST = $post;
    Request::resetCaches();
    @http_response_code(200);
    $router = new Router();
    (require RB_ROOT . '/src/routes.php')($router);
    ob_start();
    $router->dispatch();
    $raw = (string) ob_get_clean();
    return json_decode($raw, true) ?: ['_raw' => $raw];
};

// حالت الف: فقط تلگرام فعال و متصل؛ بله، روبیکا و ایتا غیرفعال و متصل‌نشده
$registry = new MessengerRegistry($db);
$tgTest = $registry->saveCredentials('telegram', ['is_enabled' => 1, 'bot_token' => $tgTok], $adminId);
T::eq(true, $tgTest['ok'], 'تلگرام فعال و با موفقیت متصل شد');

// مطمئن شویم بله حتی اگر فقط enabled شود ولی بدون توکن/اتصال موفق باشد، «فعال و متصل» محسوب نمی‌شود
$db->update('messenger_connections', ['is_enabled' => 1, 'bot_token_enc' => null, 'bot_username' => null], "platform = 'bale'");
T::eq(false, $registry->isActiveAndConnected('bale'), 'پیام‌رسان فعال‌شده اما بدون اتصال موفق به‌عنوان «فعال و متصل» شناخته نمی‌شود');
$db->update('messenger_connections', ['is_enabled' => 0], "platform = 'bale'");
T::eq(false, $registry->isActiveAndConnected('rubika'), 'روبیکا غیرفعال است');
T::eq(false, $registry->isActiveAndConnected('eitaa'), 'ایتا غیرفعال است');
T::eq(['telegram'], array_keys($registry->activeConnectedPlatforms()), 'فقط تلگرام در لیست پیام‌رسان‌های فعال و متصل قرار دارد');

$segOnlyTg = StatsService::segmentedMessengerSummary($db);
T::eq(['telegram'], array_keys($segOnlyTg['active']), 'گزارش تفکیکی فقط تلگرام را برمی‌گرداند و برای پیام‌رسان‌های غیرفعال کارت صفر نمی‌سازد');
T::eq(1, $segOnlyTg['totals']['total_users'], 'مجموع کل کاربران سیستم دقیق است');

// بررسی صفحات پنل در حالت «فقط تلگرام فعال»: عدم نشت روبیکا/ایتا/بله در تنظیمات، فیلترها و گزارش‌ها
$strictPages = [
    '/admin',
    '/admin/settings',
    '/admin/bot-profile',
    '/admin/channels',
    '/admin/users',
    '/admin/network',
    '/admin/broadcast',
    '/admin/reports/messengers',
    '/admin/reports/users',
    '/admin/reports/growth',
    '/admin/reports/referrals',
    '/admin/reports/commissions',
    '/admin/reports/channels',
    '/admin/reports/messages',
    '/admin/reports/errors',
    '/admin/reports/transactions',
    '/admin/reports/withdrawals',
];
foreach ($strictPages as $sp) {
    $html = $renderAdminGet($sp);
    T::ok($html !== '' && !str_contains($html, '[خطا]'), "صفحه {$sp} در حالت تک‌پیام‌رسانی بدون خطا رندر شد");
    T::ok(!str_contains($html, 'روبیکا') && !str_contains($html, 'ایتا'), "صفحه {$sp} هیچ تنظیم یا آمار صفری برای روبیکا/ایتا (غیرفعال) نمایش نمی‌دهد");
    T::ok(!str_contains($html, 'کاربران بله') && !str_contains($html, 'فقط بله'), "صفحه {$sp} هیچ کارت یا فیلتری برای بله (غیرفعال) نمایش نمی‌دهد");
}

$dashTgHtml = $renderAdminGet('/admin');
T::ok(str_contains($dashTgHtml, '✈️') && str_contains($dashTgHtml, 'کاربران تلگرام'), 'داشبورد آیکون و نام فارسی «✈️ کاربران تلگرام» را نمایش می‌دهد');
T::ok(str_contains($dashTgHtml, 'کل کاربران سیستم'), 'داشبورد مجموع کل کاربران سیستم را حفظ کرده است');

// صفحه /admin/messengers همچنان هر ۴ پیام‌رسان را برای راه‌اندازی نمایش می‌دهد ولی آمار ۰ برای غیرفعال نشان نمی‌دهد
$msgSetupRubHtml = $renderAdminGet('/admin/messengers', ['tab' => 'rubika']);
T::ok(str_contains($msgSetupRubHtml, 'روبیکا') && str_contains($msgSetupRubHtml, 'ایتا'), 'صفحه /admin/messengers امکان راه‌اندازی هر ۴ پیام‌رسان را حفظ کرده است');
T::ok(!str_contains($msgSetupRubHtml, '۰ کاربر'), 'در صفحه /admin/messengers شمارنده صفر برای پیام‌رسان غیرفعال نمایش داده نمی‌شود');

// ارسال پیام همگانی به پیام‌رسان غیرفعال مسدود می‌شود
$forgedBc = $dispatchAdminPost('/admin/broadcast/send', [
    'title' => 'تست غیرمجاز',
    'message_text' => 'سلام',
    'segment' => 'all',
    'target_platform' => 'rubika',
]);
T::eq(false, (bool) ($forgedBc['ok'] ?? true), 'ارسال همگانی به پیام‌رسان غیرفعال/متصل‌نشده مسدود شد');

// حالت ب: فعال‌سازی و اتصال موفق روبیکا و بله ← ظاهر شدن خودکار تنظیمات و آمار اختصاصی آن‌ها
T::eq(true, $registry->saveCredentials('rubika', ['is_enabled' => 1, 'bot_token' => $rubTok], $adminId)['ok'], 'بات روبیکا فعال و با موفقیت متصل شد');
T::eq(true, $registry->saveCredentials('bale', ['is_enabled' => 1, 'bot_token' => $baleTok], $adminId)['ok'], 'بازوی بله فعال و با موفقیت متصل شد');

$dashMultiHtml = $renderAdminGet('/admin');
T::ok(
    str_contains($dashMultiHtml, '✈️') && str_contains($dashMultiHtml, 'کاربران تلگرام')
    && str_contains($dashMultiHtml, '🟢') && str_contains($dashMultiHtml, 'کاربران بله')
    && str_contains($dashMultiHtml, '🟣') && str_contains($dashMultiHtml, 'کاربران روبیکا')
    && !str_contains($dashMultiHtml, 'کاربران ایتا'),
    'پس از اتصال بله و روبیکا، کارت‌های اختصاصی تلگرام، بله و روبیکا خودکار در داشبورد ظاهر شدند و ایتا (غیرفعال) همچنان مخفی است'
);
$settingsMultiHtml = $renderAdminGet('/admin/settings');
T::ok(str_contains($settingsMultiHtml, 'روبیکا') && str_contains($settingsMultiHtml, 'بله') && !str_contains($settingsMultiHtml, 'ایتا'), 'تنظیمات رابط کاربری بله و روبیکا خودکار نمایان شد و ایتا مخفی ماند');
$channelsMultiHtml = $renderAdminGet('/admin/channels');
T::ok(str_contains($channelsMultiHtml, 'کانال‌های تلگرام') || (str_contains($channelsMultiHtml, 'تلگرام') && str_contains($channelsMultiHtml, 'بله') && str_contains($channelsMultiHtml, 'روبیکا') && !str_contains($channelsMultiHtml, 'ایتا')), 'صفحه کانال‌های اجباری بخش مستقل برای هر پیام‌رسان متصل ساخت و ایتا را مخفی نگه داشت');

// =========================================================================
// ۵) عضویت اجباری کانال شرطی و میان‌پیام‌رسانی + ضد دور زدن + آزادسازی خودکار پاداش
// =========================================================================
T::eq(true, MessengerCapabilities::canVerifyCrossMessengerChannel('telegram'), 'تلگرام از بررسی رسمی کانال میان‌پیام‌رسانی پشتیبانی می‌کند');
T::eq(true, MessengerCapabilities::canVerifyCrossMessengerChannel('bale'), 'بله از بررسی رسمی کانال میان‌پیام‌رسانی پشتیبانی می‌کند');
T::eq(false, MessengerCapabilities::canVerifyCrossMessengerChannel('rubika'), 'روبیکا به دلیل نبود getChatMember رسمی از شرط میان‌پیام‌رسانی پشتیبانی نمی‌کند');
T::eq(false, MessengerCapabilities::canVerifyCrossMessengerChannel('eitaa'), 'ایتا به دلیل نبود getChatMember رسمی از شرط میان‌پیام‌رسانی پشتیبانی نمی‌کند');
T::eq('same', MembershipService::encodeTargetPlatforms('rubika', ['rubika', 'telegram', 'bale']), 'کانال روبیکا هرگز اجازه ثبت هدف میان‌پیام‌رسانی جعلی را نمی‌دهد');

// فعال‌سازی قانون پورسانت مستقیم + خوش‌آمدگویی + لایه ۱ غیرمستقیم و عضویت اجباری کانال
\RB\Services\CommissionSettings::saveVersion([
    'direct_enabled' => 1,
    'direct_reward' => 20000,
    'invitee_enabled' => 1,
    'invitee_reward' => 10000,
    'multi_level_enabled' => 1,
    'max_levels' => 2,
    'percent_base' => 'direct_reward',
    'qualify_conditions' => ['channels'],
    'levels' => [
        ['level' => 1, 'calc_type' => 'percent', 'percent' => '25', 'amount' => 0, 'max_amount' => 0, 'enabled' => true],
        ['level' => 2, 'calc_type' => 'fixed',   'percent' => '0',  'amount' => 2000, 'max_amount' => 0, 'enabled' => true],
    ],
]);
SettingsService::set('force_channel_membership', '1', 'telegram');
SettingsService::set('contact_required', '0', 'telegram');

// ثبت کانال اجباری تلگرام که برای کاربران ورودی تلگرام و روبیکا الزامی است
$crossChId = (int) $db->insert('telegram_channels', [
    'platform' => 'telegram',
    'title' => 'کانال رسمی تلگرام باشگاه',
    'chat_id' => '@club_tg_official',
    'invite_link' => 'https://t.me/club_tg_official',
    'check_mode' => 'api',
    'target_platforms' => MembershipService::encodeTargetPlatforms('telegram', ['telegram', 'rubika']),
    'cross_rule_note' => 'کاربران روبیکا برای دریافت هدیه باید در کانال تلگرام نیز عضو شوند.',
    'is_active' => 1,
    'sort_order' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);

// تلاش برای ثبت کانال روبیکا با هدف میان‌پیام‌رسانی از طریق فرم مدیریت باید رد شود
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$badRubCh = $dispatchAdminPost('/admin/channels/save', [
    'platform' => 'rubika',
    'title' => 'کانال روبیکا',
    'chat_id' => '@club_rub_ch',
    'target_platforms' => ['rubika', 'telegram'],
    'is_active' => '1',
]);
T::eq(false, (bool) ($badRubCh['ok'] ?? true), 'پنل مدیریت ثبت شرط میان‌پیام‌رسانی روی کانال روبیکا را با خطای شفاف رد کرد');
T::eq(0, (int) $db->value("SELECT COUNT(*) FROM telegram_channels WHERE chat_id = '@club_rub_ch'"), 'کانال روبیکا با شرط میان‌پیام‌رسانی غیرمجاز ذخیره نشد');

$memSvc = new MembershipService($db);
T::eq(1, count($memSvc->activeChannels('rubika')), 'کانال تلگرام شرطی برای کاربر ورودی روبیکا فعال است');
T::eq(0, count($memSvc->activeChannels('bale')), 'کانال تلگرام شرطی برای کاربر ورودی بله (خارج از لیست هدف) الزامی نیست');
T::eq(true, $memSvc->hasEnforceableChannelsFor('rubika'), 'شرط channels برای کاربر روبیکا در effectiveConditions فعال است');

// ساخت زنجیره معرف: U_root (لایه ۱ غیرمستقیم) ← U_inviter (لایه ۰ مستقیم) ← U_rub (ورودی روبیکا)
$tgMemberMap['88001'] = true;
$tgMemberMap['88002'] = true;
$uRoot = $users->registerFromTelegram(['id' => 88001, 'first_name' => 'سعید', 'username' => 'saeed_root'])['user'];
$uInviter = $users->registerFromTelegram(['id' => 88002, 'first_name' => 'نگار', 'username' => 'negar_inv'], $uRoot['referral_code'])['user'];
$inviterBalBefore = (int) $users->find((int) $uInviter['id'])['wallet_balance'];
$rootBalBefore = (int) $users->find((int) $uRoot['id'])['wallet_balance'];

// کاربر جدید از طریق بات روبیکا با لینک دعوت نگار وارد می‌شود
$rubDriver = $registry->driver('rubika');
$rubBot = new BotHandler($db, $rubDriver);
$rubBot->handle([
    'type' => 'NewMessage',
    'chat_id' => 'b0_rub_user_chat_1',
    'new_message' => [
        'message_id' => 'm_rub_1',
        'text' => '/start',
        'time' => (string) time(),
        'sender_type' => 'User',
        'sender_id' => 'u0_rub_entrant_1',
        'aux_data' => ['start_id' => $uInviter['referral_code'], 'button_id' => null],
    ],
]);

$uRub = $users->findByMessenger('rubika', 'u0_rub_entrant_1');
T::ok($uRub !== null, 'کاربر ورودی روبیکا ثبت شد');
T::eq((int) $uInviter['id'], (int) $uRub['referred_by'], 'معرف مستقیم کاربر روبیکا ثبت شد');
T::ok(empty($uRub['qualified_at']), 'تا پیش از عضویت واقعی در کانال تلگرام، کاربر روبیکا واجد شرایط نشده است');
T::eq(0, (int) $uRub['wallet_balance'], 'هدیه خوش‌آمدگویی کاربر روبیکا تا پیش از عضویت در کانال تلگرام پرداخت نشد');
T::eq($inviterBalBefore, (int) $users->find((int) $uInviter['id'])['wallet_balance'], 'پاداش معرف تا پیش از عضویت واقعی کاربر روبیکا در کانال تلگرام قفل است');

// بررسی گیت: نیازمند اتصال حساب تلگرام است
$gate1 = $memSvc->gate($uRub, 'rubika', 'u0_rub_entrant_1');
T::eq(false, $gate1['passed'], 'گیت عضویت کاربر روبیکا را متوقف کرد');
T::eq(['telegram'], $gate1['needs_link_platforms'], 'گیت به‌درستی نیاز به اتصال حساب تلگرام را گزارش کرد');

// تست ضد دور زدن: self_confirm هرگز نمی‌تواند کانال میان‌پیام‌رسانی تلگرام را دور بزند
$bypassAttempt = $memSvc->confirmSelfDeclaredChannels($uRub, 'rubika', 'u0_rub_entrant_1');
T::eq(false, $bypassAttempt['gate']['passed'], 'تأیید خوداظهاری کاربر قادر به دور زدن کانال اجباری تلگرام نیست');
T::eq(0, (int) $users->find((int) $uRub['id'])['wallet_balance'], 'پس از تلاش دور زدن، همچنان هیچ پاداشی آزاد نشد');

// گام ۱ کاربر روبیکا: دریافت کد اتصال LNK- و ارسال آن به ربات تلگرام (در حالی که هنوز عضو کانال نشده)
$prompt = $memSvc->buildCrossPlatformLinkPrompt((int) $uRub['id'], 'telegram');
T::ok(str_starts_with((string) $prompt['code'], 'LNK-'), 'کد یک‌بارمصرف اتصال حساب تلگرام ساخته شد');
T::ok(str_starts_with((string) $prompt['deep_link'], 'https://t.me/tg_v150_bot?start=LNK-'), 'لینک مستقیم یک‌کلیکی اتصال به ربات تلگرام ساخته شد');

$tgMemberMap['88099'] = false; // هنوز عضو کانال تلگرام نیست!
$tgDriver = $registry->driver('telegram');
$tgBot = new BotHandler($db, $tgDriver);
$tgBot->handle([
    'update_id' => 991001,
    'message' => [
        'message_id' => 10,
        'from' => ['id' => 88099, 'first_name' => 'کیمیا', 'username' => 'kimia_tg'],
        'chat' => ['id' => 88099, 'type' => 'private'],
        'text' => '/start ' . $prompt['code'],
    ],
]);

$uRubLinked = $users->find((int) $uRub['id']);
T::eq(false, $memSvc->gate($uRubLinked, 'rubika', 'u0_rub_entrant_1')['passed'], 'با وجود اتصال حساب تلگرام، چون هنوز در کانال عضو نشده گیت همچنان بسته است');
T::eq(0, (int) $uRubLinked['wallet_balance'], 'صرف اتصال حساب بدون عضویت واقعی در کانال تلگرام پاداشی آزاد نکرد');

// گام ۲: کاربر واقعاً در کانال تلگرام عضو می‌شود و دکمه «بررسی عضویت» را در روبیکا می‌زند
$tgMemberMap['88099'] = true;
$rubBot->handle([
    'type' => 'NewMessage',
    'chat_id' => 'b0_rub_user_chat_1',
    'new_message' => [
        'message_id' => 'm_rub_2',
        'text' => 'بررسی',
        'time' => (string) time(),
        'sender_type' => 'User',
        'sender_id' => 'u0_rub_entrant_1',
        'aux_data' => ['button_id' => 'gate:check'],
    ],
]);

$uRubDone = $users->find((int) $uRub['id']);
T::ok(!empty($uRubDone['qualified_at']), 'پس از تأیید واقعی getChatMember تلگرام، کاربر روبیکا واجد شرایط شد');
T::eq(10000, (int) $uRubDone['wallet_balance'], 'هدیه خوش‌آمدگویی ۱۰٬۰۰۰ تومان به کاربر روبیکا واریز شد');
T::eq($inviterBalBefore + 20000, (int) $users->find((int) $uInviter['id'])['wallet_balance'], 'پاداش مستقیم لایه ۰ (۲۰٬۰۰۰ تومان) به معرف واریز شد');
T::eq($rootBalBefore + 5000, (int) $users->find((int) $uRoot['id'])['wallet_balance'], 'پورسانت غیرمستقیم لایه ۱ (۲۵٪ = ۵٬۰۰۰ تومان) به بالاسری واریز شد');

$memRow = $db->first('SELECT * FROM user_channel_memberships WHERE user_id = :u AND channel_id = :c', ['u' => (int) $uRub['id'], 'c' => $crossChId]);
T::eq('api_cross_linked', (string) ($memRow['verify_method'] ?? ''), 'روش تأیید عضویت میان‌پیام‌رسانی به‌صورت api_cross_linked ثبت شد');
T::eq('rubika', (string) ($memRow['entry_platform'] ?? ''), 'پیام‌رسان ورودی rubika در جدول عضویت کانال ثبت شد');
T::eq('telegram', (string) ($memRow['channel_platform'] ?? ''), 'پیام‌رسان میزبان telegram در جدول عضویت کانال ثبت شد');

// سناریوی دوم میان‌پیام‌رسانی: کاربر روبیکا از قبل عضو کانال تلگرام است ← به محض /start LNK- پاداش آنی آزاد شود
$rubBot->handle([
    'type' => 'NewMessage',
    'chat_id' => 'b0_rub_user_chat_2',
    'new_message' => [
        'message_id' => 'm_rub_3',
        'text' => '/start',
        'time' => (string) time(),
        'sender_type' => 'User',
        'sender_id' => 'u0_rub_entrant_2',
        'aux_data' => ['start_id' => $uInviter['referral_code'], 'button_id' => null],
    ],
]);
$uRub2 = $users->findByMessenger('rubika', 'u0_rub_entrant_2');
$prompt2 = $memSvc->buildCrossPlatformLinkPrompt((int) $uRub2['id'], 'telegram');
$tgMemberMap['88100'] = true; // از قبل عضو کانال تلگرام است
$tgBot->handle([
    'update_id' => 991002,
    'message' => [
        'message_id' => 11,
        'from' => ['id' => 88100, 'first_name' => 'پویا', 'username' => 'pouya_tg'],
        'chat' => ['id' => 88100, 'type' => 'private'],
        'text' => '/start ' . $prompt2['code'],
    ],
]);
$uRub2After = $users->find((int) $uRub2['id']);
T::ok(!empty($uRub2After['qualified_at']), 'به محض اتصال حساب تلگرام عضو کانال، تایید عضویت و پاداش کاربر روبیکا به‌صورت خودکار انجام شد');
T::eq(10000, (int) $uRub2After['wallet_balance'], 'هدیه خوش‌آمدگویی کاربر دوم روبیکا بلافاصله واریز شد');

// تطبیق ۱۰۰٪ دفتر کل مالی تمام کاربران
foreach ([(int) $cardUser['id'], (int) $uRoot['id'], (int) $uInviter['id'], (int) $uRub['id'], (int) $uRub2['id']] as $chkUid) {
    T::eq(true, $wallets->reconcile($chkUid)['match'], "تطبیق دفتر مالی کاربر #{$chkUid} دقیق و بدون مغایرت است");
}

// =========================================================================
// ۶) ثبت ایمن خطاهای اتصال پیام‌رسان‌ها (حذف توکن) و رندر کامل همه ۱۴ گزارش + CSV
// =========================================================================
MessengerRegistry::logError(
    'bale',
    'send_message',
    "Request failed for https://tapi.bale.ai/bot{$baleTok}/sendMessage: HTTP 502 Bad Gateway",
    'sendMessage',
    502,
    ['chat_id' => '12345'],
    $db
);
$errLogs = $registry->recentErrors(['bale']);
T::eq(1, count($errLogs), 'خطای ارتباطی بله در جدول messenger_error_logs ثبت شد');
T::ok(!str_contains((string) $errLogs[0]['message'], 'BBFakeTokenBaleBot'), 'توکن ربات به‌طور کامل از متن لاگ خطا پاک‌سازی (Redact) شد');

foreach (array_keys(\RB\Controllers\Admin\ReportController::TYPES) as $repType) {
    $repHtml = $renderAdminGet('/admin/reports/' . $repType);
    T::ok($repHtml !== '' && !str_contains($repHtml, '[خطا]'), "گزارش /admin/reports/{$repType} بدون خطا رندر شد");
    T::ok(!str_contains($repHtml, 'کاربران ایتا'), "گزارش /admin/reports/{$repType} پیام‌رسان غیرفعال ایتا را نمایش نمی‌دهد");
}
$chRepHtml = $renderAdminGet('/admin/reports/channels');
T::ok(str_contains($chRepHtml, 'کانال رسمی تلگرام باشگاه') && str_contains($chRepHtml, 'میان‌پیام‌رسانی'), 'گزارش عضویت کانال‌ها آمار تأیید میان‌پیام‌رسانی را نمایش می‌دهد');

$errRepHtml = $renderAdminGet('/admin/reports/errors', ['platform' => 'bale']);
T::ok(str_contains($errRepHtml, '🟢 بله') && str_contains($errRepHtml, 'sendMessage'), 'گزارش خطاهای اتصال آیکون و نام فارسی بله و متد خطا را نمایش می‌دهد');

$csvOut = $renderAdminGet('/admin/reports/messengers/export');
T::ok(str_starts_with($csvOut, "\xEF\xBB\xBF") && str_contains($csvOut, '✈️ تلگرام') && !str_contains($csvOut, 'ایتا'), 'خروجی CSV گزارش پیام‌رسان‌ها با BOM فارسی و فقط شامل پیام‌رسان‌های فعال تولید شد');

$clrRes = $dispatchAdminPost('/admin/reports/errors/clear', ['platform' => 'bale']);
T::eq(true, (bool) ($clrRes['ok'] ?? false), 'پاک‌سازی لاگ خطاهای پیام‌رسان از پنل مدیریت موفق بود');
T::eq(0, count($registry->recentErrors(['bale'])), 'لاگ خطای بله پاک‌سازی شد');

TelegramService::$fakeTransport = null;
AbstractMessengerDriver::clearFakeTransports();

T::summary();

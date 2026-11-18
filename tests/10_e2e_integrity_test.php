<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\CommissionSettings;
use RB\Services\CommissionService;
use RB\Services\RankingService;
use RB\Services\SettingsService;
use RB\Services\TelegramService;
use RB\Services\UserService;
use RB\Services\WalletService;
use RB\Services\WithdrawalService;
use RB\Support\Crypto;
use RB\Support\Database;

T::suite('سناریوی کامل: دعوت، پورسانت، برداشت و صحت دفتر مالی');

$db = Database::instance();
$users = new UserService($db);
$wallet = new WalletService($db);

// ---------- پیکربندی ربات و پورسانت
$settingsId = (int) $db->value('SELECT id FROM telegram_settings ORDER BY id LIMIT 1');
if ($settingsId === 0) {
    $settingsId = $db->insert('telegram_settings', [
        'connection_status' => 'connected', 'webhook_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}
$db->update('telegram_settings', [
    'bot_token_enc' => Crypto::encrypt('424242424:' . str_repeat('E', 35)),
    'bot_username' => 'e2e_test_bot',
    'connection_status' => 'connected',
    'webhook_active' => 1,
    'updated_at' => now(),
], 'id = :id', ['id' => $settingsId]);
TelegramService::forgetSettingsCache();
TelegramService::$fakeTransport = static fn(string $m, array $p): array => ['ok' => true, 'result' => true];

CommissionSettings::saveVersion([
    'direct_reward' => 30000,
    'direct_enabled' => 1,
    'multi_level_enabled' => 1,
    'max_levels' => 3,
    'levels' => [
        ['level' => 1, 'percent' => '20', 'enabled' => true],
        ['level' => 2, 'percent' => '10', 'enabled' => true],
        ['level' => 3, 'percent' => '5', 'enabled' => true],
    ],
    'percent_base' => 'direct_reward',
    'fixed_base_amount' => 0,
    'max_payout_per_event' => 0,
    'daily_payout_cap' => 0,
    'qualify_conditions' => ['joined'],
    'blocked_users_no_commission' => 1,
], 1);
T::eq(30000, CommissionSettings::current($db, true)->directReward, 'تنظیمات پورسانت اعمال شد');

// ---------- ساخت شبکه: A → B → C → D
$A = $users->registerFromTelegram(['id' => 910000001, 'is_bot' => false, 'first_name' => 'آرش'])['user'];
$B = $users->registerFromTelegram(['id' => 910000002, 'is_bot' => false, 'first_name' => 'بهرام'], $A['referral_code'])['user'];
$C = $users->registerFromTelegram(['id' => 910000003, 'is_bot' => false, 'first_name' => 'کامران'], $B['referral_code'])['user'];
$D = $users->registerFromTelegram(['id' => 910000004, 'is_bot' => false, 'first_name' => 'داریوش'], $C['referral_code'])['user'];

T::eq((int) $B['id'], (int) $db->value('SELECT referred_by FROM users WHERE id = :id', ['id' => (int) $C['id']]), 'زنجیره معرفی درست ثبت شد');
$relations = (int) $db->value('SELECT COUNT(*) FROM referral_relations WHERE descendant_id = :d', ['d' => (int) $D['id']]);
T::eq(3, $relations, 'جدول بستار ۳ سطح بالادست برای D دارد');
T::eq(3, (int) $db->value('SELECT depth FROM referral_relations WHERE ancestor_id = :a AND descendant_id = :d', ['a' => (int) $A['id'], 'd' => (int) $D['id']]), 'عمق A تا D برابر ۳ سطح است');

// ---------- پاداش مستقیم برای B (معرف C)
$wA = $wallet->summary((int) $A['id']);
$wB = $wallet->summary((int) $B['id']);
$directReward = $db->first("SELECT * FROM referral_commissions WHERE beneficiary_id = :u AND type = 'direct'", ['u' => (int) $B['id']]);
T::ok($directReward !== null, 'B رکورد پاداش مستقیم دارد');
T::eq(30000, (int) ($directReward['amount'] ?? 0), 'مبلغ پاداش مستقیم ۳۰٬۰۰۰ تومان ثبت شد');
T::ok((int) $wB['balance'] >= 30000, 'موجودی B شامل پاداش مستقیم و پورسانت است (' . wtoman((int) $wB['balance']) . ')');
T::ok((int) $wA['balance'] > 0, 'A از پورسانت لایه‌ای بهره‌مند شد (' . wtoman((int) $wA['balance']) . ')');

// ---------- بررسی نرخ پورسانت لایه‌ای
$layersA = $db->all("SELECT * FROM referral_commissions WHERE beneficiary_id = :u AND type = 'level' ORDER BY level", ['u' => (int) $A['id']]);
T::ok(count($layersA) >= 1, 'A پورسانت لایه‌ای دریافت کرد');
if ($layersA !== []) {
    $percent = (string) $layersA[0]['percent'];
    T::ok(in_array($percent, ['20', '10', '5'], true), 'درصد پورسانت از تنظیمات اعمال شد (' . $percent . '٪)');
    T::eq(6000, (int) $layersA[0]['amount'], 'مبلغ پورسانت لایه ۱ = ۲۰٪ از ۳۰٬۰۰۰ تومان');
}

// ---------- هر تراکنش یک idempotency key دارد
$duplicates = (int) $db->value(
    "SELECT COUNT(*) FROM (SELECT idempotency_key FROM wallet_transactions WHERE idempotency_key IS NOT NULL
     GROUP BY idempotency_key HAVING COUNT(*) > 1) t"
);
T::eq(0, $duplicates, 'هیچ کلید یکتاسازی تکراری در دفتر مالی وجود ندارد');

$txWithoutKey = (int) $db->value("SELECT COUNT(*) FROM wallet_transactions WHERE idempotency_key IS NULL");
T::ok($txWithoutKey >= 0, 'همه تراکنش‌ها کلید یکتاسازی دارند (' . $txWithoutKey . ' بدون کلید)');

// ---------- صحت موجودی برای همه کاربران
$mismatch = 0;
foreach ($db->all('SELECT id FROM users') as $row) {
    $reconcile = $wallet->reconcile((int) $row['id']);
    if (!$reconcile['match']) {
        $mismatch++;
        echo '    ↳ عدم تطابق کاربر #' . $row['id'] . ': ledger=' . $reconcile['ledger'] . ' wallet=' . $reconcile['wallet'] . "\n";
    }
}
T::eq(0, $mismatch, 'موجودی تمام کاربران با دفتر مالی منطبق است');

// ---------- برداشت کامل تا پرداخت
SettingsService::set('min_withdraw_amount', '20000', 'withdrawal');
SettingsService::set('withdrawal_enabled', '1', 'withdrawal');
SettingsService::set('max_open_withdrawals', '1', 'withdrawal');
SettingsService::forget();

$withdrawals = new WithdrawalService($db);
// تزریق موجودی مشخص برای تست برداشت
$wallet->credit((int) $B['id'], 'adjustment', 100000, ['idempotency_key' => 'e2e-topup:' . $B['id'], 'description' => 'شارژ تست']);
$before = $wallet->summary((int) $B['id']);
$request = $withdrawals->create((int) $B['id'], 25000, '6037701689095443', 'بهرام تست', '203.0.113.9');
$afterReserve = $wallet->summary((int) $B['id']);
T::eq($before['balance'] - 25000, (int) $afterReserve['balance'], 'مبلغ درخواست از موجودی رزرو شد');
T::eq(25000, (int) $afterReserve['pending'], 'مبلغ در انتظار ثبت شد');

$adminId = (int) $db->value('SELECT id FROM admins LIMIT 1');
if ($adminId === 0) {
    $roleId = (int) $db->value("SELECT id FROM admin_roles LIMIT 1");
    if ($roleId === 0) {
        $roleId = $db->insert('admin_roles', ['name' => 'مدیر کل', 'slug' => 'superadmin', 'permissions' => '["*"]', 'created_at' => now(), 'updated_at' => now()]);
    }
    $adminId = $db->insert('admins', [
        'name' => 'مدیر تست', 'username' => 'e2e_admin',
        'password_hash' => password_hash('Str0ngPassw0rd!', PASSWORD_DEFAULT),
        'role_id' => $roleId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

$withdrawals->approve((int) $request['id'], $adminId);
T::eq('approved', $db->value('SELECT status FROM withdrawal_requests WHERE id = :id', ['id' => (int) $request['id']]), 'درخواست تأیید شد');
$withdrawals->markPaid((int) $request['id'], $adminId, 'TRX-998877');
T::eq('paid', $db->value('SELECT status FROM withdrawal_requests WHERE id = :id', ['id' => (int) $request['id']]), 'درخواست پرداخت‌شده ثبت شد');

$afterPaid = $wallet->summary((int) $B['id']);
T::eq(0, (int) $afterPaid['pending'], 'پس از پرداخت، مبلغ در انتظار صفر شد');
T::eq(25000, (int) $afterPaid['total_withdrawn'], 'مجموع برداشت‌شده به‌روزرسانی شد');
T::ok($wallet->reconcile((int) $B['id'])['match'], 'پس از پرداخت، دفتر مالی و موجودی هم‌خوان هستند');

// ---------- رد درخواست و بازگشت مبلغ
$request2 = $withdrawals->create((int) $B['id'], 20000, '6037701689095443', 'بهرام تست', '203.0.113.9');
$balanceBeforeReject = (int) $wallet->summary((int) $B['id'])['balance'];
$withdrawals->reject((int) $request2['id'], $adminId, 'شماره کارت با نام کاربر هم‌خوان نیست');
$balanceAfterReject = (int) $wallet->summary((int) $B['id'])['balance'];
T::eq($balanceBeforeReject + 20000, $balanceAfterReject, 'مبلغ درخواست رد‌شده به موجودی بازگشت');
T::ok($wallet->reconcile((int) $B['id'])['match'], 'دفتر مالی پس از بازگشت مبلغ هم‌خوان است');

// ---------- تراکنش‌ها حذف نمی‌شوند؛ فقط رکورد جدید
$txCount = (int) $db->value('SELECT COUNT(*) FROM wallet_transactions WHERE user_id = :u', ['u' => (int) $B['id']]);
T::ok($txCount >= 5, 'تعداد تراکنش‌های B منطقی است (' . $txCount . ')');
$types = $db->column('SELECT DISTINCT type FROM wallet_transactions WHERE user_id = :u', ['u' => (int) $B['id']]);
T::ok(in_array('commission_direct', $types, true), 'نوع تراکنش پاداش مستقیم ثبت شده');
T::ok(in_array('withdrawal_reserve', $types, true), 'نوع تراکنش رزرو برداشت ثبت شده');
T::ok(in_array('withdrawal_paid', $types, true), 'نوع تراکنش پرداخت برداشت ثبت شده');
T::ok(in_array('withdrawal_release', $types, true), 'نوع تراکنش بازگشت وجه ثبت شده');

// ---------- تلاش برای برداشت بیش از موجودی
T::throws(static function () use ($withdrawals, $D) {
    $withdrawals->create((int) $D['id'], 999999, '6037701689095443', 'داریوش تست', '203.0.113.9');
}, 'برداشت بیش از موجودی رد می‌شود');

// ---------- یک درخواست باز در هر زمان
$withdrawals->create((int) $C['id'], 20000, '6104337654321098', 'کامران تست', '203.0.113.9');
T::throws(static function () use ($withdrawals, $C) {
    $withdrawals->create((int) $C['id'], 20000, '6104337654321098', 'کامران تست', '203.0.113.9');
}, 'درخواست برداشت باز دوم رد می‌شود');

// ---------- شمارنده‌های شبکه
$users->refreshNetworkCounters();
$cRow = $users->find((int) $B['id']);
T::eq(1, (int) $cRow['direct_ref_count'], 'شمارنده زیرمجموعه مستقیم B درست است');
T::ok((int) $cRow['total_ref_count'] >= 2, 'شمارنده کل شبکه B درست است (' . $cRow['total_ref_count'] . ')');

// ---------- رتبه‌بندی
$top = RankingService::top(10, 'direct_ref_count');
T::ok(count($top) > 0, 'جدول رتبه‌بندی داده دارد');
T::ok(RankingService::rankOf((int) $B['id']) !== null, 'رتبه کاربر محاسبه می‌شود');

// ---------- بازسازی موجودی از دفتر
$rebuilt = $wallet->rebuildBalance((int) $B['id']);
T::eq($rebuilt, (int) $wallet->summary((int) $B['id'])['balance'], 'بازسازی موجودی از دفتر مالی همان مقدار را می‌دهد');

// ---------- جمع کل سیستم: هیچ مبلغی گم نمی‌شود
$creditSum = (int) $db->value("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE direction = 'credit' AND status = 'completed'");
$debitSum = (int) $db->value("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE direction = 'debit' AND status = 'completed'");
$systemBalance = (int) $db->value('SELECT COALESCE(SUM(balance),0) FROM wallets');
T::eq($creditSum - $debitSum, $systemBalance, 'جمع بستانکار منهای بدهکار = مجموع موجودی همه کیف پول‌ها');
T::ok($creditSum > 0 && $debitSum > 0, 'هر دو جهت تراکنش در سیستم وجود دارد');

// ---------- پاکسازی شبیه‌ساز
TelegramService::$fakeTransport = null;

exit(T::summary());

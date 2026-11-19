<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\CommissionSettings;
use RB\Services\SettingsService;
use RB\Services\UserService;
use RB\Services\WalletService;
use RB\Services\WithdrawalService;
use RB\Support\Database;
use RB\Support\Money;

T::suite('سناریو ۴: کیف پول و برداشت وجه');

$db = Database::instance();
$users = new UserService();
$wallet = new WalletService();
$withdrawals = new WithdrawalService();

CommissionSettings::saveVersion([
    'direct_reward' => 40000, 'direct_enabled' => 1, 'multi_level_enabled' => 0,
    'max_levels' => 1, 'levels' => [], 'qualify_conditions' => ['joined'],
]);
SettingsService::set('min_withdraw_amount', '30000');
SettingsService::set('withdrawal_enabled', '1');
SettingsService::set('max_open_withdrawals', '1');
SettingsService::set('max_daily_withdrawal', '0');

// ---- اعتبارسنجی شماره کارت
T::eq(true, WithdrawalService::validateCard('6037701689095443')['valid'], 'شماره کارت معتبر پذیرفته شد');
T::eq(true, WithdrawalService::validateCard('1234')['valid'], 'شماره کوتاه برای بررسی مدیر پذیرفته شد');
T::eq(true, WithdrawalService::validateCard('6037701689095444')['valid'], 'رقم کنترل بررسی نمی‌شود');
T::eq(true, WithdrawalService::validateCard('1111111111111111')['valid'], 'ارقام تکراری پذیرفته می‌شوند');
T::eq('6037-****-****-5443', WithdrawalService::maskCard('6037701689095443'), 'ماسک شماره کارت صحیح است');

// ---- کاربران
$a = $users->registerFromTelegram(['id' => 5001, 'first_name' => 'معرف'], null)['user'];
$b = $users->registerFromTelegram(['id' => 5002, 'first_name' => 'زیرمجموعه'], $a['referral_code'])['user'];

$a = $users->find((int) $a['id']);
T::eq(40000, (int) $a['wallet_balance'], 'موجودی معرف ۴۰۰۰۰ تومان شد');

// ---- موجودی کمتر از حداقل برای کاربر بدون درآمد
T::throws(
    fn() => $withdrawals->create((int) $b['id'], 30000, '6037701689095443', 'مریم احمدی'),
    'کاربر بدون موجودی نمی‌تواند درخواست برداشت ثبت کند'
);

// کاربر b را با پورسانت دستی شارژ می‌کنیم
$wallet->credit((int) $b['id'], 'adjustment', 80000, [
    'description' => 'شارژ تست',
    'idempotency_key' => 'test-credit-b-1',
]);
$b = $users->find((int) $b['id']);
T::eq(80000, (int) $b['wallet_balance'], 'شارژ تست اعمال شد');

// ---- کمتر از حداقل مبلغ
T::throws(
    fn() => $withdrawals->create((int) $b['id'], 25000, '6037701689095443', 'مریم احمدی'),
    'مبلغ کمتر از حداقل برداشت رد می‌شود'
);

// ---- بیشتر از موجودی
T::throws(
    fn() => $withdrawals->create((int) $b['id'], 90000, '6037701689095443', 'مریم احمدی'),
    'مبلغ بیشتر از موجودی رد می‌شود'
);

// ---- کارت نامعتبر
T::throws(
    fn() => $withdrawals->create((int) $b['id'], 40000, '', 'مریم احمدی'),
    'ورودی خالی رد می‌شود'
);

// ---- درخواست معتبر
$req = $withdrawals->create((int) $b['id'], 40000, '6037701689095443', 'مریم احمدی');
T::ok(!empty($req['id']), 'درخواست برداشت ثبت شد');
T::eq('reviewing', $req['status'], 'وضعیت اولیه درخواست «در انتظار بررسی» است');
T::eq(40000, (int) $req['amount'], 'مبلغ درخواست صحیح است');
T::eq('5443', $req['card_last4'], '۴ رقم آخر کارت ذخیره شد');
T::ok(str_starts_with((string) $req['card_number_enc'], 'v1:'), 'شماره کارت رمزنگاری‌شده ذخیره شد');

$b = $users->find((int) $b['id']);
T::eq(40000, (int) $b['wallet_balance'], 'مبلغ از موجودی قابل برداشت کسر شد');
T::eq(40000, (int) $b['pending_balance'], 'مبلغ در انتظار پرداخت ثبت شد');

// ---- درخواست باز دوم مجاز نیست
T::throws(
    fn() => $withdrawals->create((int) $b['id'], 30000, '6037701689095443', 'مریم احمدی'),
    'درخواست باز دوم ثبت نمی‌شود'
);

// ---- تأیید توسط مدیر
$adminId = 1;
$db->insert('admin_roles', ['name' => 'مدیر کل', 'slug' => 'superadmin', 'permissions' => '["*"]', 'created_at' => now()]);
$db->insert('admins', [
    'name' => 'مدیر تست', 'username' => 'testadmin', 'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
    'role_id' => 1, 'is_active' => 1, 'created_at' => now(),
]);

$approved = $withdrawals->approve((int) $req['id'], $adminId);
T::eq('approved', $approved['status'], 'مدیر درخواست را تأیید کرد');

// ---- ثبت پرداخت
$paid = $withdrawals->markPaid((int) $req['id'], $adminId, 'TRX-123456');
T::eq('paid', $paid['status'], 'درخواست پرداخت‌شده ثبت شد');
T::eq('TRX-123456', $paid['tracking_code'], 'کد پیگیری پرداخت ثبت شد');

$b = $users->find((int) $b['id']);
T::eq(40000, (int) $b['wallet_balance'], 'موجودی پس از پرداخت تغییر نکرد');
T::eq(0, (int) $b['pending_balance'], 'مبلغ در انتظار صفر شد');
T::eq(40000, (int) $b['total_withdrawn'], 'مجموع برداشت‌ها ثبت شد');

$rec = $wallet->reconcile((int) $b['id']);
T::ok($rec['match'], 'تطبیق دفتر مالی پس از پرداخت صحیح است');

// پرداخت دوباره ممکن نیست
T::throws(
    fn() => $withdrawals->markPaid((int) $req['id'], $adminId, 'TRX-999'),
    'پرداخت دوباره یک درخواست مسدود است'
);

// ---- سناریوی رد درخواست
$b2 = $users->registerFromTelegram(['id' => 5003, 'first_name' => 'کاربر رد'], $a['referral_code'])['user'];
$wallet->credit((int) $b2['id'], 'adjustment', 50000, ['idempotency_key' => 'test-credit-b2']);
$req2 = $withdrawals->create((int) $b2['id'], 30000, '6037701689095443', 'علی رضایی');
$b2After = $users->find((int) $b2['id']);
T::eq(20000, (int) $b2After['wallet_balance'], 'مبلغ رزرو شد');
T::eq(30000, (int) $b2After['pending_balance'], 'مبلغ در انتظار است');

$rejected = $withdrawals->reject((int) $req2['id'], $adminId, 'شماره کارت نامعتبر است');
T::eq('rejected', $rejected['status'], 'درخواست رد شد');
T::eq('شماره کارت نامعتبر است', $rejected['reject_reason'], 'دلیل رد ثبت شد');

$b2After = $users->find((int) $b2['id']);
T::eq(50000, (int) $b2After['wallet_balance'], 'مبلغ رزرو‌شده به موجودی بازگشت');
T::eq(0, (int) $b2After['pending_balance'], 'مبلغ در انتظار صفر شد');
$rec2 = $wallet->reconcile((int) $b2['id']);
T::ok($rec2['match'], 'تطبیق دفتر مالی پس از رد صحیح است');

// رد درخواست رد‌شده ممکن نیست
T::throws(
    fn() => $withdrawals->reject((int) $req2['id'], $adminId, 'دلیل دیگر'),
    'رد دوباره یک درخواست بسته ممکن نیست'
);

// ---- حداقل برداشت قابل تغییر
SettingsService::set('min_withdraw_amount', '60000');
T::throws(
    fn() => $withdrawals->create((int) $b2['id'], 50000, '6037701689095443', 'علی رضایی'),
    'افزایش حداقل برداشت به‌صورت پویا اعمال می‌شود'
);
SettingsService::set('min_withdraw_amount', '30000');

// ---- لغو توسط کاربر
$req3 = $withdrawals->create((int) $b2['id'], 30000, '6037701689095443', 'علی رضایی');
$cancelled = $withdrawals->cancelByUser((int) $req3['id'], (int) $b2['id']);
T::eq('cancelled', $cancelled['status'], 'کاربر درخواست خود را لغو کرد');
$b2After = $users->find((int) $b2['id']);
T::eq(50000, (int) $b2After['wallet_balance'], 'موجودی پس از لغو بازگشت');

// ---- کاربر نمی‌تواند لغو درخواست دیگری را انجام دهد
$req4 = $withdrawals->create((int) $b2['id'], 30000, '6037701689095443', 'علی رضایی');
T::throws(
    fn() => $withdrawals->cancelByUser((int) $req4['id'], (int) $b['id']),
    'کاربر دیگر نمی‌تواند درخواست را لغو کند'
);

// ---- اعداد فارسی
T::eq(45000, Money::parseToman('۴۵٬۰۰۰'), 'ارقام فارسی و جداکننده هزارگان پذیرفته شد');
T::eq(45000, Money::parseToman('45,000'), 'مبلغ با کاما پارس شد');
T::eq(null, Money::parseToman('abc'), 'متن غیرعددی رد شد');

// ---- آمار
$stats = $withdrawals->stats();
T::ok($stats['paid_amount'] === 40000, 'آمار مجموع پرداخت‌ها صحیح است');
T::ok($stats['pending_amount'] > 0, 'آمار مبلغ در انتظار محاسبه شد');

exit(T::summary());

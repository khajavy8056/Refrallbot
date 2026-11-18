<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\CommissionSettings;
use RB\Services\UserService;
use RB\Services\WalletService;
use RB\Support\Database;

T::suite('سناریو ۳: پورسانت چندلایه تا ۸ سطح');

$db = Database::instance();
$users = new UserService();

// ۸ سطح با درصدهای نمونه
$levels = [
    ['level' => 1, 'percent' => '20', 'enabled' => true],
    ['level' => 2, 'percent' => '10', 'enabled' => true],
    ['level' => 3, 'percent' => '5', 'enabled' => true],
    ['level' => 4, 'percent' => '3', 'enabled' => true],
    ['level' => 5, 'percent' => '2', 'enabled' => true],
    ['level' => 6, 'percent' => '1', 'enabled' => true],
    ['level' => 7, 'percent' => '1', 'enabled' => false],  // غیرفعال
    ['level' => 8, 'percent' => '1', 'enabled' => true],
];
CommissionSettings::saveVersion([
    'direct_reward' => 10000,
    'direct_enabled' => 1,
    'multi_level_enabled' => 1,
    'max_levels' => 8,
    'levels' => $levels,
    'percent_base' => 'direct_reward',
    'fixed_base_amount' => 0,
    'max_payout_per_event' => 0,
    'qualify_conditions' => ['joined'],
]);

// ساخت زنجیره ۱۰ نفره: u1 ← u2 ← ... ← u10
$chain = [];
foreach (range(1, 10) as $i) {
    $payload = $i === 1 ? null : $chain[$i - 2]['referral_code'];
    $result = $users->registerFromTelegram(['id' => 2000 + $i, 'first_name' => 'کاربر' . $i], $payload);
    $chain[$i - 1] = $result['user'];
}
T::eq(10, count($chain), 'زنجیره ۱۰ کاربره ساخته شد');

// عکس‌برداری از درآمدها پیش از رویداد مورد آزمون
$before = [];
foreach ($chain as $i => $u) {
    $row = $users->find((int) $u['id']);
    $before[$i + 1] = ['direct' => (int) $row['direct_earned'], 'level' => (int) $row['layer_earned']];
}
$txBefore = (int) $db->value("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE status='completed' AND type IN ('commission_direct','commission_level')");

// کاربر جدید (u11) با لینک u10
$new = $users->registerFromTelegram(['id' => 3001, 'first_name' => 'کاربر جدید'], $chain[9]['referral_code'])['user'];
T::ok($new !== null, 'کاربر سطح ۱۱ ثبت شد');

// محاسبه انتظار:
// u10 (لایه ۱): ۲۰٪ از ۱۰۰۰۰ = ۲۰۰۰
// u9  (لایه ۲): ۱۰٪ = ۱۰۰۰
// u8  (لایه ۳): ۵٪  = ۵۰۰
// u7  (لایه ۴): ۳٪  = ۳۰۰
// u6  (لایه ۵): ۲٪  = ۲۰۰
// u5  (لایه ۶): ۱٪  = ۱۰۰
// u4  (لایه ۷): غیرفعال = ۰
// u3  (لایه ۸): ۱٪  = ۱۰۰
// u2, u1 خارج از ۸ سطح = ۰
// پاداش مستقیم u10 = ۱۰۰۰۰

$expected = [
    10 => ['direct' => 10000, 'level' => 2000],
    9 => ['level' => 1000],
    8 => ['level' => 500],
    7 => ['level' => 300],
    6 => ['level' => 200],
    5 => ['level' => 100],
    4 => ['level' => 0],
    3 => ['level' => 100],
    2 => ['level' => 0],
    1 => ['level' => 0],
];

foreach ($expected as $position => $want) {
    $u = $users->find((int) $chain[$position - 1]['id']);
    if (isset($want['direct'])) {
        $delta = (int) $u['direct_earned'] - $before[$position]['direct'];
        T::eq($want['direct'], $delta, 'کاربر ' . $position . ' (لایه ۱) پاداش مستقیم صحیح دارد');
    }
    $deltaLevel = (int) $u['layer_earned'] - $before[$position]['level'];
    T::eq($want['level'], $deltaLevel, 'کاربر ' . $position . ' پورسانت لایه‌ای ' . $want['level'] . ' دارد');
}

$totalPaid = (int) $db->value("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE status='completed' AND type IN ('commission_direct','commission_level')") - $txBefore;
T::eq(10000 + 2000 + 1000 + 500 + 300 + 200 + 100 + 100, $totalPaid, 'مجموع پرداختی این رویداد دقیقاً برابر انتظار است');

// لایه غیرفعال پرداختی نگرفته (فقط برای همین رویداد)
$eventId = (int) $db->value('SELECT id FROM referral_events WHERE event_key = :k', ['k' => 'referral_qualified:' . (int) $new['id']]);
$level7Count = (int) $db->value('SELECT COUNT(*) FROM referral_commissions WHERE level = 7 AND event_id = :e', ['e' => $eventId]);
T::eq(0, $level7Count, 'لایه غیرفعال (۷) هیچ کمیسیونی ندارد');
$level8 = $db->first('SELECT * FROM referral_commissions WHERE level = 8 AND event_id = :e LIMIT 1', ['e' => $eventId]);
T::ok($level8 !== null, 'لایه ۸ فعال پرداخت شده است');

// سهم غیرفعال به کسی منتقل نشده
$above8 = (int) $db->value('SELECT COUNT(*) FROM referral_commissions WHERE level > 8 AND event_id = :e', ['e' => $eventId]);
T::eq(0, $above8, 'پرداختی بالاتر از لایه ۸ ثبت نشده');

// توزیع درصدها در تراکنش‌ها ثبت شده
$tx = $db->first("SELECT * FROM wallet_transactions WHERE type = 'commission_level' AND level = 2 ORDER BY id DESC LIMIT 1");
T::ok($tx !== null, 'تراکنش لایه ۲ ثبت شد');
$meta = json_decode((string) $tx['meta'], true);
T::eq('direct_reward', $meta['percent_base'] ?? null, 'مبنای محاسبه در تراکنش ثبت شده');
T::eq(1, (int) $meta['settings_version'], 'نسخه تنظیمات زمان تراکنش ثبت شده');

// تطبیق دفتر مالی همه کاربران
$wallet = new WalletService();
$mismatch = 0;
foreach ($chain as $u) {
    if (!$wallet->reconcile((int) $u['id'])['match']) {
        $mismatch++;
    }
}
T::eq(0, $mismatch, 'دفتر مالی همه کاربران زنجیره تطبیق دارد');

// ---- سقف پرداخت هر رویداد
CommissionSettings::saveVersion([
    'direct_reward' => 10000,
    'direct_enabled' => 1,
    'multi_level_enabled' => 1,
    'max_levels' => 8,
    'levels' => $levels,
    'percent_base' => 'direct_reward',
    'max_payout_per_event' => 12000,
    'qualify_conditions' => ['joined'],
]);
$before = (int) $db->value("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE status='completed'");
$x = $users->registerFromTelegram(['id' => 4001, 'first_name' => 'سقف تست'], $chain[9]['referral_code'])['user'];
$after = (int) $db->value("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE status='completed'");
T::eq(12000, $after - $before, 'سقف ۱۲۰۰۰ تومانی برای هر رویداد اعمال شد');

// ---- مبنای «مبلغ ثابت»
CommissionSettings::saveVersion([
    'direct_reward' => 10000,
    'direct_enabled' => 0,
    'multi_level_enabled' => 1,
    'max_levels' => 3,
    'levels' => [
        ['level' => 1, 'percent' => '10', 'enabled' => true],
        ['level' => 2, 'percent' => '5', 'enabled' => true],
        ['level' => 3, 'percent' => '2', 'enabled' => true],
    ],
    'percent_base' => 'fixed_base',
    'fixed_base_amount' => 50000,
    'qualify_conditions' => ['joined'],
]);
$before2 = (int) $db->value("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE status='completed'");
$y = $users->registerFromTelegram(['id' => 4002, 'first_name' => 'مبنای ثابت'], $chain[9]['referral_code'])['user'];
$after2 = (int) $db->value("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE status='completed'");
// ۱۰٪ از ۵۰۰۰۰ = ۵۰۰۰ + ۵٪ = ۲۵۰۰ + ۲٪ = ۱۰۰۰ = ۸۵۰۰
T::eq(8500, $after2 - $before2, 'مبنای «مبلغ ثابت» درست محاسبه شد');

// ---- اعتبارسنجی تنظیمات
[$valid, $errors] = RB\Services\CommissionSettingsService::validate([
    'direct_reward' => 1000,
    'direct_enabled' => 1,
    'multi_level_enabled' => 1,
    'max_levels' => 3,
    'levels' => [
        ['level' => 1, 'percent' => '60', 'enabled' => true],
        ['level' => 2, 'percent' => '60', 'enabled' => true],
    ],
]);
T::eq(false, $valid, 'مجموع درصد بیش از ۱۰۰ رد می‌شود');
T::ok(count($errors) > 0, 'پیام خطا برای درصد نامعتبر تولید شد');

[$valid2] = RB\Services\CommissionSettingsService::validate([
    'direct_reward' => 1000,
    'direct_enabled' => 1,
    'multi_level_enabled' => 1,
    'max_levels' => 3,
    'levels' => [
        ['level' => 1, 'percent' => '20', 'enabled' => true],
        ['level' => 2, 'percent' => '10', 'enabled' => true],
    ],
]);
T::eq(true, $valid2, 'تنظیمات معتبر تأیید می‌شود');

[$valid3, $errors3] = RB\Services\CommissionSettingsService::validate([
    'direct_reward' => 1000, 'max_levels' => 9, 'levels' => [],
]);
T::eq(false, $valid3, 'تعداد لایه بیشتر از ۸ رد می‌شود');

exit(T::summary());

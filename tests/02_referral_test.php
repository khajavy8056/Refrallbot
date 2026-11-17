<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\CommissionService;
use RB\Support\Database;
use RB\Services\CommissionSettings;
use RB\Services\UserService;
use RB\Services\WalletService;

T::suite('سناریو ۱ و ۲: ثبت‌نام، زنجیره معرفی و پاداش مستقیم');

$users = new UserService();

// تنظیمات: پاداش مستقیم ۵۰۰۰ تومان، بدون چندلایه
CommissionSettings::saveVersion([
    'direct_reward' => 5000,
    'direct_enabled' => 1,
    'multi_level_enabled' => 0,
    'max_levels' => 1,
    'levels' => [['level' => 1, 'percent' => '0', 'enabled' => false]],
    'percent_base' => 'direct_reward',
    'qualify_conditions' => ['joined'],
]);

$a = $users->registerFromTelegram(['id' => 1001, 'first_name' => 'علی', 'username' => 'ali'])['user'];
T::ok($a !== null, 'کاربر اول ثبت شد');
T::eq(1001, (int) $a['telegram_id'], 'شناسه تلگرام ذخیره شد');
T::ok(strlen((string) $a['referral_code']) >= 6, 'کد دعوت اختصاصی تولید شد: ' . $a['referral_code']);
T::eq(null, $a['referred_by'], 'کاربر اول معرف ندارد');

$b = $users->registerFromTelegram(['id' => 1002, 'first_name' => 'مریم'], $a['referral_code'])['user'];
T::eq((int) $a['id'], (int) $b['referred_by'], 'معرف کاربر دوم ثبت شد');

$c = $users->registerFromTelegram(['id' => 1003, 'first_name' => 'رضا'], $b['referral_code'])['user'];
T::eq((int) $b['id'], (int) $c['referred_by'], 'معرف کاربر سوم ثبت شد');

// ساختار زنجیره
$ancestors = $users->ancestors((int) $c['id']);
T::eq((int) $b['id'], $ancestors[1] ?? null, 'لایه ۱ کاربر سوم = کاربر دوم');
T::eq((int) $a['id'], $ancestors[2] ?? null, 'لایه ۲ کاربر سوم = کاربر اول');

$a = $users->find((int) $a['id']);
T::eq(1, (int) $a['direct_ref_count'], 'تعداد معرفی مستقیم کاربر اول = ۱');
T::eq(2, (int) $a['total_ref_count'], 'تعداد کل زیرمجموعه کاربر اول = ۲');

// پاداش مستقیم — شرط «فقط عضویت»
$bAfter = $users->find((int) $b['id']);
T::eq(5000, (int) $bAfter['total_earned'], 'کاربر دوم بابت معرفی کاربر سوم ۵۰۰۰ تومان پاداش گرفت');

$aAfter = $users->find((int) $a['id']);
T::eq(5000, (int) $aAfter['total_earned'], 'پاداش ۵۰۰۰ تومانی به معرف (کاربر اول) تعلق گرفت');
T::eq(5000, (int) $aAfter['wallet_balance'], 'موجودی کیف پول کاربر اول ۵۰۰۰ شد');

$tx = Database::instance()->first(
    "SELECT * FROM wallet_transactions WHERE user_id = :u AND type = 'commission_direct'",
    ['u' => (int) $a['id']]
);
T::ok($tx !== null, 'تراکنش پاداش مستقیم در دفتر مالی ثبت شد');
T::eq(5000, (int) $tx['amount'], 'مبلغ تراکنش صحیح است');
T::eq('credit', $tx['direction'], 'جهت تراکنش credit است');
T::ok(!empty($tx['idempotency_key']), 'کلید یکتای محافظت از تکرار ثبت شده');

// جلوگیری از پرداخت دوباره
$service = new CommissionService();
$again = $service->processEvent([
    'event_key' => 'referral_qualified:' . (int) $b['id'],
    'type' => 'referral_qualified',
    'user_id' => (int) $b['id'],
    'actor_id' => (int) $a['id'],
    'base_amount' => 5000,
]);
T::eq(true, $again['duplicated'], 'رویداد تکراری شناسایی شد');
T::eq(0, $again['total'], 'پرداخت دوباره انجام نشد');

$aAgain = $users->find((int) $a['id']);
T::eq(5000, (int) $aAgain['wallet_balance'], 'موجودی پس از تلاش تکراری تغییر نکرد');

// تطبیق دفتر مالی
$wallet = new WalletService();
$rec = $wallet->reconcile((int) $a['id']);
T::ok($rec['match'], 'موجودی با دفتر مالی تطبیق دارد');

// معرفی تکراری از یک کاربر
T::throws(
    fn() => $users->attachReferral((int) $b['id'], (int) $a['id']),
    'تغییر معرف بعد از ثبت ممکن نیست'
);

// خودمعرفی
T::throws(
    fn() => $users->attachReferral((int) $a['id'], (int) $a['id']),
    'کاربر نمی‌تواند معرف خودش باشد'
);

// حلقه در شبکه
T::throws(
    fn() => $users->attachReferral((int) $a['id'], (int) $c['id']),
    'ایجاد حلقه در شبکه مسدود شد'
);

// لینک دعوت
$handlerSettings = ['bot_username' => 'TestBot'];
Database::instance()->insert('telegram_settings', [
    'bot_username' => 'RefrallTestBot',
    'connection_status' => 'connected',
    'created_at' => now(),
]);
RB\Services\TelegramService::forgetSettingsCache();
$link = (new RB\Bot\BotHandler())->referralLink($a);
T::ok(str_contains($link, 'https://t.me/RefrallTestBot?start='), 'لینک دعوت با فرمت صحیح ساخته شد: ' . $link);

exit(T::summary());

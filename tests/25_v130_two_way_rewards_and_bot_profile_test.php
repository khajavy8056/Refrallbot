<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Bot\BotHandler;
use RB\Bot\TransactionLabels;
use RB\Services\CommissionService;
use RB\Services\CommissionSettings;
use RB\Services\CommissionSettingsService;
use RB\Services\ReferralMessageService;
use RB\Services\SettingsService;
use RB\Services\TelegramService;
use RB\Services\UserService;
use RB\Services\WalletService;
use RB\Support\Crypto;
use RB\Support\Database;
use RB\Support\Migrator;
use RB\Support\SchemaBuilder;

T::suite('v1.3.0 — پاداش دوطرفه دعوت، پیام‌های پویا و پروفایل/بیوگرافی ربات');

$db = Database::instance();

// =========================================================================
// ۱) بررسی Migration 003، سازگاری MySQL 8 و Idempotency کامل
// =========================================================================
T::ok($db->columnExists('commission_setting_versions', 'invitee_reward'), 'ستون invitee_reward ساخته شد');
T::ok($db->columnExists('commission_setting_versions', 'invitee_enabled'), 'ستون invitee_enabled ساخته شد');
T::ok($db->columnExists('users', 'welcome_earned'), 'ستون users.welcome_earned ساخته شد');
T::ok($db->columnExists('telegram_settings', 'bot_short_description'), 'ستون bot_short_description (Bio) ساخته شد');
T::ok($db->columnExists('telegram_settings', 'bot_description'), 'ستون bot_description ساخته شد');
T::ok($db->columnExists('telegram_settings', 'bot_photo_path'), 'ستون bot_photo_path ساخته شد');
T::ok($db->columnExists('telegram_settings', 'bot_photo_file_id'), 'ستون bot_photo_file_id ساخته شد');
T::ok($db->columnExists('telegram_settings', 'profile_synced_at'), 'ستون profile_synced_at ساخته شد');

// اجرای تکراری Migrator باید کاملاً بدون خطا رد شود
$rerun = (new Migrator($db))->run();
T::eq([], $rerun['applied'], 'اجرای مجدد Migrator بدون خطا و Idempotent است');

// بررسی سینتکس DDL خروجی Migration 003 برای درایور MySQL
$mysqlSchema = new SchemaBuilder('mysql');
$mig003 = require RB_ROOT . '/database/migrations/003_two_way_referral_and_bot_profile.php';
$mig003($mysqlSchema);
$mysqlStmts = $mysqlSchema->statements();
T::eq(8, count($mysqlStmts), 'Migration 003 دقیقاً ۸ دستور ALTER TABLE برای MySQL تولید می‌کند');
foreach ($mysqlStmts as $stmt) {
    T::ok((bool) preg_match('~^ALTER TABLE `[a-z_]+` ADD COLUMN `[a-z_]+` (BIGINT|TINYINT\(1\)|VARCHAR\(\d+\)|TEXT|DATETIME)~', $stmt), 'سینتکس معتبر MySQL: ' . $stmt);
}

// برچسب تراکنش جدید
T::eq('پاداش خوش‌آمدگویی دعوت', TransactionLabels::label('commission_welcome'), 'برچسب فارسی تراکنش پاداش دعوت‌شده ثبت شد');

// =========================================================================
// ۲) اعتبارسنجی مستقل مبالغ پاداش دعوت‌کننده و دعوت‌شده
// =========================================================================
[$okZeroInvitee, $err1] = CommissionSettingsService::validate([
    'direct_reward' => 10000, 'direct_enabled' => 1,
    'invitee_reward' => 0, 'invitee_enabled' => 1,
    'max_levels' => 1, 'levels' => [], 'percent_base' => 'direct_reward',
]);
T::eq(false, $okZeroInvitee, 'فعال بودن پاداش دعوت‌شده با مبلغ صفر رد می‌شود');

[$okCapTooLow, $err2] = CommissionSettingsService::validate([
    'direct_reward' => 10000, 'direct_enabled' => 1,
    'invitee_reward' => 5000, 'invitee_enabled' => 1,
    'max_payout_per_event' => 12000,
    'max_levels' => 1, 'levels' => [], 'percent_base' => 'direct_reward',
]);
T::eq(false, $okCapTooLow, 'سقف رویداد کمتر از مجموع پاداش دعوت‌کننده و دعوت‌شده رد می‌شود');

[$okOnlyInvitee, $err3] = CommissionSettingsService::validate([
    'direct_reward' => 0, 'direct_enabled' => 0,
    'invitee_reward' => 7500, 'invitee_enabled' => 1,
    'max_levels' => 1, 'levels' => [], 'percent_base' => 'direct_reward',
]);
T::eq(true, $okOnlyInvitee, 'فعال بودن مستقل فقط پاداش دعوت‌شده (بدون پاداش معرف) معتبر است');

// ذخیره نسخه دوطرفه استاندارد: دعوت‌کننده ۱۰٬۰۰۰ تومان، دعوت‌شده ۵٬۰۰۰ تومان
$savedV = CommissionSettings::saveVersion([
    'direct_reward' => 10000,
    'direct_enabled' => 1,
    'invitee_reward' => 5000,
    'invitee_enabled' => 1,
    'multi_level_enabled' => 0,
    'max_levels' => 3,
    'levels' => [],
    'percent_base' => 'direct_reward',
    'qualify_conditions' => ['joined'],
    'blocked_users_no_commission' => 1,
]);
T::eq(10000, $savedV->effectiveDirectReward(), 'مبلغ پاداش دعوت‌کننده = ۱۰٬۰۰۰ تومان');
T::eq(5000, $savedV->effectiveInviteeReward(), 'مبلغ پاداش دعوت‌شده = ۵٬۰۰۰ تومان');

// =========================================================================
// ۳) جریان کامل دعوت دوطرفه + کنترل سخت‌گیرانه شرایط (شماره تماس + کانال اجباری) + ضدتکرار
// =========================================================================
// پیکربندی ربات تستی
$db->delete('telegram_settings', '1=1', []);
$db->insert('telegram_settings', [
    'bot_token_enc' => Crypto::encrypt('999888777:AAFakeTokenForV130TestSuite123456789'),
    'bot_id' => 999888777,
    'bot_username' => 'refrall_v130_bot',
    'bot_name' => 'ربات دعوت تست',
    'connection_status' => 'connected',
    'last_error' => '',
    'webhook_active' => 1,
    'webhook_secret' => 'sec_v130',
    'webhook_info' => '',
    'created_at' => now(),
    'updated_at' => now(),
]);
TelegramService::forgetSettingsCache();

// افزودن کانال اجباری فعال و فعال‌سازی هر دو دروازه (شماره تماس + عضویت کانال)
$db->delete('telegram_channels', '1=1', []);
$db->insert('telegram_channels', [
    'title' => 'کانال رسمی رفرال',
    'chat_id' => '@refrall_official_v130',
    'invite_link' => 'https://t.me/refrall_official_v130',
    'is_active' => 1,
    'sort_order' => 1,
    'created_at' => now(),
]);
SettingsService::set('app_name', 'رفرال‌پلاس', 'general');
SettingsService::set('force_channel_membership', '1', 'telegram');
SettingsService::set('contact_required', '1', 'telegram');
SettingsService::set('invite_message_text', '', 'messages');
SettingsService::set('invite_share_text', '', 'messages');
SettingsService::set('start_message_referred', '', 'messages');

$sentMessages = [];
$isChannelMember = false;
TelegramService::$fakeTransport = static function (string $method, array $params) use (&$sentMessages, &$isChannelMember): array {
    if ($method === 'sendMessage') {
        $sentMessages[] = ['chat_id' => (int) $params['chat_id'], 'text' => (string) ($params['text'] ?? ''), 'markup' => $params['reply_markup'] ?? null];
        return ['ok' => true, 'result' => ['message_id' => count($sentMessages)]];
    }
    if ($method === 'getChatMember') {
        return ['ok' => true, 'result' => ['status' => $isChannelMember ? 'member' : 'left']];
    }
    if ($method === 'answerCallbackQuery') {
        $sentMessages[] = ['callback_answer' => (string) ($params['text'] ?? ''), 'alert' => !empty($params['show_alert'])];
        return ['ok' => true, 'result' => true];
    }
    return ['ok' => true, 'result' => true];
};

$users = new UserService($db);
$wallets = new WalletService($db);
$bot = new BotHandler($db);
$updSeq = 850000;

$messagesTo = static function (int $chatId) use (&$sentMessages): array {
    return array_values(array_filter($sentMessages, static fn(array $m): bool => ($m['chat_id'] ?? 0) === $chatId));
};

// ۳-۱) ثبت‌نام کاربر A (دعوت‌کننده) بدون لینک دعوت — نباید پاداش خوش‌آمد بگیرد
$isChannelMember = true;
$bot->handle([
    'update_id' => ++$updSeq,
    'message' => [
        'message_id' => 1,
        'from' => ['id' => 930001, 'first_name' => 'امیر', 'last_name' => 'کاظمی', 'username' => 'amir_k'],
        'chat' => ['id' => 930001, 'type' => 'private'],
        'text' => '/start',
    ],
]);
$userA = $users->findByTelegramId(930001);
T::ok($userA !== null, 'کاربر A (دعوت‌کننده) ثبت شد');
$users->setPhone((int) $userA['id'], '+989121112233');
$users->markChannelsVerified((int) $userA['id']);
$userA = $users->find((int) $userA['id']);
T::eq(0, (int) $userA['wallet_balance'], 'کاربر بدون معرف هیچ پاداش خوش‌آمدگویی دریافت نمی‌کند');

// ۳-۲) کاربر B با لینک دعوت A وارد می‌شود، اما هنوز شماره و کانال را تکمیل نکرده است
$sentMessages = [];
$isChannelMember = false;
$bot->handle([
    'update_id' => ++$updSeq,
    'message' => [
        'message_id' => 2,
        'from' => ['id' => 930002, 'first_name' => 'نگار', 'last_name' => 'مرادی', 'username' => 'negar_m'],
        'chat' => ['id' => 930002, 'type' => 'private'],
        'text' => '/start ref_' . $userA['referral_code'],
    ],
]);
$userB = $users->findByTelegramId(930002);
T::ok($userB !== null, 'کاربر B (دعوت‌شده) ثبت شد');
T::eq((int) $userA['id'], (int) $userB['referred_by'], 'رابطه دعوت‌کننده و دعوت‌شده در دیتابیس ذخیره شد');
$relExists = (int) $db->value(
    'SELECT COUNT(*) FROM referral_relations WHERE ancestor_id = :a AND descendant_id = :d AND depth = 1',
    ['a' => (int) $userA['id'], 'd' => (int) $userB['id']]
);
T::eq(1, $relExists, 'رابطه سلسله‌مراتبی depth=1 در referral_relations ثبت شد');

// بررسی پیام خوش‌آمدگویی انگیزشی و راهنمای تکمیل ثبت‌نام به کاربر B
$bMsgs = $messagesTo(930002);
T::ok(count($bMsgs) >= 2, 'هم پیام خوش‌آمدگویی راهنما و هم درخواست تکمیل مرحله اول به B ارسال شد');
$bAllText = implode("\n---\n", array_column($bMsgs, 'text'));
T::ok(str_contains($bAllText, wtoman(5000)), 'مبلغ پویای پاداش دعوت‌شده (۵٬۰۰۰ تومان) در پیام خوش‌آمدگویی B نمایش داده شد');
T::ok(str_contains($bAllText, 'امیر کاظمی'), 'نام دعوت‌کننده در پیام خوش‌آمدگویی B درج شد');
T::ok(str_contains($bAllText, 'شماره تماس'), 'الزام ثبت شماره تماس در چک‌لیست B نمایش داده شد');
T::ok(str_contains($bAllText, 'کانال رسمی رفرال'), 'الزام عضویت در کانال اجباری فعال در چک‌لیست B نمایش داده شد');

// هیچ‌یک از دو طرف نباید قبل از تکمیل شرایط پاداشی گرفته باشند!
T::eq(0, (int) $users->find((int) $userA['id'])['wallet_balance'], 'قبل از تکمیل شرایط: پاداش دعوت‌کننده A = ۰');
T::eq(0, (int) $users->find((int) $userB['id'])['wallet_balance'], 'قبل از تکمیل شرایط: پاداش دعوت‌شده B = ۰');
T::eq(null, $userB['qualified_at'], 'قبل از تکمیل شرایط: B هنوز واجد شرایط نشده است');

// ۳-۳) کاربر B شماره تماس را ارسال می‌کند، اما هنوز عضو کانال اجباری نشده است
$sentMessages = [];
$bot->handle([
    'update_id' => ++$updSeq,
    'message' => [
        'message_id' => 3,
        'from' => ['id' => 930002, 'first_name' => 'نگار', 'last_name' => 'مرادی', 'username' => 'negar_m'],
        'chat' => ['id' => 930002, 'type' => 'private'],
        'contact' => ['phone_number' => '+989123334455', 'user_id' => 930002, 'first_name' => 'نگار'],
    ],
]);
T::eq(0, (int) $users->find((int) $userA['id'])['wallet_balance'], 'بعد از شماره ولی قبل از عضویت کانال: پاداش A همچنان = ۰');
T::eq(0, (int) $users->find((int) $userB['id'])['wallet_balance'], 'بعد از شماره ولی قبل از عضویت کانال: پاداش B همچنان = ۰');

// ۳-۴) کاربر B بدون عضویت در کانال روی «بررسی عضویت» می‌زند -> رد می‌شود و پاداشی واریز نمی‌شود
$sentMessages = [];
$bot->handle([
    'update_id' => ++$updSeq,
    'callback_query' => [
        'id' => 'cb_not_joined',
        'from' => ['id' => 930002, 'first_name' => 'نگار'],
        'message' => ['message_id' => 4, 'chat' => ['id' => 930002, 'type' => 'private']],
        'data' => 'gate:check',
    ],
]);
T::eq(0, (int) $users->find((int) $userA['id'])['wallet_balance'], 'با عدم عضویت واقعی در کانال: پاداش A = ۰');
T::eq(0, (int) $users->find((int) $userB['id'])['wallet_balance'], 'با عدم عضویت واقعی در کانال: پاداش B = ۰');

// ۳-۵) کاربر B در کانال اجباری عضو می‌شود و روی «بررسی عضویت» کلیک می‌کند -> هر دو پاداش واریز می‌شوند!
$sentMessages = [];
$isChannelMember = true;
$bot->handle([
    'update_id' => ++$updSeq,
    'callback_query' => [
        'id' => 'cb_joined_ok',
        'from' => ['id' => 930002, 'first_name' => 'نگار'],
        'message' => ['message_id' => 5, 'chat' => ['id' => 930002, 'type' => 'private']],
        'data' => 'gate:check',
    ],
]);

$userA = $users->find((int) $userA['id']);
$userB = $users->find((int) $userB['id']);
T::ok(!empty($userB['qualified_at']), 'پس از تکمیل تمام شرایط، B واجد شرایط شد');
T::eq(10000, (int) $userA['wallet_balance'], 'دعوت‌کننده A دقیقاً ۱۰٬۰۰۰ تومان پاداش دریافت کرد');
T::eq(10000, (int) $userA['direct_earned'], 'ستون direct_earned دعوت‌کننده A = ۱۰٬۰۰۰ تومان');
T::eq(10000, (int) $userA['total_earned'], 'ستون total_earned دعوت‌کننده A = ۱۰٬۰۰۰ تومان');

T::eq(5000, (int) $userB['wallet_balance'], 'دعوت‌شده B دقیقاً ۵٬۰۰۰ تومان پاداش خوش‌آمدگویی دریافت کرد');
T::eq(5000, (int) $userB['welcome_earned'], 'ستون welcome_earned دعوت‌شده B = ۵٬۰۰۰ تومان');
T::eq(5000, (int) $userB['total_earned'], 'ستون total_earned دعوت‌شده B = ۵٬۰۰۰ تومان');

// بررسی تطبیق کامل دفتر کل مالی (Ledger Reconciliation) برای هر دو کاربر
T::eq(true, $wallets->reconcile((int) $userA['id'])['match'], 'دفتر کل کیف پول دعوت‌کننده A کاملاً منطبق است');
T::eq(true, $wallets->reconcile((int) $userB['id'])['match'], 'دفتر کل کیف پول دعوت‌شده B کاملاً منطبق است');
T::eq(5000, $wallets->rebuildBalance((int) $userB['id']), 'rebuildBalance دعوت‌شده B دقیقاً ۵٬۰۰۰ تومان برمی‌گرداند');

// بررسی ثبت تراکنش با ارتباط متقابل کاربران در دیتابیس
$txB = $db->first("SELECT * FROM wallet_transactions WHERE user_id = :u AND type = 'commission_welcome'", ['u' => (int) $userB['id']]);
T::ok($txB !== null && (int) $txB['related_user_id'] === (int) $userA['id'], 'تراکنش خوش‌آمدگویی B به شناسه دعوت‌کننده A متصل است');

// بررسی ارسال اعلان تلگرام مجزا به هر دو طرف
$aNotifText = implode("\n", array_column($messagesTo(930001), 'text'));
$bNotifText = implode("\n", array_column($messagesTo(930002), 'text'));
T::ok(str_contains($aNotifText, wtoman(10000)) && str_contains($aNotifText, 'پاداش معرفی'), 'اعلان واریز ۱۰٬۰۰۰ تومان به دعوت‌کننده A ارسال شد');
T::ok(str_contains($bNotifText, wtoman(5000)) && str_contains($bNotifText, 'خوش‌آمدگویی'), 'اعلان واریز ۵٬۰۰۰ تومان خوش‌آمدگویی به دعوت‌شده B ارسال شد');

// ۳-۶) تست سخت‌گیرانه جلوگیری از پرداخت تکراری (Duplicate Payout Protection)
$bot->handle([
    'update_id' => ++$updSeq,
    'callback_query' => [
        'id' => 'cb_joined_dup',
        'from' => ['id' => 930002, 'first_name' => 'نگار'],
        'message' => ['message_id' => 6, 'chat' => ['id' => 930002, 'type' => 'private']],
        'data' => 'gate:check',
    ],
]);
$bot->handle([
    'update_id' => ++$updSeq,
    'message' => [
        'message_id' => 7,
        'from' => ['id' => 930002, 'first_name' => 'نگار', 'last_name' => 'مرادی', 'username' => 'negar_m'],
        'chat' => ['id' => 930002, 'type' => 'private'],
        'text' => '/start ref_' . $userA['referral_code'],
    ],
]);
$users->maybeQualify($users->find((int) $userB['id']), false);
$users->maybeQualify($users->find((int) $userB['id']), true);
T::eq(10000, (int) $users->find((int) $userA['id'])['wallet_balance'], 'ضدتکرار: موجودی A با درخواست‌های تکراری تغییر نکرد (۱۰٬۰۰۰)');
T::eq(5000, (int) $users->find((int) $userB['id'])['wallet_balance'], 'ضدتکرار: موجودی B با درخواست‌های تکراری تغییر نکرد (۵٬۰۰۰)');

// =========================================================================
// ۴) پیام دعوت پویا و قابل ویرایش + بازتاب آنی تغییر مبالغ در پیام‌ها
// =========================================================================
$linkA = 'https://t.me/refrall_v130_bot?start=ref_' . $userA['referral_code'];
$defaultInvite = ReferralMessageService::renderInviteMessage($userA, $linkA);
T::ok(str_contains($defaultInvite, $linkA), 'پیام دعوت پیش‌فرض شامل لینک اختصاصی کاربر است');
T::ok(str_contains($defaultInvite, wtoman(10000)), 'پیام دعوت پیش‌فرض مبلغ پاداش دعوت‌کننده را نمایش می‌دهد');
T::ok(str_contains($defaultInvite, wtoman(5000)), 'پیام دعوت پیش‌فرض مبلغ پاداش دعوت‌شده را نمایش می‌دهد');

// ویرایش متن پیام دعوت توسط مدیر (با ترکیب متغیرهای انگلیسی و فارسی مثل نمونه کاربر)
SettingsService::set(
    'invite_message_text',
    "دوستت رو دعوت کن و جایزه بگیر! 🎁\n• تو {inviter_reward} پاداش می‌گیری.\n• دوستت هم [پاداش دعوت‌شده] هدیه خوش‌آمدگویی دریافت می‌کنه.\nلینک شروع:\n[لینک دعوت]",
    'messages'
);
SettingsService::set(
    'start_message_referred',
    "خوش آمدی {name}! با دعوت {referrer_name} آمدی. با تکمیل شرایط زیر {invitee_reward} هدیه می‌گیری:\n{requirements}",
    'messages'
);

// تغییر پویا مبالغ در پنل مدیریت (دعوت‌کننده: ۲۵٬۰۰۰ تومان — دعوت‌شده: ۱۲٬۰۰۰ تومان) + پورسانت چندلایه + شرط پروفایل و تأیید مدیر
SettingsService::set('force_channel_membership', '0', 'telegram');
SettingsService::set('contact_required', '0', 'telegram');
CommissionSettings::saveVersion([
    'direct_reward' => 25000,
    'direct_enabled' => 1,
    'invitee_reward' => 12000,
    'invitee_enabled' => 1,
    'multi_level_enabled' => 1,
    'max_levels' => 2,
    'levels' => [
        ['level' => 1, 'percent' => '10', 'enabled' => true],
        ['level' => 2, 'percent' => '5', 'enabled' => true],
    ],
    'percent_base' => 'direct_reward',
    'qualify_conditions' => ['joined', 'profile', 'approved'],
    'blocked_users_no_commission' => 1,
]);

// بدون تغییر قالب پیام، مبالغ جدید باید فوراً در پیام دعوت و متن اشتراک‌گذاری منعکس شوند
$updatedInvite = ReferralMessageService::renderInviteMessage($userA, $linkA);
$updatedShare = ReferralMessageService::renderShareText($userA, $linkA);
T::ok(str_contains($updatedInvite, wtoman(25000)) && str_contains($updatedInvite, wtoman(12000)), 'تغییر مبالغ پاداش بلافاصله در پیام دعوت سفارشی منعکس شد');
T::ok(str_contains($updatedInvite, $linkA), 'جایگذاری [لینک دعوت] در پیام سفارشی کار می‌کند');
T::ok(str_contains($updatedShare, wtoman(25000)) && str_contains($updatedShare, wtoman(12000)), 'متن اشتراک‌گذاری تلگرام هم مبالغ جدید را پویا نشان می‌دهد');

// =========================================================================
// ۵) سازگاری با چندلایه + شرط پروفایل + تأیید دستی مدیر + برگشت مالی (Reversal)
// =========================================================================
// کاربر C با لینک B وارد می‌شود (زنجیره A -> B -> C)، بدون نام خانوادگی
$sentMessages = [];
$bot->handle([
    'update_id' => ++$updSeq,
    'message' => [
        'message_id' => 8,
        'from' => ['id' => 930003, 'first_name' => 'سهراب', 'username' => 'sohrab_c'],
        'chat' => ['id' => 930003, 'type' => 'private'],
        'text' => '/start ref_' . $userB['referral_code'],
    ],
]);
$userC = $users->findByTelegramId(930003);
$cWelcome = implode("\n", array_column($messagesTo(930003), 'text'));
T::ok(str_contains($cWelcome, wtoman(12000)), 'پیام خوش‌آمد سفارشی C مبلغ جدید ۱۲٬۰۰۰ تومان را نشان داد');
T::ok(str_contains($cWelcome, 'تکمیل پروفایل') && str_contains($cWelcome, 'تأیید عضویت توسط مدیریت'), 'هر دو شرط پروفایل و تأیید مدیریت در راهنمای C آمدند');
T::eq(0, (int) $userC['wallet_balance'], 'قبل از تکمیل پروفایل و تأیید مدیر: پاداش C = ۰');
T::eq(5000, (int) $users->find((int) $userB['id'])['wallet_balance'], 'قبل از تکمیل شرایط C: موجودی B دست‌نخورده (۵٬۰۰۰)');

// C پروفایل (نام و نام خانوادگی) را تکمیل می‌کند، اما هنوز تأیید مدیر نشده -> همچنان صفر
$users->updateProfile((int) $userC['id'], 'سهراب', 'سپهری');
T::eq(0, (int) $users->find((int) $userC['id'])['wallet_balance'], 'بعد از تکمیل پروفایل ولی قبل از تأیید مدیر: پاداش C همچنان = ۰');

// مدیر عضویت C را تأیید می‌کند -> پاداش مستقیم لایه ۰ برای B (۲۵k)، خوش‌آمدگویی C (۱۲k) و لایه ۱ غیرمستقیم برای A (۲.۵k) همزمان واریز می‌شوند!
$qualifiedC = $users->approveVerification((int) $userC['id']);
T::eq(true, $qualifiedC, 'تأیید دستی مدیر باعث واجد شرایط شدن C و پرداخت دوطرفه شد');
T::eq(12000, (int) $users->find((int) $userC['id'])['wallet_balance'], 'دعوت‌شده C دقیقاً ۱۲٬۰۰۰ تومان دریافت کرد');
T::eq(5000 + 25000, (int) $users->find((int) $userB['id'])['wallet_balance'], 'دعوت‌کننده مستقیم B (لایه ۰) دقیقاً ۲۵٬۰۰۰ مستقیم دریافت کرد (جمع ۳۰٬۰۰۰)');
T::eq(10000 + 2500, (int) $users->find((int) $userA['id'])['wallet_balance'], 'معرف بالادستی A (لایه ۱ غیرمستقیم) مبلغ ۲٬۵۰۰ دریافت کرد و پاداش قبلی ۱۰٬۰۰۰ دست‌نخورده ماند');

// تست برگشت مالی (Reversal) رویداد C: باید هم پاداش دعوت‌شده و هم دعوت‌کننده و لایه غیرمستقیم دقیق برگردند
$eventC = $db->first("SELECT * FROM referral_events WHERE event_key = :k", ['k' => 'referral_qualified:' . (int) $userC['id']]);
$revertedCount = (new CommissionService($db))->revertEvent((int) $eventC['id'], 'تست برگشت دوطرفه');
T::eq(3, $revertedCount, 'هر ۳ ردیف پاداش (مستقیم لایه ۰، خوش‌آمدگویی، لایه ۱ غیرمستقیم) با موفقیت برگشت خوردند');
T::eq(0, (int) $users->find((int) $userC['id'])['wallet_balance'], 'موجودی C پس از برگشت = ۰');
T::eq(0, (int) $users->find((int) $userC['id'])['welcome_earned'], 'welcome_earned کاربر C پس از برگشت = ۰');
T::eq(5000, (int) $users->find((int) $userB['id'])['wallet_balance'], 'موجودی B به ۵٬۰۰۰ قبلی برگشت');
T::eq(10000, (int) $users->find((int) $userA['id'])['wallet_balance'], 'موجودی A به ۱۰٬۰۰۰ قبلی برگشت');
T::eq(true, $wallets->reconcile((int) $userC['id'])['match'], 'تطبیق دفتر کل C پس از برگشت ۱۰۰٪ صحیح است');

// تست خودمعرفی (Self-referral)
$selfRes = (new CommissionService($db))->processEvent([
    'event_key' => 'self_ref_test_v130',
    'type' => 'referral_qualified',
    'user_id' => (int) $userA['id'],
    'actor_id' => (int) $userA['id'],
    'base_amount' => 25000,
    'invitee_amount' => 12000,
]);
T::eq(0, $selfRes['total'], 'خودمعرفی نه پاداش دعوت‌کننده و نه پاداش دعوت‌شده پرداخت می‌کند');

// =========================================================================
// ۶) مدیریت پروفایل و ظاهر ربات (تصویر پروفایل، بیوگرافی Bio، توضیحات و نام)
// =========================================================================
$apiCalls = [];
// یک تصویر PNG معتبر ۱×۱ واقعی برای تست دانلود و آپلود
$tinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);

TelegramService::$fakeTransport = static function (string $method, array $params) use (&$apiCalls, $tinyPng): array {
    $apiCalls[$method] = $params;
    return match ($method) {
        'getMe' => ['ok' => true, 'result' => ['id' => 999888777, 'is_bot' => true, 'first_name' => 'رفرال‌پلاس رسمی', 'username' => 'refrall_v130_bot']],
        'getMyName' => ['ok' => true, 'result' => ['name' => 'رفرال‌پلاس رسمی']],
        'getMyShortDescription' => ['ok' => true, 'result' => ['short_description' => 'بیو فعلی ربات از تلگرام 🎁']],
        'getMyDescription' => ['ok' => true, 'result' => ['description' => 'توضیحات شروع ربات از تلگرام']],
        'getUserProfilePhotos' => ['ok' => true, 'result' => ['total_count' => 1, 'photos' => [[['file_id' => 'PHOTO_FILE_ID_V130', 'width' => 640, 'height' => 640]]]]],
        'getFile' => ['ok' => true, 'result' => ['file_id' => 'PHOTO_FILE_ID_V130', 'file_path' => 'photos/bot_avatar.png']],
        '__downloadFile' => ['ok' => true, 'bytes' => $tinyPng],
        'setMyName', 'setMyShortDescription', 'setMyDescription', 'setMyProfilePhoto', 'removeMyProfilePhoto' => ['ok' => true, 'result' => true],
        default => ['ok' => true, 'result' => true],
    };
};

$tg = new TelegramService(null, $db);

// ۶-۱) تنظیم نام، بیوگرافی (Bio) و توضیحات کامل
T::ok($tg->setMyName('ربات جایزه دوطرفه')['ok'] === true, 'setMyName موفق');
T::eq('ربات جایزه دوطرفه', $apiCalls['setMyName']['name'] ?? '', 'نام ربات دقیق به API تلگرام ارسال شد');

$bioText = 'دوستت رو دعوت کن: ۱۰ هزار تومان برای تو + ۵ هزار تومان هدیه خوش‌آمدگویی دوستت! 🎁';
T::ok($tg->setMyShortDescription($bioText)['ok'] === true, 'setMyShortDescription (Bio) موفق');
T::eq($bioText, $apiCalls['setMyShortDescription']['short_description'] ?? '', 'متن بیوگرافی (Bio) دقیق به API تلگرام ارسال شد');

$descText = "به ربات رسمی دعوت دوستان خوش آمدید!\nروی Start بزنید و هدیه عضویت بگیرید.";
T::ok($tg->setMyDescription($descText)['ok'] === true, 'setMyDescription موفق');
T::eq($descText, $apiCalls['setMyDescription']['description'] ?? '', 'توضیحات معرفی ربات دقیق به API تلگرام ارسال شد');

// ۶-۲) تنظیم و حذف تصویر پروفایل ربات (InputProfilePhotoStatic)
$tmpAvatar = sys_get_temp_dir() . '/rb_test_bot_avatar_' . getmypid() . '.png';
file_put_contents($tmpAvatar, $tinyPng);
$photoRes = $tg->setMyProfilePhoto($tmpAvatar);
@unlink($tmpAvatar);
T::ok($photoRes['ok'] === true, 'setMyProfilePhoto موفق');
$decodedPhotoParam = json_decode((string) ($apiCalls['setMyProfilePhoto']['photo'] ?? ''), true);
T::eq(['type' => 'static', 'photo' => 'attach://profile_photo'], $decodedPhotoParam, 'ساختار InputProfilePhotoStatic تلگرام دقیق است');
T::eq('profile_photo', $apiCalls['setMyProfilePhoto']['_file_field'] ?? '', 'فیلد multipart تصویر پروفایل دقیق است');

$rmRes = $tg->removeMyProfilePhoto();
T::ok($rmRes['ok'] === true && isset($apiCalls['removeMyProfilePhoto']), 'removeMyProfilePhoto موفق');

// ۶-۳) استعلام اطلاعات فعلی ربات و دانلود امن تصویر بدون افشای توکن
$shortFetched = $tg->getMyShortDescription();
T::eq('بیو فعلی ربات از تلگرام 🎁', $shortFetched['result']['short_description'] ?? '', 'استعلام بیوگرافی فعلی ربات کار می‌کند');
$photosFetched = $tg->getUserProfilePhotos(999888777, 1);
T::eq('PHOTO_FILE_ID_V130', $photosFetched['result']['photos'][0][0]['file_id'] ?? '', 'استعلام تصویر پروفایل فعلی ربات کار می‌کند');
$dlBytes = $tg->downloadFile('photos/bot_avatar.png');
T::eq($tinyPng, $dlBytes, 'دانلود امن تصویر پروفایل در سمت سرور کار می‌کند');
T::eq(null, $tg->downloadFile('../etc/passwd'), 'مسیرهای ناامن در downloadFile مسدود می‌شوند');

// ۶-۴) بررسی وجود کنترلر، ویو و مسیرهای پنل مدیریت پروفایل ربات
T::ok(class_exists(\RB\Controllers\Admin\BotProfileController::class), 'کنترلر مستقل BotProfileController بارگذاری شد');
T::ok(is_file(RB_ROOT . '/views/admin/bot_profile/index.php'), 'ویوی مستقل پروفایل و ظاهر ربات موجود است');
$layoutCode = (string) file_get_contents(RB_ROOT . '/views/layouts/app.php');
T::ok(str_contains($layoutCode, '/admin/bot-profile'), 'لینک مستقل پروفایل و ظاهر ربات در منوی پنل مدیریت ثبت شده است');
$routesCode = (string) file_get_contents(RB_ROOT . '/src/routes.php');
T::ok(
    str_contains($routesCode, "'/admin/bot-profile'")
    && str_contains($routesCode, "'/admin/bot-profile/save'")
    && str_contains($routesCode, "'/admin/bot-profile/photo'")
    && str_contains($routesCode, "'/admin/bot-profile/photo/remove'")
    && str_contains($routesCode, "'/admin/bot-profile/sync'"),
    'تمام مسیرهای مدیریت تصویر پروفایل، بیوگرافی و همگام‌سازی ربات ثبت شده‌اند'
);

// پاک‌سازی ترنسپورت تست
TelegramService::$fakeTransport = null;
SettingsService::set('force_channel_membership', '0', 'telegram');
SettingsService::set('contact_required', '0', 'telegram');

T::summary();

<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Bot\BotHandler;
use RB\Services\SettingsService;
use RB\Services\TelegramService;
use RB\Services\UserService;
use RB\Services\WalletService;
use RB\Services\WithdrawalService;
use RB\Support\Crypto;
use RB\Support\Database;

T::suite('برداشت دکمه‌ای: پذیرش و حفظ ورودی آزاد کارت');
$db = Database::instance();
\RB\Support\RateLimiter::reset('bot:telegram:931990001');
SettingsService::set('withdrawal_enabled', '1');
SettingsService::set('min_withdraw_amount', '30000');
SettingsService::set('max_open_withdrawals', '1');
SettingsService::set('max_daily_withdrawal', '0');
SettingsService::set('force_channel_membership', '0');
SettingsService::set('contact_required', '0');
SettingsService::flush();
$botId = (int) ($db->value('SELECT id FROM telegram_settings ORDER BY id LIMIT 1') ?: 0);
$settings = ['bot_id'=>123456789, 'bot_username'=>'test_free_card_bot', 'bot_token_enc'=>Crypto::encrypt('123456789:AAFakeTokenForTestingABCDEF123456'), 'connection_status'=>'connected', 'updated_at'=>now()];
if ($botId) $db->update('telegram_settings', $settings, 'id=:id', ['id'=>$botId]);
else $db->insert('telegram_settings', $settings + ['created_at'=>now()]);
TelegramService::forgetSettingsCache();
$sent = [];
TelegramService::$fakeTransport = static function ($method, $params) use (&$sent): array {
    $sent[] = ['method'=>$method, 'params'=>$params];
    return ['ok'=>true, 'result'=>['message_id'=>count($sent)]];
};
$user = (new UserService($db))->registerFromTelegram(['id'=>931990001,'first_name'=>'کاربر','last_name'=>'تست'])['user'];
$uid = (int) $user['id'];
(new WalletService($db))->credit($uid, 'adjustment', 500000, ['idempotency_key'=>'free-card-credit','description'=>'آزمون برداشت']);
$handler = new BotHandler($db);
$updateId = 9319900;
$send = static function (string $text) use ($handler, &$updateId): void {
    $updateId++;
    $handler->handle(['update_id'=>$updateId, 'message'=>['message_id'=>$updateId,'date'=>time(),
        'chat'=>['id'=>931990001,'type'=>'private'],
        'from'=>['id'=>931990001,'first_name'=>'کاربر','last_name'=>'تست'], 'text'=>$text]]);
};
$cards = ['123', '1111111111111111', '6037997512345671', '۶۰۳۷-۹۹۷۵-۱۲۳۴-۵۶۷۱', 'IR062960000000100324200001', 'شماره 12 با متن', str_repeat('۶۰۳۷-', 50)];
$service = new WithdrawalService($db);
foreach ($cards as $i => $raw) {
    $send('🏦 درخواست برداشت');
    $session = $db->first('SELECT * FROM bot_sessions WHERE user_id=:id', ['id'=>$uid]);
    T::eq('withdraw_amount', $session['step'] ?? null, 'دکمه برداشت مرحله مبلغ را باز می‌کند');
    $send('30000');
    $send($raw);
    $session = $db->first('SELECT * FROM bot_sessions WHERE user_id=:id', ['id'=>$uid]);
    $payload = json_decode((string) ($session['payload'] ?? ''), true) ?: [];
    T::eq('withdraw_holder', $session['step'] ?? null, 'ورودی آزاد بدون خطا به مرحله نام صاحب کارت می‌رود');
    T::eq($raw, Crypto::decrypt($payload['card_enc'] ?? null), 'ورودی اصلی در نشست رمزگذاری و بدون تغییر است');
    T::ok(!str_contains((string) $session['payload'], $raw), 'ورودی به صورت آشکار در نشست ثبت نمی‌شود');
    $log = (string) $db->value("SELECT payload FROM telegram_messages WHERE user_id=:id AND direction='in' ORDER BY id DESC LIMIT 1", ['id'=>$uid]);
    T::ok(!str_contains($log, $raw), 'ورودی در لاگ پیام ذخیره نمی‌شود');
    $send('علی رضایی');
    $request = $db->first('SELECT * FROM withdrawal_requests WHERE user_id=:id ORDER BY id DESC LIMIT 1', ['id'=>$uid]);
    T::eq('reviewing', $request['status'] ?? null, 'درخواست برای بررسی مدیر ثبت می‌شود');
    T::eq($raw, WithdrawalService::decryptedCard($request), 'مدیر ورودی دقیق کاربر را مشاهده می‌کند');
    T::eq(30000, (int) ($request['amount'] ?? 0), 'مبلغ درست رزرو شده است');
    T::eq(true, (new WalletService($db))->reconcile($uid)['match'], 'دفتر مالی با کیف پول مطابقت دارد');
    $service->cancelByUser((int) $request['id'], $uid);
}
T::eq(500000, (int) (new WalletService($db))->summary($uid)['balance'], 'پس از لغو درخواست‌های آزمایشی، موجودی اولیه بازگشته است');
T::eq(false, WithdrawalService::validateCard(" \t")['valid'], 'ورودی خالی رد می‌شود');
T::throws(fn() => $service->create($uid, 600000, '123', 'علی رضایی'), 'محدودیت موجودی همچنان برقرار است');
TelegramService::$fakeTransport = null;
\RB\Support\RateLimiter::reset('bot:telegram:931990001');
exit(T::summary());

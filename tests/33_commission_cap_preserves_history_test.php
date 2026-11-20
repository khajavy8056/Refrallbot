<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\CommissionService;
use RB\Services\CommissionSettings;
use RB\Services\UserService;
use RB\Support\Database;

T::suite('سقف پرداخت نباید سوابق و موجودی قبلی را بازنویسی کند');
$db = Database::instance();
$users = new UserService($db);
CommissionSettings::saveVersion(['direct_reward'=>1000, 'direct_enabled'=>1, 'multi_level_enabled'=>0, 'max_levels'=>1, 'qualify_conditions'=>['joined']]);
$referrer = $users->registerFromTelegram(['id'=>83300001, 'first_name'=>'Referrer'])['user'];
$uid = (int)$referrer['id'];
$first = $users->registerFromTelegram(['id'=>83300002, 'first_name'=>'First'], $referrer['referral_code'])['user'];
$event = (int)$db->value('SELECT id FROM referral_events WHERE event_key = :k', ['k'=>'referral_qualified:'.$first['id']]);
(new CommissionService($db))->revertEvent($event, 'test');
T::eq(0, (int)$users->find($uid)['direct_earned'], 'درآمد رویداد برگشت‌خورده صفر است');
$report=\RB\Services\StatsService::layerFinancialReport($db);
T::eq(0, (int)$report['layers'][0]['tx_count'], 'پرداخت برگشت‌خورده در تعداد پرداخت‌ها شمارش نمی‌شود');
T::eq(0, (int)$report['layers'][0]['paid_amount'], 'مبلغ برگشت‌خورده پرداخت‌شده محسوب نمی‌شود');
// A discrepancy can exist on upgraded installations. A new payout must not silently
// overwrite it; reconciliation is a separate, administrator-reviewed operation.
$db->run('UPDATE users SET wallet_balance = 5000 WHERE id = :id', ['id'=>$uid]);
$db->run('UPDATE wallets SET balance = 5000 WHERE user_id = :id', ['id'=>$uid]);
CommissionSettings::saveVersion(['direct_reward'=>1000, 'direct_enabled'=>1, 'multi_level_enabled'=>0, 'max_levels'=>1, 'max_payout_per_event'=>600, 'qualify_conditions'=>['joined']]);
$second = $users->registerFromTelegram(['id'=>83300003, 'first_name'=>'Second'], $referrer['referral_code'])['user'];
$after = $users->find($uid);
T::eq(5600, (int)$after['wallet_balance'], 'موجودی پیشین صرفاً به اندازه پرداخت جدید تغییر می‌کند');
T::eq(5600, (int)$db->value('SELECT balance FROM wallets WHERE user_id = :id', ['id'=>$uid]), 'موجودی کیف پول نیز حفظ می‌شود');
T::eq(600, (int)$after['direct_earned'], 'درآمد برگشت‌خورده دوباره محسوب نمی‌شود');
T::eq(600, (int)$after['total_earned'], 'درآمد کل تنها پرداخت جدید را شامل می‌شود');
$tx = $db->first("SELECT * FROM wallet_transactions WHERE user_id = :u AND type = 'commission_direct' ORDER BY id DESC LIMIT 1", ['u'=>$uid]);
T::eq(600, (int)$tx['amount'], 'مبلغ دفتر مطابق سقف است');
T::eq(5600, (int)$tx['balance_after'], 'مانده ثبت‌شده با پرداخت نهایی تطبیق دارد');
T::eq(2, (int)$db->value("SELECT COUNT(*) FROM referral_events WHERE type = 'referral_qualified'"), 'هر دعوت فقط یک رویداد دارد');
$again = (new CommissionService($db))->processEvent(['event_key'=>'referral_qualified:'.$second['id'], 'user_id'=>(int)$second['id'], 'actor_id'=>$uid]);
T::eq(true, $again['duplicated'], 'پردازش مجدد رویداد پرداخت تکراری ایجاد نمی‌کند');
T::eq(5600, (int)$users->find($uid)['wallet_balance'], 'موجودی پس از تکرار ثابت است');

// Both direct and welcome are trimmed when the event cap is lower than
// their sum; an empty daily budget produces zero, without breaking the ledger.
CommissionSettings::saveVersion(['direct_reward'=>1000, 'direct_enabled'=>1, 'invitee_reward'=>800, 'invitee_enabled'=>1, 'multi_level_enabled'=>0, 'max_levels'=>1, 'max_payout_per_event'=>500, 'qualify_conditions'=>['joined']]);
$parent = $users->registerFromTelegram(['id'=>83300004, 'first_name'=>'Parent'])['user'];
$child = $users->registerFromTelegram(['id'=>83300005, 'first_name'=>'Child'], $parent['referral_code'])['user'];
$pid=(int)$parent['id']; $cid=(int)$child['id'];
T::eq(500, (int)$users->find($pid)['wallet_balance'], 'سقف نخست پاداش مستقیم را حفظ می‌کند');
T::eq(0, (int)$users->find($cid)['wallet_balance'], 'پاداش خوش‌آمد در سقف صفر می‌شود');
T::eq(true, (new \RB\Services\WalletService($db))->reconcile($pid)['match'], 'دفتر دعوت‌کننده تطبیق دارد');
T::eq(true, (new \RB\Services\WalletService($db))->reconcile($cid)['match'], 'دفتر دعوت‌شده تطبیق دارد');
T::eq(500, (int)$db->value('SELECT total_in FROM wallets WHERE user_id = :u', ['u'=>$pid]), 'جمع ورودی سقف‌شده است');
T::eq(0, (int)$db->value('SELECT total_in FROM wallets WHERE user_id = :u', ['u'=>$cid]), 'جمع ورودی خوش‌آمد صفر است');
exit(T::summary());

<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

use RB\Services\CommissionSettings;
use RB\Services\UserService;
use RB\Services\WalletService;
use RB\Support\Database;

T::suite('سقف روزانه به ازای هر کاربر');
$db = Database::instance();
$users = new UserService($db);
CommissionSettings::saveVersion(['direct_reward'=>1000,'direct_enabled'=>1,'multi_level_enabled'=>0,'max_levels'=>1,'daily_payout_cap'=>1500,'qualify_conditions'=>['joined']]);
$a = $users->registerFromTelegram(['id'=>83410001,'first_name'=>'A'])['user'];
$b = $users->registerFromTelegram(['id'=>83410002,'first_name'=>'B'])['user'];
$users->registerFromTelegram(['id'=>83410003,'first_name'=>'C'], $a['referral_code']);
$users->registerFromTelegram(['id'=>83410004,'first_name'=>'D'], $b['referral_code']);
T::eq(1000, (int)$users->find((int)$a['id'])['wallet_balance'], 'کاربر اول پاداش خود را می‌گیرد');
T::eq(1000, (int)$users->find((int)$b['id'])['wallet_balance'], 'پرداخت کاربر اول سهم کاربر دوم را کم نمی‌کند');
$users->registerFromTelegram(['id'=>83410005,'first_name'=>'E'], $a['referral_code']);
$users->registerFromTelegram(['id'=>83410006,'first_name'=>'F'], $a['referral_code']);
T::eq(1500, (int)$users->find((int)$a['id'])['wallet_balance'], 'سقف برای هر کاربر رعایت می‌شود');
T::eq(1000, (int)$users->find((int)$b['id'])['wallet_balance'], 'سقف کاربر دیگر دست‌نخورده است');
T::eq(true, (new WalletService($db))->reconcile((int)$a['id'])['match'], 'دفتر کاربر اول تطبیق دارد');
T::eq(true, (new WalletService($db))->reconcile((int)$b['id'])['match'], 'دفتر کاربر دوم تطبیق دارد');
T::eq([1000,500], array_map('intval', array_column($db->all("SELECT amount FROM referral_commissions WHERE beneficiary_id = :u AND type = 'direct' ORDER BY id", ['u'=>(int)$a['id']]), 'amount')), 'تنها مبالغ مجاز ثبت می‌شوند');
exit(T::summary());

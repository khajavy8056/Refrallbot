<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use RB\Support\Database;
use RB\Support\View;

$db = Database::instance();
// مدیر و نقش
$roleId = (int) $db->value("SELECT id FROM admin_roles WHERE slug='superadmin'") ?: $db->insert('admin_roles', ['name'=>'مدیر کل','slug'=>'superadmin','permissions'=>'["*"]','created_at'=>now(),'updated_at'=>now()]);
$adminId = $db->insert('admins', ['name'=>'مدیر','username'=>'smoke','password_hash'=>password_hash('Str0ngPassw0rd!', PASSWORD_DEFAULT),'role_id'=>$roleId,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
$db->insert('system_settings', ['key'=>'installed_at','value'=>now(),'group_name'=>'system','created_at'=>now(),'updated_at'=>now()]);

$views = [
  'admin/dashboard' => ['title'=>'داشبورد','overview'=>['bot_status'=>'connected','bot_connected'=>true,'webhook_active'=>true,'total_users'=>0,'new_today'=>0,'active_users'=>0,'new_week'=>0,'total_referrals'=>0,'qualified_referrals'=>0,'total_commissions'=>0,'total_earned'=>0,'pending_withdrawals_amount'=>0,'pending_withdrawals_count'=>0,'total_withdrawn'=>0,'wallet_total_balance'=>0,'suspicious_users'=>0,'pending_security_events'=>0],'days'=>7,'telegram'=>['bot_username'=>'t'],'labels'=>['۱','۲'],'growth'=>[0,1],'referrals'=>[0,1],'payouts'=>[0,1],'withdrawalSeries'=>[0,1],'recentUsers'=>[],'topUsers'=>[],'activity'=>[],'commissionLevels'=>[],'settings'=>RB\Services\CommissionSettings::defaults()],
  'admin/network/index' => ['title'=>'شبکه','roots'=>[],'topInviters'=>[],'q'=>'','depth'=>8,'stats'=>['total_relations'=>0,'with_refs'=>0,'max_depth'=>0,'orphans'=>0]],
  'admin/withdrawals/index' => ['title'=>'برداشت','result'=>['rows'=>[],'total'=>0,'sum'=>0,'page'=>1,'per_page'=>20],'filters'=>[],'stats'=>['counts'=>[],'sums'=>[],'pending_amount'=>0,'paid_amount'=>0,'paid_count'=>0],'fromRaw'=>'','toRaw'=>''],
  'admin/commissions/index' => ['title'=>'پورسانت','settings'=>RB\Services\CommissionSettings::defaults(),'versions'=>[],'stats'=>['total_commissions'=>0,'by_level'=>[],'events'=>0]],
  'admin/settings/index' => ['title'=>'تنظیمات','settings'=>RB\Services\SettingsService::all(),'commission'=>RB\Services\CommissionSettings::defaults(),'metrics'=>RB\Services\RankingService::METRICS],
  'admin/telegram/index' => ['title'=>'ربات','settings'=>[],'maskedToken'=>null,'suggestedWebhook'=>'https://x/webhook','webhookInfo'=>null,'menus'=>[],'counts'=>['messages_sent'=>0,'messages_failed'=>0,'updates'=>0],'lastMessages'=>[]],
  'admin/channels/index' => ['title'=>'کانال','channels'=>[],'enforcement'=>false,'health'=>[],'contactRequired'=>false],
  'admin/broadcast/index' => ['title'=>'ارسال','campaigns'=>[],'segments'=>RB\Services\BroadcastService::SEGMENTS,'counts'=>['all'=>0,'active'=>0,'with_phone'=>0,'with_refs'=>0,'no_refs'=>0,'with_balance'=>0,'suspicious'=>0]],
  'admin/campaigns/index' => ['title'=>'کمپین','campaigns'=>[],'stats'=>[],'types'=>RB\Services\CampaignService::TYPES],
  'admin/campaigns/form' => ['title'=>'کمپین','campaign'=>null,'types'=>RB\Services\CampaignService::TYPES],
  'admin/reports/index' => ['title'=>'گزارش','type'=>'transactions','types'=>RB\Controllers\Admin\ReportController::TYPES,'rows'=>[],'columns'=>['amount'=>['label'=>'مبلغ']],'totals'=>[],'filters'=>[]],
  'admin/security/index' => ['title'=>'امنیت','events'=>[],'statusFilter'=>'','suspiciousUsers'=>[],'counts'=>['open'=>0,'high'=>0,'suspicious'=>0,'card_reuse'=>0]],
  'admin/security/logs' => ['title'=>'لاگ','logs'=>[],'total'=>0,'page'=>1,'pages'=>1,'q'=>'','adminId'=>0,'admins'=>[]],
  'admin/support/index' => ['title'=>'پشتیبانی','messages'=>[],'statusFilter'=>'open','openCount'=>0],
  'admin/admins/index' => ['title'=>'مدیران','admins'=>[]],
  'admin/backup/index' => ['title'=>'پشتیبان','files'=>[],'driver'=>'sqlite','tables'=>30,'stats'=>['users'=>0,'transactions'=>0,'withdrawals'=>0]],
  'miniapp/landing' => ['title'=>'پنل','appName'=>'تست','miniappUrl'=>''],
];
$_SESSION['admin_id'] = $adminId;
$_SESSION['admin_last_seen'] = time();
$fail = 0;
foreach ($views as $view => $data) {
    View::share('currentAdmin', ['id'=>$adminId,'name'=>'مدیر','role_name'=>'مدیر کل','role_slug'=>'superadmin']);
    View::share('flashMessages', []);
    View::share('navBadges', []);
    View::share('currentPath', '/admin');
    try {
        $layout = str_starts_with($view, 'miniapp/') ? 'miniapp' : 'app';
        $html = View::render($view, $data, $layout);
        $len = strlen($html);
        $bad = str_contains($html, 'Warning:') || str_contains($html, 'Notice:') || str_contains($html, 'Fatal error');
        printf("%-32s %6d bytes %s\n", $view, $len, $bad ? '✘ هشدار PHP' : '✔');
        if ($bad) { $fail++; }
    } catch (Throwable $e) {
        $fail++;
        printf("%-32s ✘ %s\n", $view, $e->getMessage());
    }
}
echo $fail === 0 ? "همه قالب‌ها سالم رندر شدند\n" : "خطا در $fail قالب\n";
exit($fail === 0 ? 0 : 1);

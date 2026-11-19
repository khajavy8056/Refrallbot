<?php
declare(strict_types=1);

use RB\Controllers\Admin;
use RB\Controllers\CronController;
use RB\Controllers\InstallerController;
use RB\Controllers\MiniApp;
use RB\Controllers\Webhook;
use RB\Http\Router;

/**
 * تعریف تمام مسیرهای برنامه (نسخه چندپیام‌رسانی v1.4)
 */
return static function (Router $router): void {

    // ------------------------------------------------ نصب اولیه
    $router->get('/install', [InstallerController::class, 'index']);
    $router->post('/install/test-database', [InstallerController::class, 'testDatabase']);
    $router->post('/install/database', [InstallerController::class, 'database']);
    $router->post('/install/migrate', [InstallerController::class, 'migrate']);
    $router->post('/install/admin', [InstallerController::class, 'createAdmin']);
    $router->post('/install/settings', [InstallerController::class, 'settings']);
    $router->post('/install/finish', [InstallerController::class, 'finish']);

    // ------------------------------------------------ صفحه اصلی، درگاه هوشمند دعوت و سرو امن بنرها
    $router->get('/', [MiniApp\HomeController::class, 'landing']);
    $router->get('/r/{code}', [Webhook\MessengerWebhookController::class, 'referralGateway']);
    $router->get('/media/banner/{id}', [Webhook\MessengerWebhookController::class, 'banner']);

    // ------------------------------------------------ پنل مدیریت
    $router->get('/admin/login', [Admin\AuthController::class, 'showLogin'], ['admin_guest']);
    $router->post('/admin/login', [Admin\AuthController::class, 'login'], ['admin_guest']);
    $router->get('/admin/logout', [Admin\AuthController::class, 'logout']);
    $router->post('/admin/logout', [Admin\AuthController::class, 'logout'], ['admin']);
    $router->get('/admin', [Admin\DashboardController::class, 'index'], ['admin']);
    $router->get('/admin/api/chart', [Admin\DashboardController::class, 'chart'], ['admin']);

    // کاربران و شبکه
    $router->get('/admin/users', [Admin\UserController::class, 'index'], ['admin']);
    $router->get('/admin/users/{id}', [Admin\UserController::class, 'show'], ['admin']);
    $router->post('/admin/users/{id}/status', [Admin\UserController::class, 'updateStatus'], ['admin', 'csrf']);
    $router->post('/admin/users/{id}/approve', [Admin\UserController::class, 'approve'], ['admin', 'csrf']);
    $router->post('/admin/users/{id}/referrer', [Admin\UserController::class, 'changeReferrer'], ['admin', 'csrf']);
    $router->post('/admin/users/{id}/wallet', [Admin\UserController::class, 'adjustWallet'], ['admin', 'csrf']);
    $router->get('/admin/network', [Admin\NetworkController::class, 'index'], ['admin']);
    $router->get('/admin/network/{id}', [Admin\NetworkController::class, 'show'], ['admin']);
    $router->get('/admin/api/network/{id}', [Admin\NetworkController::class, 'treeJson'], ['admin']);

    // برداشت‌ها
    $router->get('/admin/withdrawals', [Admin\WithdrawalController::class, 'index'], ['admin']);
    $router->get('/admin/withdrawals/{id}', [Admin\WithdrawalController::class, 'show'], ['admin']);
    $router->post('/admin/withdrawals/{id}/approve', [Admin\WithdrawalController::class, 'approve'], ['admin', 'csrf']);
    $router->post('/admin/withdrawals/{id}/reject', [Admin\WithdrawalController::class, 'reject'], ['admin', 'csrf']);
    $router->post('/admin/withdrawals/{id}/pay', [Admin\WithdrawalController::class, 'pay'], ['admin', 'csrf']);

    // تنظیمات پورسانت و لایه‌ها
    $router->get('/admin/commissions', [Admin\CommissionController::class, 'index'], ['admin']);
    $router->post('/admin/commissions', [Admin\CommissionController::class, 'save'], ['admin', 'csrf']);

    // تنظیمات سیستم / برداشت / ربات / حالت نمایش
    $router->get('/admin/settings', [Admin\SettingsController::class, 'index'], ['admin']);
    $router->post('/admin/settings/general', [Admin\SettingsController::class, 'saveGeneral'], ['admin', 'csrf']);
    $router->post('/admin/settings/withdrawal', [Admin\SettingsController::class, 'saveWithdrawal'], ['admin', 'csrf']);
    $router->post('/admin/settings/start-message', [Admin\SettingsController::class, 'saveStartMessage'], ['admin', 'csrf']);
    $router->post('/admin/settings/gates', [Admin\SettingsController::class, 'saveGates'], ['admin', 'csrf']);
    $router->post('/admin/settings/ranking', [Admin\SettingsController::class, 'saveRanking'], ['admin', 'csrf']);

    // مدیریت یکپارچه پیام‌رسان‌ها (تلگرام، بله، روبیکا، ایتا)
    $router->get('/admin/messengers', [Admin\MessengerController::class, 'index'], ['admin']);
    $router->post('/admin/messengers/{platform}/save', [Admin\MessengerController::class, 'save'], ['admin', 'csrf']);
    $router->post('/admin/messengers/{platform}/toggle', [Admin\MessengerController::class, 'toggle'], ['admin', 'csrf']);
    $router->post('/admin/messengers/{platform}/test', [Admin\MessengerController::class, 'test'], ['admin', 'csrf']);
    $router->post('/admin/messengers/{platform}/webhook/set', [Admin\MessengerController::class, 'setWebhook'], ['admin', 'csrf']);
    $router->post('/admin/messengers/{platform}/webhook/delete', [Admin\MessengerController::class, 'deleteWebhook'], ['admin', 'csrf']);
    $router->post('/admin/messengers/{platform}/commands', [Admin\MessengerController::class, 'syncCommands'], ['admin', 'csrf']);
    $router->post('/admin/messengers/{platform}/disconnect', [Admin\MessengerController::class, 'disconnect'], ['admin', 'csrf']);

    // استودیوی محتوای دعوت و بنرهای تبلیغاتی
    $router->get('/admin/invite-content', [Admin\InviteContentController::class, 'index'], ['admin']);
    $router->post('/admin/invite-content/messages/save', [Admin\InviteContentController::class, 'saveMessage'], ['admin', 'csrf']);
    $router->post('/admin/invite-content/messages/{id}/toggle', [Admin\InviteContentController::class, 'toggleMessage'], ['admin', 'csrf']);
    $router->post('/admin/invite-content/messages/{id}/default', [Admin\InviteContentController::class, 'defaultMessage'], ['admin', 'csrf']);
    $router->post('/admin/invite-content/messages/{id}/delete', [Admin\InviteContentController::class, 'deleteMessage'], ['admin', 'csrf']);
    $router->post('/admin/invite-content/banners/save', [Admin\InviteContentController::class, 'saveBanner'], ['admin', 'csrf']);
    $router->post('/admin/invite-content/banners/{id}/toggle', [Admin\InviteContentController::class, 'toggleBanner'], ['admin', 'csrf']);
    $router->post('/admin/invite-content/banners/{id}/default', [Admin\InviteContentController::class, 'defaultBanner'], ['admin', 'csrf']);
    $router->post('/admin/invite-content/banners/{id}/delete', [Admin\InviteContentController::class, 'deleteBanner'], ['admin', 'csrf']);

    // ربات تلگرام و منوهای ربات (سازگاری کامل با مسیرهای قبلی)
    $router->get('/admin/telegram', [Admin\TelegramController::class, 'index'], ['admin']);
    $router->post('/admin/telegram/save', [Admin\TelegramController::class, 'saveToken'], ['admin', 'csrf']);
    $router->post('/admin/telegram/test', [Admin\TelegramController::class, 'test'], ['admin', 'csrf']);
    $router->post('/admin/telegram/disconnect', [Admin\TelegramController::class, 'disconnect'], ['admin', 'csrf']);
    $router->post('/admin/telegram/webhook/set', [Admin\TelegramController::class, 'setWebhook'], ['admin', 'csrf']);
    $router->post('/admin/telegram/webhook/delete', [Admin\TelegramController::class, 'deleteWebhook'], ['admin', 'csrf']);
    $router->post('/admin/telegram/mode', [Admin\TelegramController::class, 'saveMode'], ['admin', 'csrf']);
    $router->post('/admin/telegram/menu/sync', [Admin\TelegramController::class, 'syncMenu'], ['admin', 'csrf']);
    $router->get('/admin/telegram/menus', [Admin\TelegramController::class, 'menus'], ['admin']);
    $router->post('/admin/telegram/menus/save', [Admin\TelegramController::class, 'saveMenus'], ['admin', 'csrf']);

    // پروفایل و ظاهر ربات‌ها (چندپیام‌رسانی)
    $router->get('/admin/bot-profile', [Admin\BotProfileController::class, 'index'], ['admin']);
    $router->get('/admin/bot-profile/photo', [Admin\BotProfileController::class, 'photo'], ['admin']);
    $router->post('/admin/bot-profile/sync', [Admin\BotProfileController::class, 'sync'], ['admin', 'csrf']);
    $router->post('/admin/bot-profile/save', [Admin\BotProfileController::class, 'save'], ['admin', 'csrf']);
    $router->post('/admin/bot-profile/photo', [Admin\BotProfileController::class, 'uploadPhoto'], ['admin', 'csrf']);
    $router->post('/admin/bot-profile/photo/remove', [Admin\BotProfileController::class, 'removePhoto'], ['admin', 'csrf']);

    // کانال‌های اجباری (چندپیام‌رسانی)
    $router->get('/admin/channels', [Admin\ChannelController::class, 'index'], ['admin']);
    $router->post('/admin/channels', [Admin\ChannelController::class, 'save'], ['admin', 'csrf']);
    $router->post('/admin/channels/{id}/delete', [Admin\ChannelController::class, 'delete'], ['admin', 'csrf']);
    $router->post('/admin/channels/reorder', [Admin\ChannelController::class, 'reorder'], ['admin', 'csrf']);

    // پیام همگانی (چندپیام‌رسانی)
    $router->get('/admin/broadcast', [Admin\BroadcastController::class, 'index'], ['admin']);
    $router->post('/admin/broadcast/preview', [Admin\BroadcastController::class, 'preview'], ['admin', 'csrf']);
    $router->post('/admin/broadcast/send', [Admin\BroadcastController::class, 'send'], ['admin', 'csrf']);
    $router->post('/admin/broadcast/test', [Admin\BroadcastController::class, 'test'], ['admin', 'csrf']);
    $router->post('/admin/broadcast/{id}/cancel', [Admin\BroadcastController::class, 'cancel'], ['admin', 'csrf']);
    $router->get('/admin/broadcast/{id}', [Admin\BroadcastController::class, 'show'], ['admin']);

    // کمپین‌ها
    $router->get('/admin/campaigns', [Admin\CampaignController::class, 'index'], ['admin']);
    $router->get('/admin/campaigns/create', [Admin\CampaignController::class, 'create'], ['admin']);
    $router->post('/admin/campaigns', [Admin\CampaignController::class, 'store'], ['admin', 'csrf']);
    $router->get('/admin/campaigns/{id}/edit', [Admin\CampaignController::class, 'edit'], ['admin']);
    $router->get('/admin/campaigns/{id}', [Admin\CampaignController::class, 'show'], ['admin']);
    $router->post('/admin/campaigns/{id}', [Admin\CampaignController::class, 'update'], ['admin', 'csrf']);
    $router->post('/admin/campaigns/{id}/grant', [Admin\CampaignController::class, 'grantRewards'], ['admin', 'csrf']);
    $router->post('/admin/campaigns/{id}/toggle', [Admin\CampaignController::class, 'toggle'], ['admin', 'csrf']);

    // گزارش‌ها و لایه‌ها
    $router->get('/admin/reports/{type}', [Admin\ReportController::class, 'index'], ['admin']);
    $router->get('/admin/reports/{type}/export', [Admin\ReportController::class, 'export'], ['admin']);

    // امنیت
    $router->get('/admin/security', [Admin\SecurityController::class, 'index'], ['admin']);
    $router->post('/admin/security/{id}/review', [Admin\SecurityController::class, 'review'], ['admin', 'csrf']);
    $router->get('/admin/logs', [Admin\SecurityController::class, 'logs'], ['admin']);

    // پشتیبانی
    $router->get('/admin/support', [Admin\SupportController::class, 'index'], ['admin']);
    $router->post('/admin/support/{id}/reply', [Admin\SupportController::class, 'reply'], ['admin', 'csrf']);

    // مدیران
    $router->get('/admin/admins', [Admin\AdminController::class, 'index'], ['admin']);
    $router->post('/admin/admins', [Admin\AdminController::class, 'store'], ['admin', 'csrf']);
    $router->post('/admin/admins/{id}/password', [Admin\AdminController::class, 'changePassword'], ['admin', 'csrf']);
    $router->post('/admin/admins/{id}/toggle', [Admin\AdminController::class, 'toggle'], ['admin', 'csrf']);

    // پشتیبان‌گیری
    $router->get('/admin/backup', [Admin\BackupController::class, 'index'], ['admin']);
    $router->post('/admin/backup/create', [Admin\BackupController::class, 'create'], ['admin', 'csrf']);
    $router->get('/admin/backup/download/{file}', [Admin\BackupController::class, 'download'], ['admin']);

    // به‌روزرسانی سامانه (فقط مدیر کل)
    $router->get('/admin/update', [Admin\UpdateController::class, 'index'], ['admin']);
    $router->post('/admin/update/check', [Admin\UpdateController::class, 'check'], ['admin', 'csrf']);
    $router->post('/admin/update/github', [Admin\UpdateController::class, 'installGithub'], ['admin', 'csrf']);
    $router->post('/admin/update/upload', [Admin\UpdateController::class, 'upload'], ['admin', 'csrf']);
    $router->post('/admin/update/restore', [Admin\UpdateController::class, 'restore'], ['admin', 'csrf']);
    $router->post('/admin/update/settings', [Admin\UpdateController::class, 'saveSettings'], ['admin', 'csrf']);

    // ------------------------------------------------ Cron وب (هاست اشتراکی بدون crontab)
    $router->get('/cron/run', [CronController::class, 'run']);
    $router->post('/cron/run', [CronController::class, 'run']);

    // ------------------------------------------------ پنل کاربری Mini App (تلگرام، بله، ایتا)
    $router->get('/app', [MiniApp\HomeController::class, 'index']);
    $router->post('/app/auth', [MiniApp\HomeController::class, 'auth']);
    $router->get('/app/api/me', [MiniApp\HomeController::class, 'me']);
    $router->get('/app/api/bootstrap', [MiniApp\DashboardApiController::class, 'bootstrap']);
    $router->get('/app/api/dashboard', [MiniApp\DashboardApiController::class, 'dashboard']);
    $router->get('/app/api/referrals', [MiniApp\DashboardApiController::class, 'referrals']);
    $router->post('/app/api/invite/send-banner', [MiniApp\DashboardApiController::class, 'sendInviteBanner'], ['csrf']);
    $router->post('/app/api/invite/track', [MiniApp\DashboardApiController::class, 'trackInviteShare'], ['csrf']);
    $router->post('/app/api/link-token', [MiniApp\DashboardApiController::class, 'createLinkToken'], ['csrf']);
    $router->post('/app/api/link-redeem', [MiniApp\DashboardApiController::class, 'redeemLinkToken'], ['csrf']);
    $router->post('/app/api/phone', [MiniApp\DashboardApiController::class, 'savePhone'], ['csrf']);
    $router->post('/app/api/write-access', [MiniApp\DashboardApiController::class, 'saveWriteAccess'], ['csrf']);
    $router->post('/app/api/channels/verify', [MiniApp\DashboardApiController::class, 'verifyChannels'], ['csrf']);
    $router->get('/app/api/transactions', [MiniApp\DashboardApiController::class, 'transactions']);
    $router->get('/app/api/withdrawals', [MiniApp\DashboardApiController::class, 'withdrawals']);
    $router->post('/app/api/withdraw', [MiniApp\DashboardApiController::class, 'withdraw'], ['csrf']);
    $router->post('/app/api/withdraw/{id}/cancel', [MiniApp\DashboardApiController::class, 'cancelWithdrawal'], ['csrf']);
    $router->get('/app/api/campaigns', [MiniApp\DashboardApiController::class, 'campaigns']);
    $router->get('/app/api/ranking', [MiniApp\DashboardApiController::class, 'ranking']);
    $router->get('/app/api/notifications', [MiniApp\DashboardApiController::class, 'notifications']);
    $router->post('/app/api/notifications/read', [MiniApp\DashboardApiController::class, 'readNotifications'], ['csrf']);
    $router->post('/app/api/support', [MiniApp\DashboardApiController::class, 'support'], ['csrf']);
    $router->get('/app/api/network/{id}', [MiniApp\DashboardApiController::class, 'network']);
    $router->post('/app/api/profile', [MiniApp\DashboardApiController::class, 'updateProfile'], ['csrf']);

    // ------------------------------------------------ Webhook پیام‌رسان‌ها (ایزوله و امن)
    $router->post('/webhook/telegram', [Webhook\TelegramController::class, 'handle']);
    $router->get('/webhook/telegram', [Webhook\TelegramController::class, 'status']);
    $router->post('/webhook/bale/{secret}', [Webhook\MessengerWebhookController::class, 'bale']);
    $router->post('/webhook/rubika/{secret}', [Webhook\MessengerWebhookController::class, 'rubika']);
    $router->post('/webhook/rubika/{secret}/{endpoint}', [Webhook\MessengerWebhookController::class, 'rubika']);
    $router->post('/webhook/m/{platform}/{secret}', [Webhook\MessengerWebhookController::class, 'generic']);
    $router->post('/webhook/m/{platform}/{secret}/{endpoint}', [Webhook\MessengerWebhookController::class, 'generic']);

    // ------------------------------------------------ سلامت سیستم
    $router->get('/healthz', [Webhook\TelegramController::class, 'health']);

    // ------------------------------------------------ 404
    $router->any('/{any}', [Webhook\TelegramController::class, 'notFound']);
};

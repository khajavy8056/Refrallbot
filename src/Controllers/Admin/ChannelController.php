<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Messengers\MessengerCapabilities;
use RB\Messengers\MessengerRegistry;
use RB\Services\AuditService;
use RB\Services\MembershipService;
use RB\Services\SettingsService;
use RB\Support\Database;

/**
 * مدیریت تفکیک‌شده کانال‌های اجباری به ازای پیام‌رسان‌های فعال و متصل + قوانین میان‌پیام‌رسانی قابل راستی‌آزمایی
 */
final class ChannelController extends AdminController
{
    public function index(): void
    {
        $db = Database::instance();
        $service = new MembershipService($db);
        $registry = new MessengerRegistry($db);

        // فقط پیام‌رسان‌های فعال و با موفقیت متصل‌شده نمایش داده می‌شوند
        $activeConnections = $registry->activeConnectedConnections();
        $activePlatformKeys = array_keys($activeConnections);

        $groupedChannels = [];
        foreach ($activePlatformKeys as $plat) {
            $groupedChannels[$plat] = $service->channelsHostedOn($plat, false);
        }

        $memberCounts = $service->membershipCountsByChannel();
        $totalVerifiedMembers = 0;
        if ($db->tableExists('user_channel_memberships')) {
            $totalVerifiedMembers = (int) $db->value("SELECT COUNT(*) FROM user_channel_memberships WHERE status = 'verified'");
        }

        $health = (!empty($_GET['check']) && $activePlatformKeys !== [])
            ? $service->health($activePlatformKeys)
            : [];

        $this->render('admin/channels/index', [
            'title' => 'کانال‌های اجباری',
            'activeConnections' => $activeConnections,
            'activePlatformKeys' => $activePlatformKeys,
            'groupedChannels' => $groupedChannels,
            'memberCounts' => $memberCounts,
            'totalVerifiedMembers' => $totalVerifiedMembers,
            'totalActiveChannels' => (int) $db->value('SELECT COUNT(*) FROM telegram_channels WHERE is_active = 1'),
            'enforcement' => $service->enforcementEnabled(),
            'health' => $health,
            'contactRequired' => $service->contactRequired(),
        ]);
    }

    public function save(): void
    {
        $db = Database::instance();
        $registry = new MessengerRegistry($db);
        $activePlatforms = $registry->activeConnectedPlatforms();

        $id = (int) en_digits((string) ($_POST['id'] ?? '0'));
        $platform = strtolower(trim((string) ($_POST['platform'] ?? MessengerCapabilities::TELEGRAM)));
        if (!MessengerCapabilities::isSupported($platform)) {
            $platform = MessengerCapabilities::TELEGRAM;
        }

        // جلوگیری از ثبت/ویرایش تنظیمات کانال برای پیام‌رسان غیرفعال یا متصل‌نشده
        if ($activePlatforms !== [] && !isset($activePlatforms[$platform])) {
            $this->back(null, 'پیام‌رسان «' . MessengerCapabilities::label($platform) . '» فعال و متصل نیست. ابتدا آن را در بخش «پیام‌رسان‌ها» فعال و متصل کنید.');
            return;
        }

        $supportsApi = MessengerCapabilities::supports($platform, 'channel_check_api');
        $requestedMode = strtolower(trim((string) ($_POST['check_mode'] ?? ($supportsApi ? 'api' : 'self_confirm'))));
        $checkMode = ($supportsApi && $requestedMode === 'api') ? 'api' : 'self_confirm';

        $stripInvis = static fn(string $s): string => (string) (preg_replace('~[\x{00AD}\x{061C}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]+~u', '', trim($s)) ?? trim($s));
        $title = trim((string) ($_POST['title'] ?? ''));
        $chatId = en_digits($stripInvis((string) ($_POST['chat_id'] ?? '')));
        $invite = $stripInvis((string) ($_POST['invite_link'] ?? ''));
        $crossNote = trim((string) ($_POST['cross_rule_note'] ?? ''));
        $order = (int) en_digits((string) ($_POST['sort_order'] ?? '0'));
        $active = !empty($_POST['is_active']);

        // پردازش و راستی‌آزمایی پیام‌رسان‌های ورودی هدف (میان‌پیام‌رسانی فقط برای پیام‌رسان‌های دارای getChatMember رسمی)
        $rawTargets = $_POST['target_platforms'] ?? [];
        if (is_string($rawTargets)) {
            $rawTargets = preg_split('~[\s,|;]+~', $rawTargets) ?: [];
        }
        $selectedTargets = is_array($rawTargets) ? array_values(array_filter(array_map(
            static fn($v): string => strtolower(trim((string) $v)),
            $rawTargets
        ))) : [];

        $requestedCross = false;
        foreach ($selectedTargets as $st) {
            if ($st !== '' && $st !== 'same' && $st !== $platform) {
                $requestedCross = true;
                break;
            }
        }

        if ($requestedCross && !MessengerCapabilities::canVerifyCrossMessengerChannel($platform)) {
            $this->back(
                null,
                'اعمال اجباری میان‌پیام‌رسانی روی کانال «' . MessengerCapabilities::label($platform) . '» ممکن نیست؛ '
                . 'زیرا API رسمی ' . MessengerCapabilities::label($platform) . ' فاقد متد استعلام عضویت کاربر (getChatMember) است.'
            );
            return;
        }

        if ($requestedCross && $checkMode !== 'api') {
            $this->back(null, 'برای اعمال شرط عضویت میان‌پیام‌رسانی، روش بررسی کانال باید روی «بررسی خودکار با API رسمی (getChatMember)» باشد.');
            return;
        }

        // فقط پیام‌رسان‌های فعال و متصل‌شده را در لیست هدف بپذیر
        if ($activePlatforms !== [] && $selectedTargets !== []) {
            $selectedTargets = array_values(array_filter(
                $selectedTargets,
                static fn(string $t): bool => isset($activePlatforms[$t])
            ));
        }
        $targetPlatforms = ($supportsApi && $checkMode === 'api')
            ? MembershipService::encodeTargetPlatforms($platform, $selectedTargets)
            : 'same';

        // تبدیل خودکار لینک عمومی پیام‌رسان به @username
        if (preg_match('~^(?:https?://)?(?:t\.me|telegram\.me|ble\.ir|rubika\.ir|eitaa\.com)/([A-Za-z0-9_]{3,64})/?$~i', $chatId, $cm)) {
            $chatId = '@' . $cm[1];
        } elseif (preg_match('~^[A-Za-z][A-Za-z0-9_]{2,63}$~', $chatId)) {
            $chatId = '@' . $chatId;
        }

        $domainMap = [
            MessengerCapabilities::TELEGRAM => 'https://t.me/',
            MessengerCapabilities::BALE     => 'https://ble.ir/',
            MessengerCapabilities::RUBIKA   => 'https://rubika.ir/',
            MessengerCapabilities::EITAA    => 'https://eitaa.com/',
        ];
        if ($invite !== '' && !preg_match('~^https?://~i', $invite)) {
            $invite = 'https://' . ltrim($invite, '/');
        }
        if ($invite === '' && str_starts_with($chatId, '@')) {
            $invite = ($domainMap[$platform] ?? 'https://t.me/') . ltrim($chatId, '@');
        }

        if ($title === '' || ($chatId === '' && $invite === '')) {
            $this->back(null, 'عنوان و شناسه یا لینک کانال الزامی است.');
            return;
        }
        if ($chatId === '' && $invite !== '') {
            $chatId = '@' . substr(md5($invite), 0, 12);
        }

        if ($checkMode === 'api' && !preg_match('~^(@[A-Za-z0-9_]{3,64}|-?\d{5,20})$~', $chatId)) {
            $this->back(null, 'برای بررسی خودکار API، شناسه کانال باید @username یا شناسه عددی معتبر (مثلاً -100...) باشد.');
            return;
        }
        if ($invite !== '' && !preg_match('~^https://(?:t\.me|telegram\.me|ble\.ir|rubika\.ir|eitaa\.com)/~i', $invite)) {
            $this->back(null, 'لینک دعوت باید با آدرس رسمی پیام‌رسان (t.me، ble.ir، rubika.ir یا eitaa.com) شروع شود.');
            return;
        }

        $data = [
            'title' => mb_substr($title, 0, 150),
            'chat_id' => $chatId,
            'invite_link' => $invite ?: null,
            'is_active' => $active ? 1 : 0,
            'sort_order' => $order,
        ];
        if ($db->columnExists('telegram_channels', 'platform')) {
            $data['platform'] = $platform;
        }
        if ($db->columnExists('telegram_channels', 'check_mode')) {
            $data['check_mode'] = $checkMode;
        }
        if ($db->columnExists('telegram_channels', 'target_platforms')) {
            $data['target_platforms'] = $targetPlatforms;
        }
        if ($db->columnExists('telegram_channels', 'cross_rule_note')) {
            $data['cross_rule_note'] = $crossNote !== '' ? mb_substr($crossNote, 0, 250) : null;
        }

        if ($id > 0) {
            if ($db->columnExists('telegram_channels', 'updated_at')) {
                $data['updated_at'] = now();
            }
            $db->update('telegram_channels', $data, 'id = :id', ['id' => $id]);
            AuditService::log('channel.updated', 'channel', $id, [
                'platform' => $platform,
                'chat_id' => $chatId,
                'check_mode' => $checkMode,
                'target_platforms' => $targetPlatforms,
            ], current_admin_id());
            $this->back('کانال اجباری با موفقیت به‌روزرسانی شد.');
            return;
        }

        $data['created_at'] = now();
        $newId = $db->insert('telegram_channels', $data);
        AuditService::log('channel.created', 'channel', $newId, [
            'platform' => $platform,
            'chat_id' => $chatId,
            'check_mode' => $checkMode,
            'target_platforms' => $targetPlatforms,
        ], current_admin_id());
        $this->back('کانال اجباری جدید با موفقیت ثبت شد.');
    }

    public function delete(array $params): void
    {
        $id = (int) $params['id'];
        Database::instance()->delete('telegram_channels', 'id = :id', ['id' => $id]);
        AuditService::log('channel.deleted', 'channel', $id, [], current_admin_id());
        $this->back('کانال حذف شد.');
    }

    public function reorder(): void
    {
        $force = !empty($_POST['force_channel_membership']) ? '1' : '0';
        $contact = !empty($_POST['contact_required']) ? '1' : '0';
        SettingsService::set('force_channel_membership', $force, 'telegram');
        SettingsService::set('contact_required', $contact, 'telegram');
        SettingsService::flush();
        AuditService::log('channel.settings_updated', 'channel', null, [
            'force' => (bool) $force,
            'contact_required' => (bool) $contact,
        ], current_admin_id());
        $this->back('تنظیمات دروازه ورود و عضویت اجباری ذخیره شد.');
    }

    protected function back(?string $success = null, ?string $error = null): void
    {
        if (\RB\Support\Request::expectsJson()) {
            if ($error !== null) {
                $this->fail($error);
                return;
            }
            $this->ok($success ?? 'انجام شد.', ['reload' => true]);
            return;
        }
        $referer = $_SERVER['HTTP_REFERER'] ?? url('admin/channels');
        if ($success !== null) {
            $this->flash('success', $success);
        }
        if ($error !== null) {
            $this->flash('error', $error);
        }
        \RB\Support\Request::redirect($referer);
    }
}

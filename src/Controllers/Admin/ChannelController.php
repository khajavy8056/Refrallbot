<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Messengers\MessengerCapabilities;
use RB\Services\AuditService;
use RB\Services\MembershipService;
use RB\Services\SettingsService;
use RB\Support\Database;

final class ChannelController extends AdminController
{
    public function index(): void
    {
        $db = Database::instance();
        $service = new MembershipService($db);
        $health = !empty($_GET['check']) ? $service->health() : [];
        $this->render('admin/channels/index', [
            'title' => 'کانال‌های اجباری (چندپیام‌رسانی)',
            'channels' => $db->all('SELECT * FROM telegram_channels ORDER BY platform, sort_order, id'),
            'enforcement' => $service->enforcementEnabled(),
            'health' => $health,
            'contactRequired' => $service->contactRequired(),
        ]);
    }

    public function save(): void
    {
        $db = Database::instance();
        $id = (int) en_digits((string) ($_POST['id'] ?? '0'));
        $platform = strtolower(trim((string) ($_POST['platform'] ?? MessengerCapabilities::TELEGRAM)));
        if (!MessengerCapabilities::isSupported($platform)) {
            $platform = MessengerCapabilities::TELEGRAM;
        }
        $supportsApi = MessengerCapabilities::supports($platform, 'channel_check_api');
        $requestedMode = strtolower(trim((string) ($_POST['check_mode'] ?? ($supportsApi ? 'api' : 'self_confirm'))));
        $checkMode = ($supportsApi && $requestedMode === 'api') ? 'api' : 'self_confirm';

        $title = trim((string) ($_POST['title'] ?? ''));
        $stripInvis = static fn(string $s): string => (string) (preg_replace('~[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+~u', '', trim($s)) ?? trim($s));
        $chatId = en_digits($stripInvis((string) ($_POST['chat_id'] ?? '')));
        $invite = $stripInvis((string) ($_POST['invite_link'] ?? ''));
        $order = (int) en_digits((string) ($_POST['sort_order'] ?? '0'));
        $active = !empty($_POST['is_active']);

        // تبدیل خودکار لینک عمومی هر پیام‌رسان به @username
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
            $this->back(null, 'برای بررسی خودکار API، شناسه کانال باید @username یا شناسه عددی معتبر باشد.');
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

        if ($id > 0) {
            $data['updated_at'] = now();
            $db->update('telegram_channels', $data, 'id = :id', ['id' => $id]);
            AuditService::log('channel.updated', 'channel', $id, ['platform' => $platform, 'chat_id' => $chatId, 'check_mode' => $checkMode], current_admin_id());
            $this->back('کانال به‌روزرسانی شد.');
            return;
        }
        $data['created_at'] = now();
        $newId = $db->insert('telegram_channels', $data);
        AuditService::log('channel.created', 'channel', $newId, ['platform' => $platform, 'chat_id' => $chatId, 'check_mode' => $checkMode], current_admin_id());
        $this->back('کانال اجباری جدید اضافه شد.');
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
        $db = Database::instance();
        $orders = $_POST['order'] ?? [];
        if (is_array($orders)) {
            foreach ($orders as $id => $order) {
                $db->update('telegram_channels', ['sort_order' => (int) $order], 'id = :id', ['id' => (int) $id]);
            }
        }
        SettingsService::set('force_channel_membership', !empty($_POST['force_channel_membership']) ? '1' : '0', 'telegram');
        $this->back('ترتیب و وضعیت عضویت اجباری به‌روزرسانی شد.');
    }
}

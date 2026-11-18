<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
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
            'title' => 'کانال‌های اجباری',
            'channels' => $db->all('SELECT * FROM telegram_channels ORDER BY sort_order, id'),
            'enforcement' => $service->enforcementEnabled(),
            'health' => $health,
            'contactRequired' => $service->contactRequired(),
        ]);
    }

    public function save(): void
    {
        $db = Database::instance();
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $chatId = trim((string) ($_POST['chat_id'] ?? ''));
        $invite = trim((string) ($_POST['invite_link'] ?? ''));
        $order = (int) ($_POST['sort_order'] ?? 0);
        $active = !empty($_POST['is_active']);

        if ($title === '' || $chatId === '') {
            $this->back(null, 'عنوان و شناسه کانال الزامی است.');
            return;
        }
        if (!preg_match('~^(@[A-Za-z0-9_]{4,32}|-100\d{6,15})$~', $chatId)) {
            $this->back(null, 'شناسه کانال باید با @ شروع شود (کانال عمومی) یا شناسه عددی -100... باشد.');
            return;
        }
        if ($invite !== '' && !preg_match('~^https://t\.me/~', $invite)) {
            $this->back(null, 'لینک دعوت باید با https://t.me/ شروع شود.');
            return;
        }
        if ($invite === '' && str_starts_with($chatId, '@')) {
            $invite = 'https://t.me/' . ltrim($chatId, '@');
        }

        $data = [
            'title' => mb_substr($title, 0, 150),
            'chat_id' => $chatId,
            'invite_link' => $invite ?: null,
            'is_active' => $active ? 1 : 0,
            'sort_order' => $order,
        ];
        if ($id > 0) {
            $data['updated_at'] = now();
            $db->update('telegram_channels', $data, 'id = :id', ['id' => $id]);
            AuditService::log('channel.updated', 'channel', $id, ['chat_id' => $chatId], current_admin_id());
            $this->back('کانال به‌روزرسانی شد.');
            return;
        }
        $data['created_at'] = now();
        $newId = $db->insert('telegram_channels', $data);
        AuditService::log('channel.created', 'channel', $newId, ['chat_id' => $chatId], current_admin_id());
        $this->back('کانال اضافه شد.');
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

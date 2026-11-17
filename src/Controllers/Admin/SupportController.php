<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Services\SupportService;
use RB\Support\Database;

final class SupportController extends AdminController
{
    public function index(): void
    {
        $db = Database::instance();
        $status = (string) ($_GET['status'] ?? 'open');
        $where = $status !== '' && $status !== 'all' ? 'WHERE sm.status = :s' : '';
        $bind = $where !== '' ? ['s' => $status] : [];
        $messages = $db->all(
            'SELECT sm.*, u.first_name, u.last_name, u.username, u.telegram_id
             FROM support_messages sm JOIN users u ON u.id = sm.user_id
             ' . $where . ' ORDER BY sm.id DESC LIMIT 100',
            $bind
        );
        $this->render('admin/support/index', [
            'title' => 'پشتیبانی',
            'messages' => $messages,
            'statusFilter' => $status,
            'openCount' => (int) $db->value("SELECT COUNT(*) FROM support_messages WHERE status = 'open' AND direction = 'in'"),
        ]);
    }

    public function reply(array $params): void
    {
        $id = (int) $params['id'];
        $message = trim((string) ($_POST['message'] ?? ''));
        if (mb_strlen($message) < 2) {
            $this->fail('متن پاسخ را وارد کنید.');
            return;
        }
        try {
            SupportService::reply($id, (int) current_admin_id(), $message);
            $this->ok('پاسخ ارسال شد.', ['reload' => true]);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }
}

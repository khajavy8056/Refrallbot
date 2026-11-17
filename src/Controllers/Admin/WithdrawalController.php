<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Services\WithdrawalService;
use RB\Services\WalletService;
use RB\Support\Database;
use RB\Support\Request;

final class WithdrawalController extends AdminController
{
    public function index(): void
    {
        $service = new WithdrawalService();
        $filters = $this->filters(['status', 'q']);
        if (empty($filters['status'])) {
            $filters['status'] = (string) ($_GET['status'] ?? '');
        }
        $from = $this->dateFilter('from');
        $to = $this->dateFilter('to');
        if ($from) { $filters['from'] = $from; }
        if ($to) { $filters['to'] = $to; }

        $result = $service->paginate($filters, $this->page(), max(20, $this->perPage()));
        $stats = $service->stats();

        $this->render('admin/withdrawals/index', [
            'title' => 'درخواست‌های برداشت',
            'result' => $result,
            'filters' => $filters,
            'stats' => $stats,
            'fromRaw' => $_GET['from'] ?? '',
            'toRaw' => $_GET['to'] ?? '',
        ]);
    }

    public function show(array $params): void
    {
        $id = (int) $params['id'];
        $service = new WithdrawalService();
        $request = $service->find($id);
        if ($request === null) {
            $this->flash('error', 'درخواست یافت نشد.');
            Request::redirect(url('admin/withdrawals'));
            return;
        }
        $db = Database::instance();
        $card = WithdrawalService::decryptedCard($request);
        $payments = $db->all('SELECT wp.*, a.name AS admin_name FROM withdrawal_payments wp LEFT JOIN admins a ON a.id = wp.admin_id WHERE wp.withdrawal_id = :id ORDER BY wp.id DESC', ['id' => $id]);
        $history = $db->all(
            'SELECT * FROM withdrawal_requests WHERE user_id = :u AND id <> :id ORDER BY id DESC LIMIT 10',
            ['u' => (int) $request['user_id'], 'id' => $id]
        );
        $wallet = (new WalletService($db))->summary((int) $request['user_id']);

        $this->render('admin/withdrawals/show', [
            'title' => 'جزئیات درخواست برداشت',
            'request' => $request,
            'card' => $card,
            'maskedCard' => WithdrawalService::maskCard($card),
            'payments' => $payments,
            'history' => $history,
            'walletSummary' => $wallet,
            'reviewer' => $request['reviewed_by'] ? $db->first('SELECT name FROM admins WHERE id = :id', ['id' => (int) $request['reviewed_by']]) : null,
            'payer' => $request['paid_by'] ? $db->first('SELECT name FROM admins WHERE id = :id', ['id' => (int) $request['paid_by']]) : null,
        ]);
    }

    public function approve(array $params): void
    {
        $id = (int) $params['id'];
        $this->respond(function () use ($id) {
            (new WithdrawalService())->approve($id, (int) current_admin_id());
            return ['redirect' => url('admin/withdrawals/' . $id)];
        }, 'درخواست تأیید شد.', 'admin/withdrawals/' . $id);
    }

    public function reject(array $params): void
    {
        $id = (int) $params['id'];
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $this->respond(function () use ($id, $reason) {
            (new WithdrawalService())->reject($id, (int) current_admin_id(), $reason);
            return ['redirect' => url('admin/withdrawals/' . $id)];
        }, 'درخواست رد شد و مبلغ به موجودی کاربر بازگشت.', 'admin/withdrawals/' . $id);
    }

    public function pay(array $params): void
    {
        $id = (int) $params['id'];
        $tracking = trim((string) ($_POST['tracking_code'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        $this->respond(function () use ($id, $tracking, $note) {
            (new WithdrawalService())->markPaid($id, (int) current_admin_id(), $tracking !== '' ? $tracking : null, $note !== '' ? $note : null);
            return ['redirect' => url('admin/withdrawals/' . $id)];
        }, 'پرداخت ثبت شد.', 'admin/withdrawals/' . $id);
    }
}

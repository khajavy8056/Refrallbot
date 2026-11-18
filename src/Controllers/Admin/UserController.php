<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Services\AuditService;
use RB\Services\CommissionSettings;
use RB\Services\SecurityService;
use RB\Services\TelegramService;
use RB\Services\UserService;
use RB\Services\WalletService;
use RB\Services\WithdrawalService;
use RB\Services\CommissionService;
use RB\Support\Database;
use RB\Support\Money;

final class UserController extends AdminController
{
    public function index(): void
    {
        $users = new UserService();
        $filters = $this->filters(['q', 'status', 'has_refs', 'has_income', 'with_phone', 'suspicious']);
        $from = $this->dateFilter('from');
        $to = $this->dateFilter('to');
        if ($from) { $filters['from'] = $from; }
        if ($to) { $filters['to'] = $to; }

        $result = $users->paginate($filters, $this->page(), $this->perPage());
        $db = Database::instance();

        $this->render('admin/users/index', [
            'title' => 'مدیریت کاربران',
            'result' => $result,
            'filters' => $filters,
            'fromRaw' => $_GET['from'] ?? '',
            'toRaw' => $_GET['to'] ?? '',
            'summary' => [
                'total' => (int) $db->value('SELECT COUNT(*) FROM users'),
                'active' => (int) $db->value("SELECT COUNT(*) FROM users WHERE status = 'active'"),
                'blocked' => (int) $db->value("SELECT COUNT(*) FROM users WHERE status = 'blocked'"),
                'with_refs' => (int) $db->value('SELECT COUNT(*) FROM users WHERE direct_ref_count > 0'),
            ],
        ]);
    }

    public function show(array $params): void
    {
        $id = (int) $params['id'];
        $db = Database::instance();
        $users = new UserService();
        $user = $users->find($id);
        if ($user === null) {
            $this->flash('error', 'کاربر یافت نشد.');
            \RB\Support\Request::redirect(url('admin/users'));
            return;
        }

        $wallet = new WalletService($db);
        [$transactions, $txTotal] = $wallet->transactions($id, [], 1, 30);
        $withdrawals = (new WithdrawalService($db))->forUser($id, 20);
        $referrer = $user['referred_by'] ? $users->find((int) $user['referred_by']) : null;
        $directRefs = $users->directReferrals($id, 50);
        $commissions = $db->all(
            'SELECT rc.*, u.first_name, u.last_name, u.username
             FROM referral_commissions rc JOIN users u ON u.id = rc.source_user_id
             WHERE rc.beneficiary_id = :u ORDER BY rc.id DESC LIMIT 30',
            ['u' => $id]
        );
        $events = $db->all(
            'SELECT * FROM referral_events WHERE user_id = :u OR actor_id = :u2 ORDER BY id DESC LIMIT 20',
            ['u' => $id, 'u2' => $id]
        );
        $security = $db->all('SELECT * FROM security_events WHERE user_id = :u ORDER BY id DESC LIMIT 20', ['u' => $id]);
        $reconcile = $wallet->reconcile($id);

        $this->render('admin/users/show', [
            'title' => 'پروفایل کاربر',
            'user' => $user,
            'walletSummary' => $wallet->summary($id),
            'transactions' => $transactions,
            'txTotal' => $txTotal,
            'withdrawals' => $withdrawals,
            'referrer' => $referrer,
            'directRefs' => $directRefs,
            'commissions' => $commissions,
            'events' => $events,
            'security' => $security,
            'reconcile' => $reconcile,
            'stats' => $users->stats($id),
            'settings' => CommissionSettings::current(),
        ]);
    }

    public function updateStatus(array $params): void
    {
        $id = (int) $params['id'];
        $status = (string) ($_POST['status'] ?? '');
        $reason = trim((string) ($_POST['reason'] ?? ''));
        try {
            (new UserService())->setStatus($id, $status, current_admin_id(), $reason);
            $this->flash('success', 'وضعیت کاربر به‌روزرسانی شد.');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        \RB\Support\Request::redirect(url('admin/users/' . $id) . '#status');
    }

    public function changeReferrer(array $params): void
    {
        $id = (int) $params['id'];
        $newReferrerInput = trim((string) ($_POST['referrer'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if (mb_strlen($reason) < 5) {
            $this->flash('error', 'ثبت دلیل تغییر معرف الزامی است (حداقل ۵ کاراکتر).');
            \RB\Support\Request::redirect(url('admin/users/' . $id));
            return;
        }
        if (!\RB\Services\AdminAuth::requireSuperAdmin()) {
            $this->flash('error', 'تغییر معرف فقط توسط مدیر کل انجام می‌شود.');
            \RB\Support\Request::redirect(url('admin/users/' . $id));
            return;
        }

        $users = new UserService();
        $newId = null;
        if ($newReferrerInput !== '') {
            $found = str_starts_with($newReferrerInput, '@')
                ? null
                : ($users->findByTelegramId((int) en_digits($newReferrerInput)) ?? $users->findByCode($newReferrerInput));
            if ($found === null) {
                $found = Database::instance()->first('SELECT * FROM users WHERE username = :u', ['u' => ltrim($newReferrerInput, '@')]);
            }
            if ($found === null) {
                $this->flash('error', 'کاربر معرف یافت نشد (بر اساس شناسه، کد دعوت یا نام کاربری جستجو شد).');
                \RB\Support\Request::redirect(url('admin/users/' . $id));
                return;
            }
            $newId = (int) $found['id'];
        }

        try {
            $users->changeReferrer($id, $newId, $reason, current_admin_id());
            $this->flash('success', 'معرف کاربر تغییر کرد و سوابق ثبت شد.');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        \RB\Support\Request::redirect(url('admin/users/' . $id));
    }

    public function adjustWallet(array $params): void
    {
        $id = (int) $params['id'];
        $amount = Money::parseToman($_POST['amount'] ?? null);
        $type = (string) ($_POST['type'] ?? 'credit');
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if ($amount === null || $amount <= 0) {
            $this->flash('error', 'مبلغ اصلاح باید عددی بزرگ‌تر از صفر باشد.');
            \RB\Support\Request::redirect(url('admin/users/' . $id));
            return;
        }
        if (mb_strlen($reason) < 5) {
            $this->flash('error', 'ثبت دلیل اصلاح مالی الزامی است (حداقل ۵ کاراکتر).');
            \RB\Support\Request::redirect(url('admin/users/' . $id));
            return;
        }
        if (!\RB\Services\AdminAuth::requireSuperAdmin()) {
            $this->flash('error', 'اصلاح مالی فقط توسط مدیر کل انجام می‌شود.');
            \RB\Support\Request::redirect(url('admin/users/' . $id));
            return;
        }

        try {
            $wallet = new WalletService();
            $key = 'adjustment:' . $id . ':' . time() . ':' . bin2hex(random_bytes(3));
            if ($type === 'credit') {
                $txId = $wallet->credit($id, 'adjustment', $amount, [
                    'description' => 'اصلاح مالی (واریز): ' . $reason,
                    'idempotency_key' => $key,
                    'created_by_admin_id' => current_admin_id(),
                    'source_type' => 'manual',
                    'meta' => ['reason' => $reason],
                ]);
            } else {
                $txId = $wallet->debit($id, 'adjustment', $amount, [
                    'description' => 'اصلاح مالی (برداشت): ' . $reason,
                    'idempotency_key' => $key,
                    'created_by_admin_id' => current_admin_id(),
                    'source_type' => 'manual',
                    'meta' => ['reason' => $reason],
                ], allowNegative: true);
            }
            AuditService::log('wallet.adjusted', 'user', $id, [
                'amount' => $amount, 'type' => $type, 'reason' => $reason, 'tx' => $txId,
            ], current_admin_id());
            $this->flash('success', 'اصلاح مالی با ثبت سابقه انجام شد.');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        \RB\Support\Request::redirect(url('admin/users/' . $id));
    }
}

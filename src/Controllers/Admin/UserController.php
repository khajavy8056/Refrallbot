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
        $db = Database::instance();
        $users = new UserService($db);
        $filters = $this->filters(['q', 'status', 'platform', 'has_refs', 'has_income', 'with_phone', 'suspicious']);
        $from = $this->dateFilter('from');
        $to = $this->dateFilter('to');
        if ($from) { $filters['from'] = $from; }
        if ($to) { $filters['to'] = $to; }

        $msgSummary = \RB\Services\StatsService::segmentedMessengerSummary($db);
        // اگر فیلتر پیام‌رسان روی پلتفرم غیرفعال/متصل‌نشده باشد، پاک شود
        if (!empty($filters['platform']) && !isset($msgSummary['active'][strtolower((string) $filters['platform'])])) {
            unset($filters['platform']);
        }
        $result = $users->paginate($filters, $this->page(), $this->perPage());

        $this->render('admin/users/index', [
            'title' => 'مدیریت کاربران',
            'result' => $result,
            'filters' => $filters,
            'fromRaw' => $_GET['from'] ?? '',
            'toRaw' => $_GET['to'] ?? '',
            'activeMessengers' => $msgSummary['active'],
            'pendingVerificationCount' => (int) $db->value("SELECT COUNT(*) FROM users WHERE verification_required = 1 AND verify_status = 'pending' AND referred_by IS NOT NULL AND qualified_at IS NULL"),
            'summary' => [
                'total' => (int) $db->value('SELECT COUNT(*) FROM users'),
                'active' => (int) $db->value("SELECT COUNT(*) FROM users WHERE status = 'active'"),
                'blocked' => (int) $db->value("SELECT COUNT(*) FROM users WHERE status = 'blocked'"),
                'with_refs' => (int) $db->value('SELECT COUNT(*) FROM users WHERE direct_ref_count > 0'),
            ],
        ]);
    }

    public function verificationQueue(): void
    {
        $db = Database::instance();
        $page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
        $where = "verification_required = 1 AND verify_status = 'pending' AND referred_by IS NOT NULL AND qualified_at IS NULL";
        $this->render('admin/users/verification', [
            'title'=>'دعوت‌های نیازمند تأیید',
            'total'=>(int)$db->value('SELECT COUNT(*) FROM users WHERE '.$where),
            'through'=>(int)$db->value('SELECT COALESCE(MAX(id), 0) FROM users WHERE '.$where),
            'page'=>$page,
            'rows'=>$db->all('SELECT id, telegram_id, first_name, last_name, username, status, joined_at, referred_by FROM users WHERE '.$where.' ORDER BY id ASC LIMIT 50 OFFSET '.(($page-1)*50)),
        ]);
    }

    public function bulkVerification(): void
    {
        $decision = (string)($_POST['decision'] ?? '');
        if (!in_array($decision, ['verified','rejected'], true)) { $this->fail('عملیات نامعتبر است.', 422); return; }
        $db = Database::instance();
        $all = ($_POST['scope'] ?? '') === 'all';
        if ($all && !$this->wantsJson()) { $this->flash('error', 'انتخاب همه صفحات به جاوااسکریپت فعال نیاز دارد.'); \RB\Support\Request::redirect(url('admin/users/verification')); return; }
        if ($all) {
            $after = max(0, (int)($_POST['after'] ?? 0));
            $through = max(0, (int)($_POST['through'] ?? 0));
            $rows = $db->all("SELECT id FROM users WHERE verification_required = 1 AND verify_status = 'pending' AND referred_by IS NOT NULL AND qualified_at IS NULL AND id > :a AND id <= :z ORDER BY id LIMIT 20", ['a'=>$after, 'z'=>$through]);
            $ids = array_map('intval', array_column($rows, 'id'));
        } else {
            $raw = $_POST['ids'] ?? [];
            if (!is_array($raw) || count($raw) > 50) { $this->fail('حداکثر ۵۰ کاربر را در هر نوبت انتخاب کنید.', 422); return; }
            $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static fn($i)=>$i>0)));
            if (!$ids) { $this->fail('حداقل یک کاربر انتخاب کنید.', 422); return; }
        }
        $result = ['changed'=>0, 'qualified'=>0, 'skipped'=>0, 'conflicts'=>[], 'errors'=>[], 'next'=>$all ? $after : 0, 'done'=>!$all || count($ids)<20];
        $users = new UserService($db);
        foreach ($ids as $id) {
            if ($all) $result['next']=$id;
            try {
                $r=$users->decideVerification($id, $decision, current_admin_id());
                if ($r['changed']) { $result['changed']++; if ($r['qualified']) $result['qualified']++; }
                elseif ($r['conflict']) $result['conflicts'][]=$id;
                else $result['skipped']++;
            } catch (\Throwable $e) {
                \RB\Support\ErrorHandler::logException($e);
                $result['errors'][]=$id;
            }
        }
        if ($this->wantsJson()) { $this->ok('عملیات انجام شد.', $result); return; }
        $this->flash('success', 'تغییر وضعیت: '.$result['changed'].'؛ واجد شرایط: '.$result['qualified'].'؛ موارد نیازمند بررسی: '.count($result['conflicts']).'؛ خطا: '.count($result['errors']));
        \RB\Support\Request::redirect(url('admin/users/verification'));
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
            'messengerAccounts' => $users->messengerAccounts($id),
            'layerEarnings' => $users->layerEarningsSummary($id),
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

    public function approve(array $params): void
    {
        $id = (int) $params['id'];
        try {
            $decision = (new UserService())->decideVerification($id, 'verified', current_admin_id());
            if (!$decision['changed']) throw new \RuntimeException($decision['conflict'] ? 'سابقه مالی موجود است؛ بررسی دستی لازم است.' : 'این کاربر در صف تأیید نیست.');
            $qualified = $decision['qualified'];
            $msg = $qualified
                ? 'عضویت کاربر تأیید شد و پاداش دعوت طبق تنظیمات فعال محاسبه و واریز گردید.'
                : 'وضعیت احراز عضویت کاربر به «تأییدشده» تغییر یافت.';
            if ($this->wantsJson()) {
                $this->ok($msg, ['verified' => true, 'qualified' => $qualified]);
                return;
            }
            $this->flash('success', $msg);
        } catch (\Throwable $e) {
            if ($this->wantsJson()) {
                $this->fail($e->getMessage());
                return;
            }
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
            $digits = en_digits($newReferrerInput);
            $found = str_starts_with($newReferrerInput, '@')
                ? null
                : ((ctype_digit($digits) ? $users->findByTelegramId((int) $digits) : null) ?? $users->findByCode($newReferrerInput));
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
        $requestId = (string) ($_POST['request_id'] ?? '');

        if ($amount === null || $amount <= 0) {
            $this->flash('error', 'مبلغ اصلاح باید عددی بزرگ‌تر از صفر باشد.');
            \RB\Support\Request::redirect(url('admin/users/' . $id));
            return;
        }
        if (!in_array($type, ['credit', 'debit'], true) || !preg_match('/^[a-f0-9]{32}$/', $requestId)) {
            $this->flash('error', 'درخواست اصلاح مالی نامعتبر است؛ صفحه را تازه و دوباره تلاش کنید.');
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
            $key = 'adjustment:' . $id . ':' . $requestId;
            $db = Database::instance();
            $txId = $db->transaction(function () use ($wallet, $key, $type, $id, $amount, $reason) {
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
            if ($txId !== null) {
                AuditService::log('wallet.adjusted', 'user', $id, [
                    'amount' => $amount, 'type' => $type, 'reason' => $reason, 'tx' => $txId,
                ], current_admin_id());
            }
            return $txId;
            });
            $this->flash('success', $txId === null ? 'این درخواست قبلاً ثبت شده است؛ مبلغ دوباره اعمال نشد.' : 'اصلاح مالی با ثبت سابقه انجام شد.');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        \RB\Support\Request::redirect(url('admin/users/' . $id));
    }
}

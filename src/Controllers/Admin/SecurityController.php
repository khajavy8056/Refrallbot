<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Services\AuditService;
use RB\Support\Database;

final class SecurityController extends AdminController
{
    public const TYPE_LABELS = [
        'multiple_accounts_same_ip' => 'چند حساب با IP یکسان',
        'signup_burst' => 'ثبت‌نام پرتعداد در زمان کوتاه',
        'referral_burst' => 'معرفی پرتعداد در زمان کوتاه',
        'incomplete_profile' => 'پروفایل ناقص',
        'duplicate_names' => 'نام‌های تکراری',
        'self_referral_attempt' => 'تلاش برای استفاده از لینک خود',
        'contact_mismatch' => 'عدم تطبیق شماره تماس',
        'card_reuse' => 'استفاده مجدد از شماره کارت',
        'auto_flagged' => 'علامت‌گذاری خودکار',
        'miniapp_invalid_signature' => 'امضای نامعتبر Mini App',
        'membership_api_error' => 'خطای بررسی عضویت کانال',
    ];

    public const STATUS_LABELS = [
        'open' => 'بررسی‌نشده',
        'reviewed' => 'بررسی‌شده',
        'dismissed' => 'رد شد',
        'confirmed' => 'تأیید تخلف',
    ];

    public static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    public function index(): void
    {
        $db = Database::instance();
        $status = (string) ($_GET['status'] ?? 'open');
        if (!in_array($status, ['all', 'open', 'reviewed', 'dismissed', 'confirmed'], true)) $status = 'open';
        $where = $status !== 'all' ? ' WHERE se.status = :s' : '';
        $bind = $status !== 'all' ? ['s' => $status] : [];

        $events = $db->all(
            'SELECT se.*, u.first_name, u.last_name, u.username, u.telegram_id, u.risk_score, u.status AS user_status,
                    u.referred_by, r.first_name AS ref_first_name, r.last_name AS ref_last_name, r.username AS ref_username,
                    responsible.first_name AS responsible_first_name, responsible.last_name AS responsible_last_name, responsible.username AS responsible_username
             FROM security_events se LEFT JOIN users u ON u.id = se.user_id
             LEFT JOIN users r ON r.id = u.referred_by
             LEFT JOIN users responsible ON responsible.id = se.responsible_user_id' . $where .
            ' ORDER BY se.id DESC LIMIT 200',
            $bind
        );
        $suspiciousUsers = $db->all(
            "SELECT u.*, r.first_name AS ref_first_name, r.last_name AS ref_last_name FROM users u LEFT JOIN users r ON r.id = u.referred_by WHERE u.status = 'suspicious' OR u.risk_score >= 20 ORDER BY u.risk_score DESC LIMIT 50"
        );

        $this->render('admin/security/index', [
            'title' => 'امنیت و کنترل تقلب',
            'events' => $events,
            'statusFilter' => $status,
            'suspiciousUsers' => $suspiciousUsers,
            'counts' => [
                'open' => (int) $db->value("SELECT COUNT(*) FROM security_events WHERE status = 'open'"),
                'high' => (int) $db->value("SELECT COUNT(*) FROM security_events WHERE severity = 'high' AND status = 'open'"),
                'suspicious' => (int) $db->value("SELECT COUNT(*) FROM users WHERE status = 'suspicious'"),
                'card_reuse' => (int) $db->value("SELECT COUNT(*) FROM security_events WHERE type = 'card_reuse'"),
            ],
        ]);
    }

    public function review(array $params): void
    {
        $id = (int) $params['id'];
        $status = (string) ($_POST['status'] ?? 'reviewed');
        if (!in_array($status, ['reviewed', 'dismissed', 'confirmed'], true)) {
            $this->fail('وضعیت نامعتبر است.');
            return;
        }
        $db = Database::instance();
        $expectedReferrer = (int) ($_POST['expected_referrer'] ?? 0);
        try {
            $result = $db->transaction(function () use ($db, $id, $status, $expectedReferrer): array {
                $event = $db->lockRow('security_events', $id);
                if (!$event) throw new \RuntimeException('رویداد یافت نشد.');
                if ($event['status'] !== 'open') throw new \RuntimeException('این رویداد قبلاً رسیدگی شده است؛ صفحه را تازه کنید.');
                $subjectId = (int) ($event['user_id'] ?? 0);
                $responsibleId = null; $oldStatus = null; $newStatus = null;
                if ($status === 'confirmed' && $event['type'] === 'referral_burst') {
                    $details = json_decode((string) ($event['details'] ?? ''), true);
                    $eventReferrer = (int) (is_array($details) ? ($details['referrer_id'] ?? 0) : 0);
                    if ($eventReferrer <= 0) throw new \RuntimeException('معرف زمان وقوع رویداد قدیمی ثبت نشده است؛ ابتدا سابقه را دستی بررسی کنید و از تأیید خودکار معرف استفاده نکنید.');
                    $subject = $subjectId > 0 ? $db->lockRow('users', $subjectId) : null;
                    $responsibleId = (int)($subject['referred_by'] ?? 0);
                    if (!$subject || $responsibleId <= 0 || $responsibleId === $subjectId) throw new \RuntimeException('معرف مستقیمی برای این رویداد مشخص نیست؛ تخلف به نام کسی ثبت نشد.');
                    if ($responsibleId !== $eventReferrer || $responsibleId !== $expectedReferrer) throw new \RuntimeException('معرف تغییر کرده است؛ صفحه را تازه کرده و دوباره بررسی کنید.');
                    $inviter = $db->lockRow('users', $responsibleId);
                    if (!$inviter) throw new \RuntimeException('معرف یافت نشد؛ تغییری انجام نشد.');
                    $oldStatus = (string)$inviter['status'];
                    // Never unblock a blocked account or alter any wallet.
                    $newStatus = $oldStatus === 'active' ? 'suspicious' : $oldStatus;
                    if ($newStatus !== $oldStatus) {
                        $db->update('users', ['status'=>$newStatus, 'updated_at'=>now()], 'id = :id', ['id'=>$responsibleId]);
                    }
                }
                $db->update('security_events', [
                    'status'=>$status, 'responsible_user_id'=>$responsibleId,
                    'reviewed_by'=>current_admin_id(), 'reviewed_at'=>now(),
                ], 'id = :id', ['id'=>$id]);
                AuditService::log('security.reviewed', 'security_event', $id, [
                    'status'=>$status, 'subject_user_id'=>$subjectId ?: null,
                    'responsible_user_id'=>$responsibleId, 'inviter_status_before'=>$oldStatus, 'inviter_status_after'=>$newStatus,
                ], current_admin_id());
                return ['responsible_user_id'=>$responsibleId, 'inviter_status'=>$newStatus];
            });
            $msg = $status === 'confirmed' && $result['responsible_user_id'] !== null ? 'تخلف به نام معرف ثبت شد؛ حساب او در صورت فعال بودن مشکوک علامت‌گذاری شد.' : 'رویداد امنیتی بررسی شد؛ حساب معرف تغییر نکرد.';
            $this->ok($msg, ['reload'=>true] + $result);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 409);
        }
    }

    public function logs(): void
    {
        $db = Database::instance();
        $q = trim((string) ($_GET['q'] ?? ''));
        $adminId = (int) ($_GET['admin'] ?? 0);
        $where = [];
        $bind = [];
        if ($q !== '') {
            $where[] = '(action LIKE :q OR admin_name LIKE :q2 OR details LIKE :q3)';
            $bind += ['q' => '%' . $q . '%', 'q2' => '%' . $q . '%', 'q3' => '%' . $q . '%'];
        }
        if ($adminId > 0) {
            $where[] = 'admin_id = :aid';
            $bind['aid'] = $adminId;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $page = $this->page();
        $perPage = 30;
        $total = (int) $db->value("SELECT COUNT(*) FROM admin_activity_logs {$whereSql}", $bind);
        $logs = $db->all(
            "SELECT * FROM admin_activity_logs {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $bind
        );
        $this->render('admin/security/logs', [
            'title' => 'گزارش فعالیت مدیران',
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'q' => $q,
            'adminId' => $adminId,
            'admins' => $db->all('SELECT id, name FROM admins ORDER BY id'),
        ]);
    }
}

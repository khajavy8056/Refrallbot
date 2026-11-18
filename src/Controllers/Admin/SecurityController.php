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
        $status = (string) ($_GET['status'] ?? '');
        $where = $status !== '' ? ' WHERE se.status = :s' : '';
        $bind = $status !== '' ? ['s' => $status] : [];

        $events = $db->all(
            'SELECT se.*, u.first_name, u.last_name, u.username, u.telegram_id, u.risk_score, u.status AS user_status
             FROM security_events se LEFT JOIN users u ON u.id = se.user_id' . $where .
            ' ORDER BY se.id DESC LIMIT 200',
            $bind
        );
        $suspiciousUsers = $db->all(
            "SELECT * FROM users WHERE status = 'suspicious' OR risk_score >= 20 ORDER BY risk_score DESC LIMIT 50"
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
        $db->update('security_events', [
            'status' => $status,
            'reviewed_by' => current_admin_id(),
            'reviewed_at' => now(),
        ], 'id = :id', ['id' => $id]);
        AuditService::log('security.reviewed', 'security_event', $id, ['status' => $status], current_admin_id());
        $this->ok('رویداد امنیتی بررسی شد.', ['reload' => true]);
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

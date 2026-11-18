<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Services\WithdrawalService;
use RB\Support\Database;
use RB\Support\Request;

/**
 * گزارش‌های مدیریتی + خروجی CSV
 */
final class ReportController extends AdminController
{
    public const TYPES = [
        'transactions' => 'تراکنش‌های مالی',
        'commissions' => 'پورسانت‌ها و لایه‌ها',
        'withdrawals' => 'برداشت‌ها',
        'referrals' => 'معرفی‌ها و شبکه',
        'users' => 'کاربران',
        'growth' => 'رشد کاربران',
        'campaigns' => 'کمپین‌ها',
        'broadcasts' => 'پیام‌های همگانی',
        'suspicious' => 'کاربران مشکوک',
    ];

    public function index(array $params): void
    {
        $type = (string) ($params['type'] ?? 'transactions');
        if (!array_key_exists($type, self::TYPES)) {
            Request::redirect(url('admin/reports/transactions'));
            return;
        }
        [$rows, $columns, $totals] = $this->build($type, 500);

        $this->render('admin/reports/index', [
            'title' => 'گزارش: ' . self::TYPES[$type],
            'type' => $type,
            'types' => self::TYPES,
            'rows' => $rows,
            'columns' => $columns,
            'totals' => $totals,
            'filters' => $this->filters(['q', 'status', 'from', 'to']),
        ]);
    }

    public function export(array $params): void
    {
        $type = (string) ($params['type'] ?? 'transactions');
        if (!array_key_exists($type, self::TYPES)) {
            http_response_code(404);
            return;
        }
        [$rows, $columns] = $this->build($type, 50000);

        $filename = 'refrallbot-' . $type . '-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        // BOM برای باز شدن صحیح فارسی در Excel
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(static fn($c) => $c['label'], $columns));
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $key => $meta) {
                $line[] = $row[$key] ?? '';
            }
            fputcsv($out, $line);
        }
        fclose($out);
    }

    /**
     * @return array{0:array,1:array,2:array}
     */
    private function build(string $type, int $limit): array
    {
        $db = Database::instance();
        $from = $this->dateFilter('from');
        $to = $this->dateFilter('to');
        $q = trim((string) ($_GET['q'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));

        $where = [];
        $bind = [];
        if ($from) { $where[] = 'created_at >= :from'; $bind['from'] = $from . ' 00:00:00'; }
        if ($to) { $where[] = 'created_at <= :to'; $bind['to'] = $to . ' 23:59:59'; }

        switch ($type) {
            case 'transactions':
                $sql = 'SELECT wt.*, u.first_name, u.last_name, u.username, u.telegram_id
                        FROM wallet_transactions wt LEFT JOIN users u ON u.id = wt.user_id';
                $w = $where;
                if ($q !== '') {
                    $w[] = '(u.first_name LIKE :q OR u.username LIKE :q2 OR wt.tx_code LIKE :q3)';
                    $bind['q'] = '%' . $q . '%';
                    $bind['q2'] = '%' . $q . '%';
                    $bind['q3'] = '%' . $q . '%';
                }
                if ($status !== '') { $w[] = 'wt.type = :type'; $bind['type'] = $status; }
                $sql .= ($w ? ' WHERE ' . implode(' AND ', array_map(static fn($c) => str_replace('created_at', 'wt.created_at', $c), $w)) : '');
                $rows = $db->all($sql . ' ORDER BY wt.id DESC LIMIT ' . $limit, $bind);
                $columns = [
                    'tx_code' => ['label' => 'کد تراکنش'],
                    'user' => ['label' => 'کاربر'],
                    'telegram_id' => ['label' => 'شناسه تلگرام'],
                    'type_label' => ['label' => 'نوع'],
                    'amount' => ['label' => 'مبلغ (تومان)'],
                    'direction' => ['label' => 'جهت'],
                    'status_label' => ['label' => 'وضعیت'],
                    'level' => ['label' => 'سطح'],
                    'description' => ['label' => 'توضیح'],
                    'created_at' => ['label' => 'تاریخ'],
                ];
                foreach ($rows as &$row) {
                    $row['user'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    $row['type_label'] = \RB\Bot\TransactionLabels::label((string) $row['type']);
                    $row['status_label'] = \RB\Bot\TransactionLabels::status((string) $row['status']);
                    $row['direction'] = $row['direction'] === 'credit' ? 'واریز' : 'برداشت';
                    $row['created_at'] = fa_date($row['created_at']);
                }
                unset($row);
                $totals = ['amount' => (int) $db->value('SELECT COALESCE(SUM(amount),0) FROM wallet_transactions wt '
                    . ($where ? 'WHERE ' . implode(' AND ', array_map(static fn($c) => str_replace('created_at', 'wt.created_at', $c), $where)) : ''), $bind)];
                return [$rows, $columns, $totals];

            case 'commissions':
                $rows = $db->all(
                    'SELECT rc.*, u.first_name, u.last_name, su.first_name AS src_first, su.username AS src_username
                     FROM referral_commissions rc
                     LEFT JOIN users u ON u.id = rc.beneficiary_id
                     LEFT JOIN users su ON su.id = rc.source_user_id
                     ORDER BY rc.id DESC LIMIT ' . $limit
                );
                $columns = [
                    'id' => ['label' => 'شناسه'],
                    'beneficiary' => ['label' => 'ذی‌نفع'],
                    'level' => ['label' => 'سطح'],
                    'source' => ['label' => 'منبع (کاربر معرفی‌شده)'],
                    'base_amount' => ['label' => 'مبلغ پایه'],
                    'percent' => ['label' => 'درصد'],
                    'amount' => ['label' => 'مبلغ پورسانت'],
                    'status' => ['label' => 'وضعیت'],
                    'created_at' => ['label' => 'تاریخ'],
                ];
                $byLevel = [];
                foreach ($rows as &$row) {
                    $row['beneficiary'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    $row['source'] = trim(($row['src_first'] ?? '') . ' ' . ($row['src_username'] ? '@' . $row['src_username'] : ''));
                    $row['status'] = $row['status'] === 'approved' ? 'پرداخت‌شده' : 'برگشت‌خورده';
                    $row['created_at'] = fa_date($row['created_at']);
                    $byLevel[(int) $row['level']] = ($byLevel[(int) $row['level']] ?? 0) + (int) $row['amount'];
                }
                unset($row);
                return [$rows, $columns, ['amount' => array_sum($byLevel), 'by_level' => $byLevel]];

            case 'withdrawals':
                $rows = $db->all(
                    'SELECT w.*, u.first_name, u.last_name, u.username, u.telegram_id
                     FROM withdrawal_requests w JOIN users u ON u.id = w.user_id
                     ORDER BY w.id DESC LIMIT ' . $limit
                );
                $columns = [
                    'request_code' => ['label' => 'کد درخواست'],
                    'user' => ['label' => 'کاربر'],
                    'telegram_id' => ['label' => 'شناسه تلگرام'],
                    'amount' => ['label' => 'مبلغ'],
                    'card_last4' => ['label' => '۴ رقم آخر کارت'],
                    'bank_name' => ['label' => 'بانک'],
                    'status' => ['label' => 'وضعیت'],
                    'tracking_code' => ['label' => 'کد پیگیری'],
                    'reject_reason' => ['label' => 'دلیل رد'],
                    'created_at' => ['label' => 'تاریخ ثبت'],
                    'paid_at' => ['label' => 'تاریخ پرداخت'],
                ];
                $total = 0;
                foreach ($rows as &$row) {
                    $row['user'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    $row['status'] = WithdrawalService::statusLabel((string) $row['status']);
                    $row['created_at'] = fa_date($row['created_at']);
                    $row['paid_at'] = fa_date($row['paid_at']);
                    $total += (int) $row['amount'];
                }
                unset($row);
                return [$rows, $columns, ['amount' => $total]];

            case 'referrals':
                $rows = $db->all(
                    'SELECT u.id, u.first_name, u.last_name, u.username, u.telegram_id, u.joined_at, u.status,
                            u.qualified_at, u.total_earned, r.first_name AS ref_first, r.username AS ref_username, r.referral_code AS ref_code,
                            (SELECT MAX(depth) FROM referral_relations rr WHERE rr.descendant_id = u.id) AS level
                     FROM users u LEFT JOIN users r ON r.id = u.referred_by
                     WHERE u.referred_by IS NOT NULL ORDER BY u.id DESC LIMIT ' . $limit
                );
                $columns = [
                    'user' => ['label' => 'کاربر'],
                    'telegram_id' => ['label' => 'شناسه تلگرام'],
                    'referrer' => ['label' => 'معرف'],
                    'ref_code' => ['label' => 'کد دعوت معرف'],
                    'level' => ['label' => 'سطح در شبکه'],
                    'qualified' => ['label' => 'وضعیت پاداش'],
                    'total_earned' => ['label' => 'درآمد'],
                    'joined_at' => ['label' => 'تاریخ عضویت'],
                ];
                foreach ($rows as &$row) {
                    $row['user'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    $row['referrer'] = trim(($row['ref_first'] ?? '') . ' ' . ($row['ref_username'] ? '@' . $row['ref_username'] : ''));
                    $row['qualified'] = $row['qualified_at'] ? 'واجد شرایط' : 'در انتظار';
                    $row['joined_at'] = fa_date($row['joined_at']);
                }
                unset($row);
                return [$rows, $columns, ['total' => count($rows)]];

            case 'users':
                $rows = $db->all('SELECT * FROM users ORDER BY id DESC LIMIT ' . $limit);
                $columns = [
                    'id' => ['label' => 'شناسه'],
                    'telegram_id' => ['label' => 'شناسه تلگرام'],
                    'first_name' => ['label' => 'نام'],
                    'username' => ['label' => 'نام کاربری'],
                    'phone' => ['label' => 'شماره تماس'],
                    'referral_code' => ['label' => 'کد دعوت'],
                    'direct_ref_count' => ['label' => 'زیرمجموعه مستقیم'],
                    'total_ref_count' => ['label' => 'کل شبکه'],
                    'total_earned' => ['label' => 'درآمد کل'],
                    'wallet_balance' => ['label' => 'موجودی'],
                    'status' => ['label' => 'وضعیت'],
                    'joined_at' => ['label' => 'تاریخ عضویت'],
                ];
                foreach ($rows as &$row) {
                    $row['joined_at'] = fa_date($row['joined_at']);
                }
                unset($row);
                return [$rows, $columns, ['total' => count($rows)]];

            case 'growth':
                $rows = $db->all(
                    'SELECT DATE(joined_at) AS day, COUNT(*) AS users FROM users
                     WHERE joined_at >= :s GROUP BY DATE(joined_at) ORDER BY day DESC',
                    ['s' => date('Y-m-d 00:00:00', time() - 90 * 86400)]
                );
                foreach ($rows as &$row) {
                    $row['day_fa'] = fa_date($row['day'], false);
                }
                unset($row);
                return [$rows, ['day_fa' => ['label' => 'تاریخ'], 'users' => ['label' => 'کاربران جدید']], ['users' => array_sum(array_column($rows, 'users'))]];

            case 'campaigns':
                $rows = $db->all(
                    'SELECT c.*, (SELECT COUNT(*) FROM campaign_participants cp WHERE cp.campaign_id = c.id) AS participants,
                            (SELECT COALESCE(SUM(amount),0) FROM campaign_rewards cr WHERE cr.campaign_id = c.id) AS paid
                     FROM campaigns c ORDER BY c.id DESC'
                );
                foreach ($rows as &$row) {
                    $row['starts_at'] = fa_date($row['starts_at'], false);
                    $row['ends_at'] = fa_date($row['ends_at'], false);
                    $row['is_active'] = $row['is_active'] ? 'فعال' : 'غیرفعال';
                }
                unset($row);
                return [$rows, [
                    'title' => ['label' => 'عنوان'], 'type' => ['label' => 'نوع'], 'is_active' => ['label' => 'وضعیت'],
                    'starts_at' => ['label' => 'شروع'], 'ends_at' => ['label' => 'پایان'],
                    'participants' => ['label' => 'شرکت‌کنندگان'], 'paid' => ['label' => 'جوایز پرداختی'],
                ], ['paid' => array_sum(array_column($rows, 'paid'))]];

            case 'broadcasts':
                $rows = $db->all('SELECT * FROM broadcast_campaigns ORDER BY id DESC LIMIT 200');
                foreach ($rows as &$row) {
                    $row['started_at'] = fa_date($row['started_at']);
                    $row['finished_at'] = fa_date($row['finished_at']);
                }
                unset($row);
                return [$rows, [
                    'title' => ['label' => 'عنوان'], 'status' => ['label' => 'وضعیت'],
                    'total_targets' => ['label' => 'مخاطبان'], 'sent_count' => ['label' => 'موفق'],
                    'failed_count' => ['label' => 'ناموفق'], 'blocked_count' => ['label' => 'بلاک‌کننده'],
                    'started_at' => ['label' => 'شروع'], 'finished_at' => ['label' => 'پایان'],
                ], []];

            case 'suspicious':
            default:
                $rows = $db->all(
                    "SELECT se.*, u.first_name, u.last_name, u.username, u.risk_score, u.status AS user_status,
                            u.telegram_id AS user_telegram_id
                     FROM security_events se LEFT JOIN users u ON u.id = se.user_id
                     ORDER BY se.id DESC LIMIT " . $limit
                );
                foreach ($rows as &$row) {
                    $row['user'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    $row['type_label'] = SecurityController::typeLabel((string) $row['type']);
                    $row['status'] = SecurityController::statusLabel((string) $row['status']);
                    $row['created_at'] = fa_date($row['created_at']);
                }
                unset($row);
                return [$rows, [
                    'id' => ['label' => 'شناسه'], 'user' => ['label' => 'کاربر'], 'user_telegram_id' => ['label' => 'شناسه کاربر'],
                    'type_label' => ['label' => 'نوع رویداد'], 'severity' => ['label' => 'شدت'],
                    'status' => ['label' => 'وضعیت'], 'risk_score' => ['label' => 'امتیاز ریسک'],
                    'created_at' => ['label' => 'تاریخ'],
                ], []];
        }
    }
}

<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Bot\TransactionLabels;
use RB\Http\AdminController;
use RB\Messengers\MessengerCapabilities;
use RB\Services\StatsService;
use RB\Services\WithdrawalService;
use RB\Support\Database;
use RB\Support\Request;

/**
 * گزارش‌های مدیریتی و مالی چندپیام‌رسانی + خروجی CSV
 */
final class ReportController extends AdminController
{
    public const TYPES = [
        'layers'       => 'تفکیک مالی لایه‌ها (L0..L8)',
        'messengers'   => 'عملکرد پیام‌رسان‌ها',
        'transactions' => 'تراکنش‌های مالی',
        'commissions'  => 'ریز پورسانت‌ها',
        'withdrawals'  => 'برداشت‌ها',
        'referrals'    => 'معرفی‌ها و شبکه',
        'users'        => 'کاربران',
        'growth'       => 'رشد کاربران',
        'campaigns'    => 'کمپین‌ها',
        'broadcasts'   => 'پیام‌های همگانی',
        'suspicious'   => 'کاربران مشکوک',
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
            'filters' => $this->filters(['q', 'status', 'platform', 'from', 'to']),
        ]);
    }

    public function export(array $params): void
    {
        $type = (string) ($params['type'] ?? 'transactions');
        if (!array_key_exists($type, self::TYPES)) {
            if (!headers_sent()) {
                http_response_code(404);
            }
            return;
        }
        [$rows, $columns] = $this->build($type, 50000);

        $filename = 'refrallbot-' . $type . '-' . date('Ymd-His') . '.csv';
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store');
        }

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(static fn($c) => $c['label'], $columns), ',', '"', '');
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $key => $meta) {
                $line[] = $row[$key] ?? '';
            }
            fputcsv($out, $line, ',', '"', '');
        }
        fclose($out);
    }

    /** @return array{0:array,1:array,2:array} */
    private function build(string $type, int $limit): array
    {
        $db = Database::instance();
        $from = $this->dateFilter('from');
        $to = $this->dateFilter('to');
        $q = trim((string) ($_GET['q'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $platform = strtolower(trim((string) ($_GET['platform'] ?? '')));

        $where = [];
        $bind = [];
        if ($from) { $where[] = 'created_at >= :from'; $bind['from'] = $from . ' 00:00:00'; }
        if ($to)   { $where[] = 'created_at <= :to';   $bind['to'] = $to . ' 23:59:59'; }

        switch ($type) {
            case 'layers':
                $rep = StatsService::layerFinancialReport($db);
                $rows = [];
                foreach ($rep['layers'] as $lr) {
                    $rows[] = [
                        'label' => $lr['label'],
                        'rule_summary' => $lr['rule_summary'],
                        'users_count' => (int) $lr['users_count'],
                        'qualified_count' => (int) $lr['qualified_count'],
                        'tx_count' => (int) $lr['tx_count'],
                        'paid_amount' => (int) $lr['paid_amount'],
                        'pending_amount' => (int) $lr['pending_amount'],
                        'total_amount' => (int) $lr['total_amount'],
                    ];
                }
                if (!empty($rep['welcome'])) {
                    $w = $rep['welcome'];
                    $rows[] = [
                        'label' => '🎁 هدیه خوش‌آمدگویی (کاربر دعوت‌شده)',
                        'rule_summary' => (string) ($w['rule_summary'] ?? 'مبلغ ثابت'),
                        'users_count' => (int) $rep['direct_users'],
                        'qualified_count' => (int) ($w['tx_count'] ?? 0),
                        'tx_count' => (int) ($w['tx_count'] ?? 0),
                        'paid_amount' => (int) ($w['paid_amount'] ?? 0),
                        'pending_amount' => (int) ($w['pending_amount'] ?? 0),
                        'total_amount' => (int) ($w['total_amount'] ?? 0),
                    ];
                }
                $columns = [
                    'label' => ['label' => 'لایه / بخش'],
                    'rule_summary' => ['label' => 'قانون فعلی محاسبه'],
                    'users_count' => ['label' => 'تعداد زیرمجموعه'],
                    'qualified_count' => ['label' => 'واجد شرایط'],
                    'tx_count' => ['label' => 'تعداد پرداخت'],
                    'paid_amount' => ['label' => 'واریزشده (تومان)'],
                    'pending_amount' => ['label' => 'در انتظار (تومان)'],
                    'total_amount' => ['label' => 'جمع کل (تومان)'],
                ];
                return [$rows, $columns, [
                    'amount' => (int) $rep['total_paid'],
                    'summary' => $rep,
                ]];

            case 'messengers':
                $rep = StatsService::messengerPerformanceReport($db);
                $rows = [];
                $sumPaid = 0;
                foreach ($rep as $mr) {
                    $sumPaid += (int) $mr['total_paid'];
                    $rows[] = [
                        'messenger' => ($mr['icon'] ?? '') . ' ' . $mr['name'],
                        'status' => (string) ($mr['status_badge']['label'] ?? '—'),
                        'ui_mode_label' => match ((string) ($mr['ui_mode']['effective'] ?? 'buttons')) {
                            'miniapp' => 'فقط مینی‌اپ',
                            'hybrid'  => 'ترکیبی',
                            default   => 'فقط دکمه‌ها',
                        },
                        'registered_users' => (int) $mr['registered_users'],
                        'linked_accounts' => (int) $mr['linked_accounts'],
                        'direct_referrals' => (int) $mr['direct_referrals'],
                        'qualified_referrals' => (int) $mr['qualified_referrals'],
                        'direct_paid' => (int) $mr['direct_paid'],
                        'indirect_paid' => (int) $mr['indirect_paid'],
                        'welcome_paid' => (int) $mr['welcome_paid'],
                        'total_paid' => (int) $mr['total_paid'],
                    ];
                }
                $columns = [
                    'messenger' => ['label' => 'پیام‌رسان'],
                    'status' => ['label' => 'وضعیت اتصال'],
                    'ui_mode_label' => ['label' => 'رابط کاربری مؤثر'],
                    'registered_users' => ['label' => 'کاربران ورودی'],
                    'linked_accounts' => ['label' => 'حساب‌های متصل'],
                    'direct_referrals' => ['label' => 'دعوت‌ها'],
                    'qualified_referrals' => ['label' => 'واجد شرایط'],
                    'direct_paid' => ['label' => 'مستقیم L0'],
                    'indirect_paid' => ['label' => 'غیرمستقیم L1+'],
                    'welcome_paid' => ['label' => 'خوش‌آمدگویی'],
                    'total_paid' => ['label' => 'کل پرداختی'],
                ];
                return [$rows, $columns, ['amount' => $sumPaid]];

            case 'transactions':
                $sql = 'SELECT wt.*, u.first_name, u.last_name, u.username, u.telegram_id, u.entry_platform
                        FROM wallet_transactions wt LEFT JOIN users u ON u.id = wt.user_id';
                $w = $where;
                if ($q !== '') {
                    $w[] = '(u.first_name LIKE :q OR u.username LIKE :q2 OR wt.tx_code LIKE :q3)';
                    $bind['q'] = '%' . $q . '%';
                    $bind['q2'] = '%' . $q . '%';
                    $bind['q3'] = '%' . $q . '%';
                }
                if ($status !== '') { $w[] = 'wt.type = :type'; $bind['type'] = $status; }
                if ($platform !== '' && MessengerCapabilities::isSupported($platform)) {
                    $w[] = 'u.entry_platform = :plat';
                    $bind['plat'] = $platform;
                }
                $whereSql = $w ? ' WHERE ' . implode(' AND ', array_map(static fn($c) => str_replace('created_at', 'wt.created_at', $c), $w)) : '';
                $rows = $db->all($sql . $whereSql . ' ORDER BY wt.id DESC LIMIT ' . $limit, $bind);
                $columns = [
                    'tx_code' => ['label' => 'کد تراکنش'],
                    'user' => ['label' => 'کاربر'],
                    'platform_label' => ['label' => 'پیام‌رسان'],
                    'type_label' => ['label' => 'نوع'],
                    'amount' => ['label' => 'مبلغ (تومان)'],
                    'direction' => ['label' => 'جهت'],
                    'status_label' => ['label' => 'وضعیت'],
                    'layer_label' => ['label' => 'لایه'],
                    'description' => ['label' => 'توضیح'],
                    'created_at' => ['label' => 'تاریخ'],
                ];
                $sum = 0;
                foreach ($rows as &$row) {
                    $sum += (int) $row['amount'];
                    $row['user'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    $row['platform_label'] = MessengerCapabilities::label((string) ($row['entry_platform'] ?? 'telegram'));
                    $row['type_label'] = TransactionLabels::label((string) $row['type']);
                    $row['status_label'] = TransactionLabels::status((string) $row['status']);
                    $row['direction'] = $row['direction'] === 'credit' ? 'واریز' : 'برداشت';
                    $lv = $row['level'];
                    $row['layer_label'] = $lv === null ? '—' : ((int) $lv === 0 ? 'L0 مستقیم' : ('L' . fa_digits((int) $lv)));
                    $row['created_at'] = fa_date($row['created_at']);
                }
                unset($row);
                return [$rows, $columns, ['amount' => $sum]];

            case 'commissions':
                $sql = 'SELECT rc.*, re.platform AS event_platform,
                               u.first_name, u.last_name, su.first_name AS src_first, su.username AS src_username
                        FROM referral_commissions rc
                        LEFT JOIN referral_events re ON re.id = rc.event_id
                        LEFT JOIN users u ON u.id = rc.beneficiary_id
                        LEFT JOIN users su ON su.id = rc.source_user_id';
                $cw = [];
                $cb = [];
                if ($from) { $cw[] = 'rc.created_at >= :from'; $cb['from'] = $from . ' 00:00:00'; }
                if ($to)   { $cw[] = 'rc.created_at <= :to';   $cb['to'] = $to . ' 23:59:59'; }
                if ($platform !== '' && MessengerCapabilities::isSupported($platform)) {
                    $cw[] = 're.platform = :plat';
                    $cb['plat'] = $platform;
                }
                $rows = $db->all($sql . ($cw ? ' WHERE ' . implode(' AND ', $cw) : '') . ' ORDER BY rc.id DESC LIMIT ' . $limit, $cb);
                $columns = [
                    'id' => ['label' => 'شناسه'],
                    'beneficiary' => ['label' => 'ذی‌نفع'],
                    'layer_label' => ['label' => 'نوع / لایه'],
                    'platform_label' => ['label' => 'پیام‌رسان'],
                    'source' => ['label' => 'کاربر معرفی‌شده'],
                    'calc_label' => ['label' => 'نحوه محاسبه'],
                    'amount' => ['label' => 'مبلغ پورسانت'],
                    'status' => ['label' => 'وضعیت'],
                    'created_at' => ['label' => 'تاریخ'],
                ];
                $totalCredited = 0;
                foreach ($rows as &$row) {
                    $row['beneficiary'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    $row['source'] = trim(($row['src_first'] ?? '') . ' ' . (!empty($row['src_username']) ? '@' . $row['src_username'] : ''));
                    $row['platform_label'] = MessengerCapabilities::label((string) ($row['event_platform'] ?? 'telegram'));
                    $t = (string) ($row['type'] ?? 'level');
                    $lv = (int) ($row['level'] ?? 0);
                    $row['layer_label'] = match ($t) {
                        'direct'  => 'لایه ۰ (دعوت مستقیم)',
                        'welcome' => 'هدیه خوش‌آمدگویی',
                        default   => 'لایه ' . fa_digits($lv) . ' (غیرمستقیم)',
                    };
                    $pct = (float) ($row['percent'] ?? 0);
                    $row['calc_label'] = ($t === 'level' && $pct > 0)
                        ? (fa_digits((string) $row['percent']) . '٪ از ' . wtoman((int) $row['base_amount']))
                        : 'مبلغ ثابت';
                    $st = (string) ($row['status'] ?? '');
                    if (in_array($st, ['approved', 'credited'], true)) {
                        $totalCredited += (int) $row['amount'];
                    }
                    $row['status'] = match ($st) {
                        'credited', 'approved' => 'واریزشده',
                        'pending'              => 'در انتظار',
                        'rejected'             => 'رد/برگشت',
                        default                => $st,
                    };
                    $row['created_at'] = fa_date($row['created_at']);
                }
                unset($row);
                return [$rows, $columns, ['amount' => $totalCredited]];

            case 'withdrawals':
                $rows = $db->all(
                    'SELECT w.*, u.first_name, u.last_name, u.username, u.telegram_id, u.entry_platform
                     FROM withdrawal_requests w JOIN users u ON u.id = w.user_id
                     ORDER BY w.id DESC LIMIT ' . $limit
                );
                $columns = [
                    'request_code' => ['label' => 'کد درخواست'],
                    'user' => ['label' => 'کاربر'],
                    'platform_label' => ['label' => 'پیام‌رسان'],
                    'amount' => ['label' => 'مبلغ'],
                    'card_last4' => ['label' => '۴ رقم آخر کارت'],
                    'bank_name' => ['label' => 'بانک'],
                    'status' => ['label' => 'وضعیت'],
                    'tracking_code' => ['label' => 'کد پیگیری'],
                    'created_at' => ['label' => 'تاریخ ثبت'],
                    'paid_at' => ['label' => 'تاریخ پرداخت'],
                ];
                $total = 0;
                foreach ($rows as &$row) {
                    $row['user'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    $row['platform_label'] = MessengerCapabilities::label((string) ($row['entry_platform'] ?? 'telegram'));
                    $row['status'] = WithdrawalService::statusLabel((string) $row['status']);
                    $row['created_at'] = fa_date($row['created_at']);
                    $row['paid_at'] = fa_date($row['paid_at']);
                    $total += (int) $row['amount'];
                }
                unset($row);
                return [$rows, $columns, ['amount' => $total]];

            case 'referrals':
                $rows = $db->all(
                    'SELECT u.id, u.first_name, u.last_name, u.username, u.entry_platform, u.referral_source_platform,
                            u.joined_at, u.status, u.qualified_at, u.total_earned,
                            r.first_name AS ref_first, r.username AS ref_username, r.referral_code AS ref_code,
                            (SELECT MAX(depth) FROM referral_relations rr WHERE rr.descendant_id = u.id) AS depth
                     FROM users u LEFT JOIN users r ON r.id = u.referred_by
                     WHERE u.referred_by IS NOT NULL ORDER BY u.id DESC LIMIT ' . $limit
                );
                $columns = [
                    'user' => ['label' => 'کاربر دعوت‌شده'],
                    'platform_label' => ['label' => 'پیام‌رسان ورود'],
                    'referrer' => ['label' => 'معرف مستقیم (L0)'],
                    'ref_code' => ['label' => 'کد دعوت'],
                    'depth' => ['label' => 'عمق در درخت'],
                    'qualified' => ['label' => 'وضعیت پاداش'],
                    'total_earned' => ['label' => 'درآمد کاربر'],
                    'joined_at' => ['label' => 'تاریخ عضویت'],
                ];
                foreach ($rows as &$row) {
                    $row['user'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    $row['platform_label'] = MessengerCapabilities::label((string) ($row['referral_source_platform'] ?: ($row['entry_platform'] ?? 'telegram')));
                    $row['referrer'] = trim(($row['ref_first'] ?? '') . ' ' . (!empty($row['ref_username']) ? '@' . $row['ref_username'] : ''));
                    $row['qualified'] = $row['qualified_at'] ? 'واجد شرایط' : 'در انتظار';
                    $row['joined_at'] = fa_date($row['joined_at']);
                }
                unset($row);
                return [$rows, $columns, ['total' => count($rows)]];

            case 'users':
                $uw = [];
                $ub = [];
                if ($platform !== '' && MessengerCapabilities::isSupported($platform)) {
                    $uw[] = 'entry_platform = :plat';
                    $ub['plat'] = $platform;
                }
                $rows = $db->all('SELECT * FROM users' . ($uw ? ' WHERE ' . implode(' AND ', $uw) : '') . ' ORDER BY id DESC LIMIT ' . $limit, $ub);
                $columns = [
                    'id' => ['label' => 'شناسه'],
                    'platform_label' => ['label' => 'پیام‌رسان اصلی'],
                    'first_name' => ['label' => 'نام'],
                    'username' => ['label' => 'نام کاربری'],
                    'phone' => ['label' => 'شماره تماس'],
                    'referral_code' => ['label' => 'کد دعوت'],
                    'direct_ref_count' => ['label' => 'مستقیم (L0)'],
                    'indirect_ref_count' => ['label' => 'غیرمستقیم (L1+)'],
                    'total_earned' => ['label' => 'درآمد کل'],
                    'wallet_balance' => ['label' => 'موجودی'],
                    'status' => ['label' => 'وضعیت'],
                    'joined_at' => ['label' => 'تاریخ عضویت'],
                ];
                foreach ($rows as &$row) {
                    $row['platform_label'] = MessengerCapabilities::label((string) ($row['entry_platform'] ?? 'telegram'));
                    $row['indirect_ref_count'] = max(0, (int) $row['total_ref_count'] - (int) $row['direct_ref_count']);
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
                    $tp = (string) ($row['target_platform'] ?? 'all');
                    $row['target_label'] = $tp === 'all' ? 'همه پیام‌رسان‌ها' : MessengerCapabilities::label($tp);
                    $row['started_at'] = fa_date($row['started_at']);
                    $row['finished_at'] = fa_date($row['finished_at']);
                }
                unset($row);
                return [$rows, [
                    'title' => ['label' => 'عنوان'], 'target_label' => ['label' => 'پیام‌رسان'], 'status' => ['label' => 'وضعیت'],
                    'total_targets' => ['label' => 'مخاطبان'], 'sent_count' => ['label' => 'موفق'],
                    'failed_count' => ['label' => 'ناموفق'], 'blocked_count' => ['label' => 'بلاک'],
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

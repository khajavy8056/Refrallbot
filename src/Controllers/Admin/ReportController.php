<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Bot\TransactionLabels;
use RB\Http\AdminController;
use RB\Messengers\MessengerCapabilities;
use RB\Messengers\MessengerRegistry;
use RB\Services\AuditService;
use RB\Services\MembershipService;
use RB\Services\StatsService;
use RB\Services\WithdrawalService;
use RB\Support\Database;
use RB\Support\Request;

/**
 * گزارش‌های مدیریتی، عملیاتی و مالی چندپیام‌رسانی + خروجی CSV
 *
 * تفکیک پیام‌رسان‌ها منحصراً برای پیام‌رسان‌های «فعال و متصل» انجام می‌شود
 * و در تمام گزارش‌ها مجموع کل سیستم نیز به‌صورت مستقل حفظ می‌گردد.
 */
final class ReportController extends AdminController
{
    public const TYPES = [
        'messengers'   => 'عملکرد پیام‌رسان‌های فعال',
        'layers'       => 'تفکیک مالی لایه‌ها (L0..L8)',
        'users'        => 'آمار کاربران',
        'growth'       => 'گزارش ثبت‌نام‌ها',
        'referrals'    => 'رفرال (مستقیم و غیرمستقیم)',
        'commissions'  => 'گزارش پاداش‌ها',
        'channels'     => 'عضویت کانال‌ها',
        'messages'     => 'پیام‌های ارسالی',
        'errors'       => 'خطاهای اتصال',
        'transactions' => 'تراکنش‌های مالی',
        'withdrawals'  => 'برداشت‌ها',
        'campaigns'    => 'کمپین‌ها',
        'broadcasts'   => 'پیام‌های همگانی',
        'suspicious'   => 'کاربران مشکوک',
    ];

    public const PLATFORM_FILTERABLE = [
        'users', 'growth', 'referrals', 'commissions', 'channels',
        'messages', 'errors', 'transactions', 'withdrawals', 'broadcasts',
    ];

    public function index(array $params): void
    {
        $type = (string) ($params['type'] ?? 'transactions');
        if (!array_key_exists($type, self::TYPES)) {
            Request::redirect(url('admin/reports/transactions'));
            return;
        }
        $db = Database::instance();
        $msgSummary = StatsService::segmentedMessengerSummary($db);
        $activeMessengers = $msgSummary['active'];
        $messengerTotals = $msgSummary['totals'];

        [$rows, $columns, $totals] = $this->build($type, 500, $activeMessengers, $messengerTotals);

        $filters = $this->filters(['q', 'status', 'platform', 'category', 'from', 'to']);
        $selPlat = strtolower(trim((string) ($filters['platform'] ?? '')));
        if ($selPlat !== '' && !isset($activeMessengers[$selPlat])) {
            $filters['platform'] = '';
        }

        $this->render('admin/reports/index', [
            'title' => 'گزارش: ' . self::TYPES[$type],
            'type' => $type,
            'types' => self::TYPES,
            'rows' => $rows,
            'columns' => $columns,
            'totals' => $totals,
            'filters' => $filters,
            'activeMessengers' => $activeMessengers,
            'messengerTotals' => $messengerTotals,
            'platformFilterable' => in_array($type, self::PLATFORM_FILTERABLE, true),
            'errorCategories' => MessengerRegistry::ERROR_CATEGORIES,
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
        $db = Database::instance();
        $msgSummary = StatsService::segmentedMessengerSummary($db);
        [$rows, $columns] = $this->build($type, 50000, $msgSummary['active'], $msgSummary['totals']);

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

    public function clearErrors(): void
    {
        $platform = strtolower(trim((string) ($_POST['platform'] ?? '')));
        if ($platform !== '' && !MessengerCapabilities::isSupported($platform)) {
            $this->fail('پیام‌رسان نامعتبر است.');
            return;
        }
        $reg = new MessengerRegistry();
        $deleted = $reg->clearErrors($platform !== '' ? $platform : null);
        AuditService::log('messenger.errors.clear', 'messenger', null, [
            'platform' => $platform ?: 'all',
            'deleted' => $deleted,
        ], current_admin_id());
        $this->ok(
            $deleted > 0
                ? (fa_digits($deleted) . ' رکورد خطای ارتباطی پاک‌سازی شد.')
                : 'رکوردی برای پاک‌سازی وجود نداشت.',
            ['reload' => true]
        );
    }

    /**
     * @param array<string,array<string,mixed>> $activeMessengers
     * @param array<string,mixed> $messengerTotals
     * @return array{0:array,1:array,2:array}
     */
    private function build(string $type, int $limit, array $activeMessengers, array $messengerTotals): array
    {
        $db = Database::instance();
        $from = $this->dateFilter('from');
        $to = $this->dateFilter('to');
        $q = trim((string) ($_GET['q'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $category = trim((string) ($_GET['category'] ?? ''));
        $rawPlatform = strtolower(trim((string) ($_GET['platform'] ?? '')));
        // فیلتر پیام‌رسان منحصراً برای پیام‌رسان‌های فعال و متصل اعمال می‌شود
        $platform = ($rawPlatform !== '' && isset($activeMessengers[$rawPlatform])) ? $rawPlatform : '';
        $activeKeys = array_keys($activeMessengers);

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
                $rows = [];
                foreach ($activeMessengers as $p => $mr) {
                    $botLabel = (string) ($mr['status_badge']['label'] ?? 'متصل');
                    if (!empty($mr['bot_username'])) {
                        $botLabel .= ' (@' . ltrim((string) $mr['bot_username'], '@') . ')';
                    }
                    $rows[] = [
                        'messenger' => MessengerCapabilities::badge((string) $p),
                        'status' => $botLabel,
                        'ui_mode_label' => match ((string) ($mr['ui_mode']['effective'] ?? 'buttons')) {
                            'miniapp' => 'فقط مینی‌اپ',
                            'hybrid'  => 'ترکیبی',
                            default   => 'فقط دکمه‌ها',
                        },
                        'registered_users' => (int) ($mr['independent_users'] ?? $mr['registered_users'] ?? 0),
                        'month_users' => (int) ($mr['month_users'] ?? 0),
                        'direct_referrals' => (int) ($mr['direct_referrals'] ?? 0),
                        'indirect_referrals' => (int) ($mr['indirect_referrals'] ?? 0),
                        'qualified_referrals' => (int) ($mr['qualified_referrals'] ?? 0),
                        'channel_members_total' => (int) ($mr['channel_members_total'] ?? 0),
                        'messages_sent' => (int) ($mr['messages_sent'] ?? 0),
                        'errors_24h' => (int) ($mr['errors_24h'] ?? 0),
                        'direct_paid' => (int) ($mr['direct_paid'] ?? 0),
                        'indirect_paid' => (int) ($mr['indirect_paid'] ?? 0),
                        'welcome_paid' => (int) ($mr['welcome_paid'] ?? 0),
                        'total_paid' => (int) ($mr['total_paid'] ?? 0),
                    ];
                }
                $columns = [
                    'messenger' => ['label' => 'پیام‌رسان'],
                    'status' => ['label' => 'وضعیت ربات متصل'],
                    'ui_mode_label' => ['label' => 'رابط کاربری'],
                    'registered_users' => ['label' => 'کاربران'],
                    'month_users' => ['label' => 'ثبت‌نام ۳۰روزه'],
                    'direct_referrals' => ['label' => 'دعوت مستقیم (L0)'],
                    'indirect_referrals' => ['label' => 'غیرمستقیم (L1+)'],
                    'qualified_referrals' => ['label' => 'واجد شرایط'],
                    'channel_members_total' => ['label' => 'عضویت کانال'],
                    'messages_sent' => ['label' => 'پیام ارسالی'],
                    'errors_24h' => ['label' => 'خطا (۲۴ساعت)'],
                    'direct_paid' => ['label' => 'پاداش مستقیم'],
                    'indirect_paid' => ['label' => 'پاداش غیرمستقیم'],
                    'welcome_paid' => ['label' => 'خوش‌آمدگویی'],
                    'total_paid' => ['label' => 'کل پاداش'],
                ];
                return [$rows, $columns, ['amount' => (int) ($messengerTotals['total_paid'] ?? 0)]];

            case 'channels':
                $rows = [];
                $totalVerified = 0;
                if ($db->tableExists('telegram_channels') && $activeKeys !== []) {
                    $targetHostList = $platform !== '' ? [$platform] : $activeKeys;
                    $ph = [];
                    $cb = [];
                    foreach ($targetHostList as $i => $hp) {
                        $k = 'hp' . $i;
                        $ph[] = ':' . $k;
                        $cb[$k] = $hp;
                    }
                    $sql = 'SELECT * FROM telegram_channels WHERE COALESCE(platform, \'telegram\') IN (' . implode(',', $ph) . ')';
                    if ($q !== '') {
                        $sql .= ' AND (title LIKE :q OR chat_id LIKE :q2 OR invite_link LIKE :q3)';
                        $cb['q'] = '%' . $q . '%';
                        $cb['q2'] = '%' . $q . '%';
                        $cb['q3'] = '%' . $q . '%';
                    }
                    $sql .= ' ORDER BY platform ASC, sort_order ASC, id ASC LIMIT ' . $limit;
                    $channels = $db->all($sql, $cb);

                    $memSvc = new MembershipService($db);
                    $countsMap = $memSvc->membershipCountsByChannel();

                    foreach ($channels as $ch) {
                        $cid = (int) $ch['id'];
                        $hp = strtolower((string) ($ch['platform'] ?? 'telegram'));
                        $canApi = MessengerCapabilities::supports($hp, 'channel_check_api');
                        $targets = MembershipService::resolveTargetPlatforms($ch);
                        $visibleTargets = array_values(array_filter(
                            $targets,
                            static fn(string $tp): bool => $tp === $hp || isset($activeMessengers[$tp])
                        ));
                        if ($visibleTargets === []) {
                            $visibleTargets = [$hp];
                        }
                        $targetLabels = array_map(
                            static fn(string $tp): string => MessengerCapabilities::badge($tp),
                            $visibleTargets
                        );
                        $hasCross = count(array_filter($visibleTargets, static fn(string $tp): bool => $tp !== $hp)) > 0;
                        $cm = $countsMap[$cid] ?? ['total' => 0, 'api_direct' => 0, 'api_cross' => 0, 'self_declared' => 0];
                        $verified = (int) ($cm['total'] ?? 0);
                        $totalVerified += $verified;

                        $verifyMode = !$canApi
                            ? 'تأیید کاربری (بدون API رسمی بررسی)'
                            : ($hasCross ? 'API مستقیم + میان‌پیام‌رسانی (حساب متصل)' : 'API رسمی مستقیم (getChatMember)');

                        $handle = (string) ($ch['chat_id'] ?? '—');

                        $rows[] = [
                            'platform_label' => MessengerCapabilities::badge($hp),
                            'title' => (string) ($ch['title'] ?? 'کانال'),
                            'handle' => $handle,
                            'targets_label' => implode(' ، ', $targetLabels),
                            'verify_mode' => $verifyMode,
                            'direct_members' => (int) ($cm['api_direct'] ?? 0),
                            'cross_members' => (int) ($cm['api_cross'] ?? 0),
                            'verified_members' => $verified,
                            'status_label' => ((int) ($ch['is_active'] ?? 0) === 1) ? 'فعال · اجباری' : 'غیرفعال',
                        ];
                    }
                }
                $columns = [
                    'platform_label' => ['label' => 'پیام‌رسان میزبان'],
                    'title' => ['label' => 'عنوان کانال'],
                    'handle' => ['label' => 'شناسه / یوزرنیم'],
                    'targets_label' => ['label' => 'کاربران هدف الزامی'],
                    'verify_mode' => ['label' => 'روش بررسی عضویت'],
                    'direct_members' => ['label' => 'تأیید مستقیم'],
                    'cross_members' => ['label' => 'تأیید میان‌پیام‌رسانی'],
                    'verified_members' => ['label' => 'جمع عضویت تأییدشده'],
                    'status_label' => ['label' => 'وضعیت'],
                ];
                return [$rows, $columns, ['count' => $totalVerified]];

            case 'messages':
            case 'broadcasts':
                $bw = [];
                $bb = [];
                if ($from) { $bw[] = 'created_at >= :from'; $bb['from'] = $from . ' 00:00:00'; }
                if ($to)   { $bw[] = 'created_at <= :to';   $bb['to'] = $to . ' 23:59:59'; }
                if ($q !== '') { $bw[] = 'title LIKE :q'; $bb['q'] = '%' . $q . '%'; }
                if ($platform !== '') {
                    $bw[] = "(target_platform = :plat OR target_platform = 'all')";
                    $bb['plat'] = $platform;
                }
                $rows = $db->all(
                    'SELECT * FROM broadcast_campaigns'
                    . ($bw ? ' WHERE ' . implode(' AND ', $bw) : '')
                    . ' ORDER BY id DESC LIMIT ' . min(500, $limit),
                    $bb
                );
                $statusLabels = [
                    'draft' => 'پیش‌نویس',
                    'queued' => 'در صف ارسال',
                    'sending' => 'در حال ارسال',
                    'completed' => 'ارسال‌شده',
                    'done' => 'ارسال‌شده',
                    'cancelled' => 'لغوشده',
                    'failed' => 'ناموفق',
                ];
                $sumSent = 0;
                foreach ($rows as &$row) {
                    $tp = strtolower((string) ($row['target_platform'] ?? 'all'));
                    $row['target_label'] = ($tp === '' || $tp === 'all')
                        ? '🌐 همه پیام‌رسان‌های فعال'
                        : MessengerCapabilities::badge($tp);
                    $st = (string) ($row['status'] ?? '');
                    $row['status_label'] = $statusLabels[$st] ?? $st;
                    $row['started_at'] = fa_date($row['started_at'] ?: ($row['created_at'] ?? null));
                    $row['finished_at'] = fa_date($row['finished_at']);
                    $sumSent += (int) ($row['sent_count'] ?? 0);
                }
                unset($row);
                return [$rows, [
                    'title' => ['label' => 'عنوان کمپین'],
                    'target_label' => ['label' => 'پیام‌رسان مقصد'],
                    'status_label' => ['label' => 'وضعیت'],
                    'total_targets' => ['label' => 'مخاطبان'],
                    'sent_count' => ['label' => 'ارسال موفق'],
                    'failed_count' => ['label' => 'ناموفق'],
                    'blocked_count' => ['label' => 'بلاک‌شده'],
                    'started_at' => ['label' => 'شروع'],
                    'finished_at' => ['label' => 'پایان'],
                ], ['count' => $sumSent]];

            case 'errors':
                $reg = new MessengerRegistry($db);
                $allowed = $platform !== '' ? [$platform] : $activeKeys;
                $rawRows = $reg->recentErrors(
                    $allowed,
                    $category !== '' ? $category : null,
                    min(5000, $limit),
                    $from ? ($from . ' 00:00:00') : null,
                    $to ? ($to . ' 23:59:59') : null
                );
                $rows = [];
                foreach ($rawRows as $er) {
                    if ($q !== '') {
                        $hay = strtolower((string) ($er['message'] ?? '') . ' ' . (string) ($er['method_name'] ?? '') . ' ' . (string) ($er['error_code'] ?? ''));
                        if (!str_contains($hay, strtolower($q))) {
                            continue;
                        }
                    }
                    $ep = strtolower((string) ($er['platform'] ?? 'telegram'));
                    $rows[] = [
                        'id' => (int) ($er['id'] ?? 0),
                        'platform_label' => MessengerCapabilities::badge($ep),
                        'category_label' => MessengerRegistry::errorCategoryLabel((string) ($er['category'] ?? 'api')),
                        'method_name' => (string) ($er['method_name'] ?: '—'),
                        'error_code_label' => $er['error_code'] !== null ? (string) $er['error_code'] : '—',
                        'message' => (string) ($er['message'] ?? ''),
                        'created_at' => fa_date($er['created_at'] ?? null),
                    ];
                }
                $columns = [
                    'id' => ['label' => 'شناسه'],
                    'platform_label' => ['label' => 'پیام‌رسان'],
                    'category_label' => ['label' => 'بخش'],
                    'method_name' => ['label' => 'متد API'],
                    'error_code_label' => ['label' => 'کد خطا'],
                    'message' => ['label' => 'شرح خطا (ایمن‌سازی‌شده)'],
                    'created_at' => ['label' => 'زمان ثبت'],
                ];
                return [$rows, $columns, ['count' => count($rows)]];

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
                if ($platform !== '') {
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
                    $row['platform_label'] = MessengerCapabilities::badge((string) ($row['entry_platform'] ?? 'telegram'));
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
                $sql = 'SELECT rc.*, COALESCE(re.platform, su.entry_platform, \'telegram\') AS event_platform,
                               u.first_name, u.last_name, su.first_name AS src_first, su.username AS src_username
                        FROM referral_commissions rc
                        LEFT JOIN referral_events re ON re.id = rc.event_id
                        LEFT JOIN users u ON u.id = rc.beneficiary_id
                        LEFT JOIN users su ON su.id = rc.source_user_id';
                $cw = [];
                $cb = [];
                if ($from) { $cw[] = 'rc.created_at >= :from'; $cb['from'] = $from . ' 00:00:00'; }
                if ($to)   { $cw[] = 'rc.created_at <= :to';   $cb['to'] = $to . ' 23:59:59'; }
                if ($q !== '') {
                    $cw[] = '(u.first_name LIKE :q OR su.first_name LIKE :q2 OR su.username LIKE :q3)';
                    $cb['q'] = '%' . $q . '%';
                    $cb['q2'] = '%' . $q . '%';
                    $cb['q3'] = '%' . $q . '%';
                }
                if ($platform !== '') {
                    $cw[] = 'COALESCE(re.platform, su.entry_platform, \'telegram\') = :plat';
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
                    'amount' => ['label' => 'مبلغ پاداش'],
                    'status' => ['label' => 'وضعیت'],
                    'created_at' => ['label' => 'تاریخ'],
                ];
                $totalCredited = 0;
                foreach ($rows as &$row) {
                    $row['beneficiary'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    $row['source'] = trim(($row['src_first'] ?? '') . ' ' . (!empty($row['src_username']) ? '@' . $row['src_username'] : ''));
                    $row['platform_label'] = MessengerCapabilities::badge((string) ($row['event_platform'] ?? 'telegram'));
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
                $ww = [];
                $wb = [];
                if ($from) { $ww[] = 'w.created_at >= :from'; $wb['from'] = $from . ' 00:00:00'; }
                if ($to)   { $ww[] = 'w.created_at <= :to';   $wb['to'] = $to . ' 23:59:59'; }
                if ($q !== '') {
                    $ww[] = '(w.request_code LIKE :q OR u.first_name LIKE :q2 OR w.bank_name LIKE :q3)';
                    $wb['q'] = '%' . $q . '%';
                    $wb['q2'] = '%' . $q . '%';
                    $wb['q3'] = '%' . $q . '%';
                }
                if ($platform !== '') {
                    $ww[] = 'u.entry_platform = :plat';
                    $wb['plat'] = $platform;
                }
                $rows = $db->all(
                    'SELECT w.*, u.first_name, u.last_name, u.username, u.telegram_id, u.entry_platform
                     FROM withdrawal_requests w JOIN users u ON u.id = w.user_id'
                    . ($ww ? ' WHERE ' . implode(' AND ', $ww) : '')
                    . ' ORDER BY w.id DESC LIMIT ' . $limit,
                    $wb
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
                    $row['platform_label'] = MessengerCapabilities::badge((string) ($row['entry_platform'] ?? 'telegram'));
                    $row['status'] = WithdrawalService::statusLabel((string) $row['status']);
                    $row['created_at'] = fa_date($row['created_at']);
                    $row['paid_at'] = fa_date($row['paid_at']);
                    $total += (int) $row['amount'];
                }
                unset($row);
                return [$rows, $columns, ['amount' => $total]];

            case 'referrals':
                $rw = ['u.referred_by IS NOT NULL'];
                $rb = [];
                if ($from) { $rw[] = 'u.joined_at >= :from'; $rb['from'] = $from . ' 00:00:00'; }
                if ($to)   { $rw[] = 'u.joined_at <= :to';   $rb['to'] = $to . ' 23:59:59'; }
                if ($q !== '') {
                    $rw[] = '(u.first_name LIKE :q OR r.first_name LIKE :q2 OR r.referral_code LIKE :q3)';
                    $rb['q'] = '%' . $q . '%';
                    $rb['q2'] = '%' . $q . '%';
                    $rb['q3'] = '%' . $q . '%';
                }
                if ($platform !== '') {
                    $rw[] = "COALESCE(u.referral_source_platform, u.entry_platform, 'telegram') = :plat";
                    $rb['plat'] = $platform;
                }
                $rows = $db->all(
                    'SELECT u.id, u.first_name, u.last_name, u.username, u.entry_platform, u.referral_source_platform,
                            u.joined_at, u.status, u.qualified_at, u.total_earned, u.direct_ref_count, u.total_ref_count,
                            r.first_name AS ref_first, r.username AS ref_username, r.referral_code AS ref_code,
                            (SELECT MAX(depth) FROM referral_relations rr WHERE rr.descendant_id = u.id) AS depth
                     FROM users u LEFT JOIN users r ON r.id = u.referred_by
                     WHERE ' . implode(' AND ', $rw) . ' ORDER BY u.id DESC LIMIT ' . $limit,
                    $rb
                );
                $columns = [
                    'user' => ['label' => 'کاربر دعوت‌شده'],
                    'platform_label' => ['label' => 'پیام‌رسان ورود'],
                    'referrer' => ['label' => 'معرف مستقیم (L0)'],
                    'ref_code' => ['label' => 'کد دعوت'],
                    'depth' => ['label' => 'عمق در شبکه'],
                    'direct_ref_count' => ['label' => 'دعوت مستقیم او'],
                    'indirect_ref_count' => ['label' => 'دعوت غیرمستقیم او'],
                    'qualified' => ['label' => 'وضعیت پاداش'],
                    'total_earned' => ['label' => 'درآمد کاربر'],
                    'joined_at' => ['label' => 'تاریخ عضویت'],
                ];
                foreach ($rows as &$row) {
                    $row['user'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    $row['platform_label'] = MessengerCapabilities::badge((string) ($row['referral_source_platform'] ?: ($row['entry_platform'] ?? 'telegram')));
                    $row['referrer'] = trim(($row['ref_first'] ?? '') . ' ' . (!empty($row['ref_username']) ? '@' . $row['ref_username'] : ''));
                    $row['indirect_ref_count'] = max(0, (int) ($row['total_ref_count'] ?? 0) - (int) ($row['direct_ref_count'] ?? 0));
                    $row['qualified'] = $row['qualified_at'] ? 'واجد شرایط' : 'در انتظار تکمیل شروط';
                    $row['joined_at'] = fa_date($row['joined_at']);
                }
                unset($row);
                return [$rows, $columns, ['count' => count($rows)]];

            case 'users':
                $uw = [];
                $ub = [];
                if ($from) { $uw[] = 'joined_at >= :from'; $ub['from'] = $from . ' 00:00:00'; }
                if ($to)   { $uw[] = 'joined_at <= :to';   $ub['to'] = $to . ' 23:59:59'; }
                if ($q !== '') {
                    $uw[] = '(first_name LIKE :q OR last_name LIKE :q2 OR username LIKE :q3 OR referral_code LIKE :q4)';
                    $ub['q'] = '%' . $q . '%';
                    $ub['q2'] = '%' . $q . '%';
                    $ub['q3'] = '%' . $q . '%';
                    $ub['q4'] = '%' . $q . '%';
                }
                if ($platform !== '') {
                    $uw[] = 'entry_platform = :plat';
                    $ub['plat'] = $platform;
                }
                $rows = $db->all('SELECT * FROM users' . ($uw ? ' WHERE ' . implode(' AND ', $uw) : '') . ' ORDER BY id DESC LIMIT ' . $limit, $ub);
                $columns = [
                    'id' => ['label' => 'شناسه'],
                    'platform_label' => ['label' => 'پیام‌رسان'],
                    'first_name' => ['label' => 'نام'],
                    'username' => ['label' => 'نام کاربری'],
                    'phone' => ['label' => 'شماره تماس'],
                    'referral_code' => ['label' => 'کد دعوت'],
                    'direct_ref_count' => ['label' => 'مستقیم (L0)'],
                    'indirect_ref_count' => ['label' => 'غیرمستقیم (L1+)'],
                    'total_earned' => ['label' => 'درآمد کل'],
                    'wallet_balance' => ['label' => 'موجودی'],
                    'status_label' => ['label' => 'وضعیت'],
                    'joined_at' => ['label' => 'تاریخ عضویت'],
                ];
                foreach ($rows as &$row) {
                    $row['platform_label'] = MessengerCapabilities::badge((string) ($row['entry_platform'] ?? 'telegram'));
                    $row['indirect_ref_count'] = max(0, (int) $row['total_ref_count'] - (int) $row['direct_ref_count']);
                    $row['status_label'] = match ((string) ($row['status'] ?? 'active')) {
                        'active' => 'فعال',
                        'blocked' => 'مسدود',
                        'suspicious' => 'مشکوک',
                        default => (string) ($row['status'] ?? 'فعال'),
                    };
                    $row['joined_at'] = fa_date($row['joined_at']);
                }
                unset($row);
                return [$rows, $columns, ['count' => count($rows)]];

            case 'growth':
                $gw = ['joined_at >= :s'];
                $gb = ['s' => $from ? ($from . ' 00:00:00') : date('Y-m-d 00:00:00', time() - 90 * 86400)];
                if ($to) { $gw[] = 'joined_at <= :to'; $gb['to'] = $to . ' 23:59:59'; }
                if ($platform !== '') { $gw[] = "COALESCE(entry_platform, 'telegram') = :plat"; $gb['plat'] = $platform; }

                $grouped = $db->all(
                    "SELECT DATE(joined_at) AS day, COALESCE(entry_platform, 'telegram') AS p, COUNT(*) AS cnt
                     FROM users WHERE " . implode(' AND ', $gw) . '
                     GROUP BY DATE(joined_at), COALESCE(entry_platform, \'telegram\')
                     ORDER BY day DESC',
                    $gb
                );
                $byDay = [];
                foreach ($grouped as $gr) {
                    $day = (string) ($gr['day'] ?? '');
                    if ($day === '') {
                        continue;
                    }
                    if (!isset($byDay[$day])) {
                        $byDay[$day] = ['day' => $day, 'day_fa' => fa_date($day, false), 'users' => 0];
                        foreach ($activeKeys as $ak) {
                            $byDay[$day]['plat_' . $ak] = 0;
                        }
                    }
                    $cnt = (int) $gr['cnt'];
                    $byDay[$day]['users'] += $cnt;
                    $pk = (string) ($gr['p'] ?? 'telegram');
                    if (isset($byDay[$day]['plat_' . $pk])) {
                        $byDay[$day]['plat_' . $pk] += $cnt;
                    }
                }
                $rows = array_values($byDay);
                $columns = [
                    'day_fa' => ['label' => 'تاریخ'],
                    'users' => ['label' => 'کل ثبت‌نام سیستم'],
                ];
                // ستون تفکیکی فقط برای پیام‌رسان‌های فعال و متصل
                $shownPlats = $platform !== '' ? [$platform] : $activeKeys;
                foreach ($shownPlats as $ak) {
                    $columns['plat_' . $ak] = [
                        'label' => MessengerCapabilities::icon($ak) . ' ' . MessengerCapabilities::usersLabel($ak),
                        'is_count' => true,
                    ];
                }
                return [$rows, $columns, ['count' => array_sum(array_column($rows, 'users'))]];

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
                ], ['amount' => array_sum(array_column($rows, 'paid'))]];

            case 'suspicious':
            default:
                $rows = $db->all(
                    "SELECT se.*, u.first_name, u.last_name, u.username, u.risk_score, u.status AS user_status,
                            u.telegram_id AS user_telegram_id, u.entry_platform
                     FROM security_events se LEFT JOIN users u ON u.id = se.user_id
                     ORDER BY se.id DESC LIMIT " . $limit
                );
                foreach ($rows as &$row) {
                    $row['user'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: '—';
                    $row['platform_label'] = !empty($row['user_id'])
                        ? MessengerCapabilities::badge((string) ($row['entry_platform'] ?? 'telegram'))
                        : '—';
                    $tid = (int) ($row['user_telegram_id'] ?? 0);
                    $row['user_ref'] = $tid > 0
                        ? (string) $tid
                        : (!empty($row['user_id']) ? ('#' . (int) $row['user_id']) : '—');
                    $row['type_label'] = SecurityController::typeLabel((string) $row['type']);
                    $row['status'] = SecurityController::statusLabel((string) $row['status']);
                    $row['created_at'] = fa_date($row['created_at']);
                }
                unset($row);
                return [$rows, [
                    'id' => ['label' => 'شناسه'], 'user' => ['label' => 'کاربر'],
                    'platform_label' => ['label' => 'پیام‌رسان'], 'user_ref' => ['label' => 'شناسه کاربر'],
                    'type_label' => ['label' => 'نوع رویداد'], 'severity' => ['label' => 'شدت'],
                    'status' => ['label' => 'وضعیت'], 'risk_score' => ['label' => 'امتیاز ریسک'],
                    'created_at' => ['label' => 'تاریخ'],
                ], []];
        }
    }
}

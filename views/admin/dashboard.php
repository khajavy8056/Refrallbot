<?php
$statusLabel = [
    'connected' => ['متصل', 'success'],
    'error' => ['خطا در اتصال', 'danger'],
    'disconnected' => ['قطع', 'muted'],
][$overview['bot_status']] ?? ['نامشخص', 'muted'];
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">داشبورد مدیریت</h1>
        <p class="text-muted text-sm mb-0">خلاصه وضعیت سامانه در یک نگاه</p>
    </div>
    <div class="flex gap-1">
        <?php foreach ([7 => '۷ روز', 14 => '۱۴ روز', 30 => '۳۰ روز', 90 => '۹۰ روز'] as $d => $label): ?>
            <a href="?days=<?= $d ?>" class="btn btn-sm <?= $days === $d ? '' : 'btn-secondary' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!$overview['bot_connected']): ?>
    <div class="alert alert-warning">
        <span class="alert-icon">🤖</span>
        <div>
            ربات تلگرام متصل نیست. برای فعالسازی دریافت کاربران و ارسال پیام،
            <a href="<?= url('admin/telegram') ?>">توکن ربات را ثبت کنید</a>.
        </div>
    </div>
<?php elseif (!$overview['webhook_active']): ?>
    <div class="alert alert-warning">
        <span class="alert-icon">🔗</span>
        <div>
            ربات متصل است اما Webhook فعال نیست؛ ربات پیام‌ها را دریافت نمی‌کند.
            <a href="<?= url('admin/telegram') ?>">همین حالا تنظیم کنید</a>.
        </div>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat">
        <div class="stat-head"><div class="stat-icon">👥</div><div class="stat-label">کل کاربران</div></div>
        <div class="stat-value"><?= fa_digits(number_format($overview['total_users'])) ?></div>
        <div class="stat-foot"><?= fa_digits(number_format($overview['new_today'])) ?> کاربر جدید امروز</div>
    </div>
    <div class="stat success">
        <div class="stat-head"><div class="stat-icon">✅</div><div class="stat-label">کاربران فعال</div></div>
        <div class="stat-value"><?= fa_digits(number_format($overview['active_users'])) ?></div>
        <div class="stat-foot"><?= fa_digits(number_format($overview['new_week'])) ?> عضویت در ۷ روز اخیر</div>
    </div>
    <div class="stat info">
        <div class="stat-head"><div class="stat-icon">🕸</div><div class="stat-label">کل معرفی‌ها</div></div>
        <div class="stat-value"><?= fa_digits(number_format($overview['total_referrals'])) ?></div>
        <div class="stat-foot"><?= fa_digits(number_format($overview['qualified_referrals'])) ?> معرفی واجد شرایط</div>
    </div>
    <div class="stat purple">
        <div class="stat-head"><div class="stat-icon">💰</div><div class="stat-label">پورسانت پرداخت‌شده</div></div>
        <div class="stat-value"><?= fa_digits(number_format($overview['total_commissions'])) ?><small> تومان</small></div>
        <div class="stat-foot">مجموع درآمد کاربران: <?= wtoman($overview['total_earned']) ?></div>
    </div>
    <div class="stat danger">
        <div class="stat-head"><div class="stat-icon">🏦</div><div class="stat-label">در انتظار برداشت</div></div>
        <div class="stat-value"><?= fa_digits(number_format($overview['pending_withdrawals_amount'])) ?><small> تومان</small></div>
        <div class="stat-foot"><?= fa_digits($overview['pending_withdrawals_count']) ?> درخواست باز</div>
    </div>
    <div class="stat success">
        <div class="stat-head"><div class="stat-icon">💸</div><div class="stat-label">برداشت‌های انجام‌شده</div></div>
        <div class="stat-value"><?= fa_digits(number_format($overview['total_withdrawn'])) ?><small> تومان</small></div>
        <div class="stat-foot">موجودی کل کیف پول‌ها: <?= wtoman($overview['wallet_total_balance']) ?></div>
    </div>
    <div class="stat warning">
        <div class="stat-head"><div class="stat-icon">⚠️</div><div class="stat-label">کاربران مشکوک</div></div>
        <div class="stat-value"><?= fa_digits(number_format($overview['suspicious_users'])) ?></div>
        <div class="stat-foot"><?= fa_digits($overview['pending_security_events']) ?> رویداد امنیتی در انتظار بررسی</div>
    </div>
    <div class="stat <?= $overview['bot_connected'] ? 'success' : 'danger' ?>">
        <div class="stat-head"><div class="stat-icon">🤖</div><div class="stat-label">وضعیت ربات تلگرام</div></div>
        <div class="stat-value" style="font-size:1.05rem">
            <span class="badge badge-<?= $statusLabel[1] ?>"><?= e($statusLabel[0]) ?></span>
        </div>
        <div class="stat-foot">
            <?= !empty($telegram['bot_username']) ? '@' . e($telegram['bot_username']) : 'توکن ثبت نشده' ?>
            · Webhook: <?= $overview['webhook_active'] ? 'فعال' : 'غیرفعال' ?>
        </div>
    </div>
</div>

<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">رشد کاربران</h2>
            <span class="card-subtitle"><?= fa_digits($days) ?> روز اخیر</span>
        </div>
        <div class="card-body">
            <div class="chart-wrap" style="height:260px"><canvas id="chart-users"></canvas></div>
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">دعوت‌ها و پورسانت</h2>
            <div class="chart-legend">
                <span><i class="legend-dot" style="background:#6366f1"></i> دعوت</span>
                <span><i class="legend-dot" style="background:#10b981"></i> پورسانت (تومان)</span>
            </div>
        </div>
        <div class="card-body">
            <div class="chart-wrap" style="height:260px"><canvas id="chart-referrals"></canvas></div>
        </div>
    </div>
</div>

<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">درخواست‌های برداشت</h2>
            <a href="<?= url('admin/withdrawals') ?>" class="btn btn-ghost btn-sm">مشاهده همه ←</a>
        </div>
        <div class="card-body">
            <div class="chart-wrap" style="height:220px"><canvas id="chart-withdrawals"></canvas></div>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h2 class="card-title">توزیع پورسانت بر اساس سطح</h2></div>
        <div class="card-body">
            <?php if (empty($commissionLevels)): ?>
                <div class="empty"><div class="empty-icon">📊</div><div class="empty-text">هنوز پورسانتی پرداخت نشده است.</div></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>سطح</th><th>تعداد</th><th>مجموع مبلغ</th></tr></thead>
                        <tbody>
                        <?php foreach ($commissionLevels as $level): ?>
                            <tr>
                                <td data-label="سطح">
                                    <?= (int) $level['level'] === 0 ? 'پاداش مستقیم' : 'لایه ' . fa_digits((int) $level['level']) ?>
                                </td>
                                <td data-label="تعداد" class="num"><?= fa_digits(number_format((int) $level['c'])) ?></td>
                                <td data-label="مجموع"><?= wtoman((int) $level['s']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">کاربران برتر</h2>
            <a href="<?= url('admin/users') ?>" class="btn btn-ghost btn-sm">همه کاربران ←</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>کاربر</th><th>زیرمجموعه</th><th>درآمد</th></tr></thead>
                <tbody>
                <?php if (empty($topUsers)): ?>
                    <tr><td colspan="3"><div class="empty"><div class="empty-text">کاربری ثبت نشده است.</div></div></td></tr>
                <?php endif; ?>
                <?php foreach ($topUsers as $index => $u): ?>
                    <tr>
                        <td data-label="کاربر">
                            <div class="user-cell">
                                <div class="avatar avatar-sm"><?= fa_digits($index + 1) ?></div>
                                <div>
                                    <div class="user-cell-name"><?= e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'کاربر') ?></div>
                                    <div class="user-cell-meta"><?= $u['username'] ? '@' . e($u['username']) : '' ?></div>
                                </div>
                            </div>
                        </td>
                        <td data-label="زیرمجموعه" class="num"><?= fa_digits((int) $u['direct_ref_count']) ?></td>
                        <td data-label="درآمد"><?= wtoman((int) $u['total_earned']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h2 class="card-title">آخرین فعالیت مدیران</h2></div>
        <div class="table-wrap">
            <table class="table">
                <tbody>
                <?php if (empty($activity)): ?>
                    <tr><td><div class="empty"><div class="empty-text">فعالیتی ثبت نشده است.</div></div></td></tr>
                <?php endif; ?>
                <?php foreach ($activity as $log): ?>
                    <tr>
                        <td data-label="فعالیت">
                            <div style="font-weight:600;font-size:.83rem"><?= e(RB\Services\AuditService::label((string) $log['action'])) ?></div>
                            <div class="text-xs text-muted"><?= e($log['admin_name'] ?? '—') ?> · <?= fa_date($log['created_at']) ?></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">آخرین کاربران جذب‌شده</h2>
        <a href="<?= url('admin/users') ?>" class="btn btn-ghost btn-sm">مدیریت کاربران ←</a>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>کاربر</th><th>شناسه تلگرام</th><th>تاریخ عضویت</th><th>وضعیت</th></tr></thead>
            <tbody>
            <?php if (empty($recentUsers)): ?>
                <tr><td colspan="4"><div class="empty"><div class="empty-icon">👤</div><div class="empty-text">هنوز کاربری ثبت نکرده است.</div></div></td></tr>
            <?php endif; ?>
            <?php foreach ($recentUsers as $u): ?>
                <tr>
                    <td data-label="کاربر">
                        <a href="<?= url('admin/users/' . (int) $u['id']) ?>" class="user-cell">
                            <div class="avatar avatar-sm"><?= e(mb_substr($u['first_name'] ?: '?', 0, 1)) ?></div>
                            <div>
                                <div class="user-cell-name"><?= e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'کاربر') ?></div>
                                <div class="user-cell-meta"><?= $u['username'] ? '@' . e($u['username']) : 'بدون نام کاربری' ?></div>
                            </div>
                        </a>
                    </td>
                    <td data-label="شناسه" class="num" style="direction:ltr;text-align:right"><?= fa_digits((string) $u['telegram_id']) ?></td>
                    <td data-label="تاریخ"><?= fa_date($u['joined_at']) ?></td>
                    <td data-label="وضعیت">
                        <span class="badge badge-<?= $u['status'] === 'active' ? 'success' : ($u['status'] === 'blocked' ? 'danger' : 'warning') ?>">
                            <?= $u['status'] === 'active' ? 'فعال' : ($u['status'] === 'blocked' ? 'مسدود' : 'مشکوک') ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
window.addEventListener('load', function () {
    RB.lineChart(document.getElementById('chart-users'), {
        labels: <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>,
        series: [{ name: 'کاربران جدید', data: <?= json_encode($growth) ?>, color: '#6366f1' }]
    });
    RB.lineChart(document.getElementById('chart-referrals'), {
        labels: <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>,
        series: [
            { name: 'دعوت', data: <?= json_encode($referrals) ?>, color: '#6366f1', fill: false },
            { name: 'پورسانت', data: <?= json_encode($payouts) ?>, color: '#10b981', fill: false }
        ]
    });
    RB.barChart(document.getElementById('chart-withdrawals'), {
        labels: <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>,
        data: <?= json_encode($withdrawalSeries) ?>,
        color: '#f59e0b'
    });
});
</script>

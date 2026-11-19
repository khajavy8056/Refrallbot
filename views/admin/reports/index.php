<?php
$exportQuery = array_filter($filters, static fn($v) => $v !== null && $v !== '');
$exportUrl = url('admin/reports/' . $type . '/export') . ($exportQuery ? '?' . http_build_query($exportQuery) : '');
$moneyCols = ['amount', 'base_amount', 'total_earned', 'wallet_balance', 'paid', 'paid_amount', 'pending_amount', 'total_amount', 'direct_paid', 'indirect_paid', 'welcome_paid'];
$countCols = ['users', 'users_count', 'qualified_count', 'tx_count', 'registered_users', 'linked_accounts', 'direct_referrals', 'qualified_referrals', 'direct_ref_count', 'indirect_ref_count', 'total_ref_count', 'id', 'level', 'depth'];
$layerSum = $totals['summary'] ?? null;
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">📈 گزارش‌های مدیریتی و مالی</h1>
        <p class="text-muted text-sm mb-0">گزارش زنده از دیتابیس به تفکیک لایه‌ها و پیام‌رسان‌ها — خروجی CSV سازگار با Excel فارسی</p>
    </div>
    <a href="<?= e($exportUrl) ?>" class="btn btn-secondary btn-sm">⬇️ دانلود خروجی CSV</a>
</div>

<div class="card mb-3">
    <div class="card-body flex gap-1 wrap">
        <?php foreach ($types as $key => $label): ?>
            <a href="<?= url('admin/reports/' . $key) ?>" class="btn btn-sm <?= $type === $key ? '' : 'btn-secondary' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($type === 'layers' && is_array($layerSum)): ?>
    <div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(185px,1fr))">
        <div class="stat info">
            <div class="stat-head"><div class="stat-icon">🎯</div><div class="stat-label">پرداختی لایه ۰ (مستقیم)</div></div>
            <div class="stat-value" style="font-size:1.05rem"><?= wtoman((int) $layerSum['direct_paid']) ?></div>
            <div class="stat-foot"><?= fa_digits((int) $layerSum['direct_users']) ?> زیرمجموعه مستقیم</div>
        </div>
        <div class="stat purple">
            <div class="stat-head"><div class="stat-icon">🕸</div><div class="stat-label">پرداختی لایه‌های ۱+ (غیرمستقیم)</div></div>
            <div class="stat-value" style="font-size:1.05rem"><?= wtoman((int) $layerSum['indirect_paid']) ?></div>
            <div class="stat-foot"><?= fa_digits((int) $layerSum['indirect_users']) ?> پیوند غیرمستقیم</div>
        </div>
        <div class="stat success">
            <div class="stat-head"><div class="stat-icon">🏦</div><div class="stat-label">تسویه‌شده به کاربران</div></div>
            <div class="stat-value" style="font-size:1.05rem"><?= wtoman((int) $layerSum['withdrawals_paid']) ?></div>
            <div class="stat-foot">در انتظار تسویه: <?= wtoman((int) $layerSum['withdrawals_pending']) ?></div>
        </div>
        <div class="stat warning">
            <div class="stat-head"><div class="stat-icon">👛</div><div class="stat-label">موجودی فعلی کیف پول‌ها</div></div>
            <div class="stat-value" style="font-size:1.05rem"><?= wtoman((int) $layerSum['wallet_balance']) ?></div>
            <div class="stat-foot">پورسانت در انتظار: <?= wtoman((int) $layerSum['total_pending']) ?></div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?= e($types[$type]) ?></h2>
        <span class="card-subtitle">
            <?= fa_digits(count($rows)) ?> ردیف
            <?php if (isset($totals['amount'])): ?> · مجموع واریزشده: <?= wtoman((int) $totals['amount']) ?><?php endif; ?>
        </span>
    </div>

    <?php if (!in_array($type, ['layers', 'messengers'], true)): ?>
        <form method="get" action="<?= url('admin/reports/' . $type) ?>" class="filters">
            <div class="form-group" style="min-width:190px">
                <label class="form-label">جستجو</label>
                <input type="search" name="q" class="form-control" value="<?= e($filters['q'] ?? '') ?>" placeholder="نام، شناسه، کد…">
            </div>
            <?php if (in_array($type, ['transactions', 'commissions', 'users'], true)): ?>
                <div class="form-group">
                    <label class="form-label">پیام‌رسان</label>
                    <select name="platform" class="form-select">
                        <option value="">همه پیام‌رسان‌ها</option>
                        <option value="telegram" <?= ($filters['platform'] ?? '') === 'telegram' ? 'selected' : '' ?>>تلگرام</option>
                        <option value="bale" <?= ($filters['platform'] ?? '') === 'bale' ? 'selected' : '' ?>>بله</option>
                        <option value="rubika" <?= ($filters['platform'] ?? '') === 'rubika' ? 'selected' : '' ?>>روبیکا</option>
                        <option value="eitaa" <?= ($filters['platform'] ?? '') === 'eitaa' ? 'selected' : '' ?>>ایتا</option>
                    </select>
                </div>
            <?php endif; ?>
            <div class="form-group">
                <label class="form-label">از تاریخ</label>
                <input type="text" name="from" class="form-control ltr" value="<?= e($filters['from'] ?? '') ?>" placeholder="1405/01/01">
            </div>
            <div class="form-group">
                <label class="form-label">تا تاریخ</label>
                <input type="text" name="to" class="form-control ltr" value="<?= e($filters['to'] ?? '') ?>" placeholder="1405/12/29">
            </div>
            <div class="form-group">
                <button class="btn btn-sm" type="submit">اعمال</button>
                <a href="<?= url('admin/reports/' . $type) ?>" class="btn btn-ghost btn-sm">پاک کردن</a>
            </div>
        </form>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <div class="empty">
            <div class="empty-icon">📊</div>
            <div class="empty-title">داده‌ای برای این گزارش وجود ندارد</div>
            <div class="empty-text">پس از ثبت کاربران و تراکنش‌ها، گزارش‌ها به‌صورت زنده از دیتابیس ساخته می‌شوند.</div>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <?php foreach ($columns as $key => $meta): ?>
                        <th><?= e($meta['label']) ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($columns as $key => $meta): ?>
                            <?php
                            $value = $row[$key] ?? '';
                            $isMoney = in_array($key, $moneyCols, true);
                            $isCount = in_array($key, $countCols, true);
                            ?>
                            <td data-label="<?= e($meta['label']) ?>" class="<?= ($isMoney || $isCount) ? 'num' : '' ?>">
                                <?php if ($isMoney): ?>
                                    <?= wtoman((int) $value) ?>
                                <?php elseif ($isCount && is_numeric($value)): ?>
                                    <?= fa_digits(number_format((int) $value)) ?>
                                <?php else: ?>
                                    <?= e((string) $value) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

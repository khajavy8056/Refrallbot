<?php
$exportQuery = array_filter($filters, static fn($v) => $v !== null && $v !== '');
$exportUrl = url('admin/reports/' . $type . '/export') . ($exportQuery ? '?' . http_build_query($exportQuery) : '');
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">گزارش‌های مدیریتی</h1>
        <p class="text-muted text-sm mb-0">گزارش زنده از دیتابیس — خروجی CSV با پشتیبانی کامل فارسی (BOM برای Excel)</p>
    </div>
    <a href="<?= e($exportUrl) ?>" class="btn btn-secondary btn-sm">⬇️ خروجی CSV</a>
</div>

<div class="card mb-3">
    <div class="card-body flex gap-2 wrap">
        <?php foreach ($types as $key => $label): ?>
            <a href="<?= url('admin/reports/' . $key) ?>" class="btn btn-sm <?= $type === $key ? '' : 'btn-secondary' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?= e($types[$type]) ?></h2>
        <span class="card-subtitle">
            <?= fa_digits(count($rows)) ?> ردیف
            <?php if (isset($totals['amount'])): ?> · مجموع مبالغ: <?= wtoman((int) $totals['amount']) ?><?php endif; ?>
        </span>
    </div>

    <form method="get" action="<?= url('admin/reports/' . $type) ?>" class="filters">
        <div class="form-group" style="min-width:200px">
            <label class="form-label">جستجو</label>
            <input type="search" name="q" class="form-control" value="<?= e($filters['q'] ?? '') ?>" placeholder="نام، شناسه، کد…">
        </div>
        <div class="form-group">
            <label class="form-label">از تاریخ</label>
            <input type="text" name="from" class="form-control ltr" value="<?= e($filters['from'] ?? '') ?>" placeholder="1403/01/01">
        </div>
        <div class="form-group">
            <label class="form-label">تا تاریخ</label>
            <input type="text" name="to" class="form-control ltr" value="<?= e($filters['to'] ?? '') ?>" placeholder="1403/12/29">
        </div>
        <div class="form-group">
            <button class="btn btn-sm" type="submit">اعمال</button>
            <a href="<?= url('admin/reports/' . $type) ?>" class="btn btn-ghost btn-sm">پاک کردن</a>
        </div>
    </form>

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
                            $isNumeric = in_array($key, ['amount', 'base_amount', 'total_earned', 'wallet_balance', 'paid', 'users', 'direct_ref_count', 'total_ref_count', 'telegram_id', 'id', 'level', 'minimum'], true);
                            ?>
                            <td data-label="<?= e($meta['label']) ?>" class="<?= $isNumeric ? 'num' : '' ?>">
                                <?php if ($isNumeric && in_array($key, ['amount', 'base_amount', 'total_earned', 'wallet_balance', 'paid'], true)): ?>
                                    <?= wtoman((int) $value) ?>
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

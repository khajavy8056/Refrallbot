<?php
$total = (int) $result['total'];
$page = (int) $result['page'];
$perPage = (int) $result['per_page'];
$pages = max(1, (int) ceil($total / max(1, $perPage)));
$query = array_filter(['status' => $filters['status'] ?? null, 'q' => $filters['q'] ?? null,
    'from' => $fromRaw ?: null, 'to' => $toRaw ?: null], static fn($v) => $v !== null && $v !== '');
$pageUrl = static fn(int $p) => url('admin/withdrawals') . '?' . http_build_query(array_merge($query, ['page' => $p]));
$badges = ['new' => 'info', 'reviewing' => 'warning', 'approved' => 'brand', 'paying' => 'warning',
    'paid' => 'success', 'rejected' => 'danger', 'cancelled' => 'muted'];
$statusTabs = ['' => 'همه', 'open' => 'باز', 'new' => 'جدید', 'reviewing' => 'در بررسی',
    'approved' => 'تأییدشده', 'paid' => 'پرداخت‌شده', 'rejected' => 'رد‌شده', 'cancelled' => 'لغو‌شده'];
$currentStatus = $filters['status'] ?? '';
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">درخواست‌های برداشت</h1>
        <p class="text-muted text-sm mb-0">بررسی، تأیید و ثبت پرداخت — مبالغ هنگام ثبت درخواست رزرو می‌شوند</p>
    </div>
    <a href="<?= url('admin/reports/withdrawals/export') ?>" class="btn btn-secondary btn-sm">⬇️ خروجی CSV (همه)</a>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(190px,1fr))">
    <div class="stat warning"><div class="stat-head"><div class="stat-icon">⏳</div><div class="stat-label">در انتظار بررسی</div></div>
        <div class="stat-value"><?= fa_digits(number_format($stats['pending_amount'])) ?><small> تومان</small></div>
        <div class="stat-foot"><?= fa_digits(array_sum(array_map(static fn($s) => $stats['counts'][$s] ?? 0, ['new', 'reviewing', 'approved', 'paying']))) ?> درخواست باز</div></div>
    <div class="stat success"><div class="stat-head"><div class="stat-icon">✅</div><div class="stat-label">پرداخت‌شده</div></div>
        <div class="stat-value"><?= fa_digits(number_format($stats['paid_amount'])) ?><small> تومان</small></div>
        <div class="stat-foot"><?= fa_digits($stats['paid_count']) ?> درخواست</div></div>
    <div class="stat info"><div class="stat-head"><div class="stat-icon">🧾</div><div class="stat-label">نتیجه فیلتر</div></div>
        <div class="stat-value"><?= fa_digits(number_format($total)) ?></div>
        <div class="stat-foot">مجموع: <?= wtoman($result['sum']) ?></div></div>
    <div class="stat danger"><div class="stat-head"><div class="stat-icon">↩️</div><div class="stat-label">رد‌شده / لغو‌شده</div></div>
        <div class="stat-value"><?= fa_digits(number_format(($stats['counts']['rejected'] ?? 0) + ($stats['counts']['cancelled'] ?? 0))) ?></div>
        <div class="stat-foot">مبالغ به کیف پول بازگشته</div></div>
</div>

<div class="card">
    <div class="card-header" style="gap:8px;flex-wrap:wrap">
        <?php foreach ($statusTabs as $key => $label): ?>
            <a href="<?= url('admin/withdrawals') . ($key !== '' ? '?status=' . $key : '') ?>"
               class="btn btn-sm <?= $currentStatus === $key ? '' : 'btn-secondary' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="<?= url('admin/withdrawals') ?>" class="filters">
        <input type="hidden" name="status" value="<?= e($currentStatus) ?>">
        <div class="form-group" style="min-width:220px">
            <label class="form-label">جستجو</label>
            <input type="search" name="q" class="form-control" value="<?= e($filters['q'] ?? '') ?>" placeholder="نام، کد درخواست، ۴ رقم کارت…">
        </div>
        <div class="form-group">
            <label class="form-label">از تاریخ</label>
            <input type="text" name="from" class="form-control ltr" value="<?= e($fromRaw) ?>" placeholder="1403/01/01">
        </div>
        <div class="form-group">
            <label class="form-label">تا تاریخ</label>
            <input type="text" name="to" class="form-control ltr" value="<?= e($toRaw) ?>" placeholder="1403/12/29">
        </div>
        <div class="form-group">
            <button class="btn btn-sm" type="submit">اعمال فیلتر</button>
            <a href="<?= url('admin/withdrawals') ?>" class="btn btn-ghost btn-sm">پاک کردن</a>
        </div>
    </form>

    <?php if (empty($result['rows'])): ?>
        <div class="empty">
            <div class="empty-icon">🏦</div>
            <div class="empty-title">درخواستی با این فیلترها وجود ندارد</div>
            <div class="empty-text">درخواست‌های جدید کاربران پس از تأیید شرایط برداشت، در این فهرست نمایش داده می‌شوند.</div>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th>کد درخواست</th><th>کاربر</th><th>مبلغ</th><th>کارت</th><th>وضعیت</th><th>تاریخ ثبت</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $row): ?>
                    <tr>
                        <td data-label="کد" class="ltr mono"><?= e((string) $row['request_code']) ?></td>
                        <td data-label="کاربر">
                            <div class="user-cell">
                                <div class="avatar avatar-sm"><?= e(mb_substr($row['first_name'] ?: '؟', 0, 1)) ?></div>
                                <div>
                                    <a class="user-cell-name" href="<?= url('admin/users/' . $row['user_id']) ?>"><?= e(trim($row['first_name'] . ' ' . $row['last_name'])) ?></a>
                                    <div class="user-cell-meta ltr"><?= e((string) $row['telegram_id']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td data-label="مبلغ" class="num"><?= wtoman($row['amount']) ?></td>
                        <td data-label="کارت"><span class="ltr mono">•••• <?= e((string) $row['card_last4']) ?></span>
                            <div class="user-cell-meta"><?= e((string) ($row['bank_name'] ?? '')) ?></div></td>
                        <td data-label="وضعیت"><span class="badge badge-<?= $badges[$row['status']] ?? 'muted' ?>"><?= e(\RB\Services\WithdrawalService::statusLabel((string) $row['status'])) ?></span></td>
                        <td data-label="تاریخ" class="text-xs"><?= fa_date($row['created_at']) ?></td>
                        <td data-label="">
                            <div class="actions">
                                <a href="<?= url('admin/withdrawals/' . $row['id']) ?>" class="btn btn-sm btn-secondary">بررسی</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <div class="card-footer">
                <div class="pagination">
                    <a class="page-link <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page <= 1 ? '#' : $pageUrl($page - 1) ?>">قبلی</a>
                    <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
                        <a class="page-link <?= $i === $page ? 'active' : '' ?>" href="<?= $pageUrl($i) ?>"><?= fa_digits($i) ?></a>
                    <?php endfor; ?>
                    <a class="page-link <?= $page >= $pages ? 'disabled' : '' ?>" href="<?= $page >= $pages ? '#' : $pageUrl($page + 1) ?>">بعدی</a>
                    <span class="page-info">صفحه <?= fa_digits($page) ?> از <?= fa_digits($pages) ?></span>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

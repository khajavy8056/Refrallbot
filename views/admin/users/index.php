<?php
$total = (int) $result['total'];
$page = (int) $result['page'];
$perPage = (int) $result['per_page'];
$pages = max(1, (int) ceil($total / max(1, $perPage)));
$queryBase = array_filter([
    'q' => $filters['q'] ?? null, 'status' => $filters['status'] ?? null,
    'has_refs' => $filters['has_refs'] ?? null, 'has_income' => $filters['has_income'] ?? null,
    'with_phone' => $filters['with_phone'] ?? null, 'suspicious' => $filters['suspicious'] ?? null,
    'from' => ($fromRaw ?? '') ?: null, 'to' => ($toRaw ?? '') ?: null,
], static fn($v) => $v !== null && $v !== '');
$pageUrl = static function (int $p) use ($queryBase) {
    return url('admin/users') . '?' . http_build_query(array_merge($queryBase, ['page' => $p]));
};
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">مدیریت کاربران</h1>
        <p class="text-muted text-sm mb-0"><?= fa_digits(number_format($total)) ?> کاربر در این فهرست</p>
    </div>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr))">
    <div class="stat"><div class="stat-head"><div class="stat-icon">👥</div><div class="stat-label">کل کاربران</div></div><div class="stat-value"><?= fa_digits(number_format($summary['total'])) ?></div></div>
    <div class="stat success"><div class="stat-head"><div class="stat-icon">✅</div><div class="stat-label">فعال</div></div><div class="stat-value"><?= fa_digits(number_format($summary['active'])) ?></div></div>
    <div class="stat danger"><div class="stat-head"><div class="stat-icon">⛔️</div><div class="stat-label">مسدود</div></div><div class="stat-value"><?= fa_digits(number_format($summary['blocked'])) ?></div></div>
    <div class="stat info"><div class="stat-head"><div class="stat-icon">🕸</div><div class="stat-label">دارای زیرمجموعه</div></div><div class="stat-value"><?= fa_digits(number_format($summary['with_refs'])) ?></div></div>
</div>

<div class="card">
    <form method="get" action="<?= url('admin/users') ?>" class="filters">
        <div class="form-group" style="min-width:240px">
            <label class="form-label">جستجو</label>
            <input type="search" name="q" class="form-control" value="<?= e($filters['q'] ?? '') ?>"
                   placeholder="نام، نام کاربری، شناسه تلگرام، کد دعوت…">
        </div>
        <div class="form-group">
            <label class="form-label">وضعیت</label>
            <select name="status" class="form-select">
                <option value="">همه</option>
                <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>فعال</option>
                <option value="blocked" <?= ($filters['status'] ?? '') === 'blocked' ? 'selected' : '' ?>>مسدود</option>
                <option value="suspicious" <?= ($filters['status'] ?? '') === 'suspicious' ? 'selected' : '' ?>>مشکوک</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">از تاریخ</label>
            <input type="text" name="from" class="form-control ltr" value="<?= e($fromRaw ?? '') ?>" placeholder="1403/01/01">
        </div>
        <div class="form-group">
            <label class="form-label">تا تاریخ</label>
            <input type="text" name="to" class="form-control ltr" value="<?= e($toRaw ?? '') ?>" placeholder="1403/12/29">
        </div>
        <div class="form-group">
            <label class="form-label">فیلترها</label>
            <div class="flex gap-2 wrap">
                <label class="checkbox" style="padding:0"><input type="checkbox" name="has_refs" value="1" <?= !empty($filters['has_refs']) ? 'checked' : '' ?>><span class="text-sm">دارای زیرمجموعه</span></label>
                <label class="checkbox" style="padding:0"><input type="checkbox" name="has_income" value="1" <?= !empty($filters['has_income']) ? 'checked' : '' ?>><span class="text-sm">دارای درآمد</span></label>
                <label class="checkbox" style="padding:0"><input type="checkbox" name="with_phone" value="1" <?= !empty($filters['with_phone']) ? 'checked' : '' ?>><span class="text-sm">دارای شماره</span></label>
            </div>
        </div>
        <div class="form-group">
            <button type="submit" class="btn btn-sm">اعمال فیلتر</button>
            <a href="<?= url('admin/users') ?>" class="btn btn-ghost btn-sm">پاک کردن</a>
        </div>
    </form>

    <?php if (empty($result['rows'])): ?>
        <div class="empty">
            <div class="empty-icon">🔍</div>
            <div class="empty-title">کاربری یافت نشد</div>
            <div class="empty-text">با تغییر فیلترها دوباره جستجو کنید. اگر هنوز کاربری ثبت نشده، ابتدا ربات را متصل و Webhook را فعال کنید.</div>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>کاربر</th>
                    <th>شناسه تلگرام</th>
                    <th>معرف</th>
                    <th>زیرمجموعه</th>
                    <th>درآمد</th>
                    <th>موجودی</th>
                    <th>عضویت</th>
                    <th>وضعیت</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $u): ?>
                    <tr>
                        <td data-label="کاربر">
                            <div class="user-cell">
                                <div class="avatar avatar-sm"><?= e(mb_substr($u['first_name'] ?: 'ک', 0, 1)) ?></div>
                                <div>
                                    <div class="user-cell-name"><?= e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'کاربر') ?></div>
                                    <div class="user-cell-meta"><?= $u['username'] ? '@' . e($u['username']) : e($u['referral_code']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td data-label="شناسه" class="num" style="direction:ltr;text-align:right"><?= fa_digits((string) $u['telegram_id']) ?></td>
                        <td data-label="معرف" class="text-sm">
                            <?php if ($u['referrer_first_name']): ?>
                                <?= e($u['referrer_first_name']) ?>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td data-label="زیرمجموعه" class="num">
                            <?= fa_digits((int) $u['direct_ref_count']) ?>
                            <span class="text-xs text-muted">/ <?= fa_digits((int) $u['total_ref_count']) ?></span>
                        </td>
                        <td data-label="درآمد"><?= wtoman((int) $u['total_earned']) ?></td>
                        <td data-label="موجودی" class="font-bold"><?= wtoman((int) $u['wallet_balance']) ?></td>
                        <td data-label="عضویت" class="text-sm text-muted"><?= fa_date($u['joined_at'], false) ?></td>
                        <td data-label="وضعیت">
                            <span class="badge badge-<?= $u['status'] === 'active' ? 'success' : ($u['status'] === 'blocked' ? 'danger' : 'warning') ?>">
                                <?= $u['status'] === 'active' ? 'فعال' : ($u['status'] === 'blocked' ? 'مسدود' : 'مشکوک') ?>
                            </span>
                        </td>
                        <td data-label="">
                            <a href="<?= url('admin/users/' . (int) $u['id']) ?>" class="btn btn-sm btn-secondary">پروفایل</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <div class="pagination">
                <a class="page-link <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e($pageUrl(max(1, $page - 1))) ?>">‹</a>
                <?php
                $start = max(1, $page - 3);
                $end = min($pages, $page + 3);
                if ($start > 1) {
                    echo '<a class="page-link" href="' . e($pageUrl(1)) . '">' . fa_digits(1) . '</a>';
                    if ($start > 2) {
                        echo '<span class="page-info">…</span>';
                    }
                }
                for ($p = $start; $p <= $end; $p++) {
                    printf('<a class="page-link %s" href="%s">%s</a>', $p === $page ? 'active' : '', e($pageUrl($p)), fa_digits($p));
                }
                if ($end < $pages) {
                    if ($end < $pages - 1) {
                        echo '<span class="page-info">…</span>';
                    }
                    echo '<a class="page-link" href="' . e($pageUrl($pages)) . '">' . fa_digits($pages) . '</a>';
                }
                ?>
                <a class="page-link <?= $page >= $pages ? 'disabled' : '' ?>" href="<?= e($pageUrl(min($pages, $page + 1))) ?>">›</a>
                <span class="page-info">صفحه <?= fa_digits($page) ?> از <?= fa_digits($pages) ?></span>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

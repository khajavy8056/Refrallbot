<?php
$pageUrl = static fn(int $p) => url('admin/logs') . '?' . http_build_query(array_filter(['q' => $q ?: null, 'admin' => $adminId ?: null, 'page' => $p]));
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">گزارش فعالیت مدیران</h1>
        <p class="text-muted text-sm mb-0">همه اقدامات حساس مدیران به‌صورت غیرقابل‌ویرایش ثبت می‌شود</p>
    </div>
    <a href="<?= url('admin/security') ?>" class="btn btn-secondary btn-sm">بازگشت به امنیت</a>
</div>

<div class="card">
    <form method="get" action="<?= url('admin/logs') ?>" class="filters">
        <div class="form-group" style="min-width:220px">
            <label class="form-label">جستجو</label>
            <input type="search" name="q" class="form-control" value="<?= e($q) ?>" placeholder="اقدام، نام مدیر، جزئیات…">
        </div>
        <div class="form-group">
            <label class="form-label">مدیر</label>
            <select name="admin" class="form-select">
                <option value="0">همه</option>
                <?php foreach ($admins as $a): ?>
                    <option value="<?= (int) $a['id'] ?>" <?= $adminId === (int) $a['id'] ? 'selected' : '' ?>><?= e((string) $a['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <button class="btn btn-sm" type="submit">اعمال</button>
            <a href="<?= url('admin/logs') ?>" class="btn btn-ghost btn-sm">پاک کردن</a>
        </div>
    </form>

    <?php if (empty($logs)): ?>
        <div class="empty"><div class="empty-icon">📋</div><div class="empty-title">لاگی مطابق فیلتر یافت نشد</div></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>#</th><th>اقدام</th><th>مدیر</th><th>موجودیت</th><th>جزئیات</th><th>IP</th><th>زمان</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="num" data-label="#"><?= fa_digits((int) $log['id']) ?></td>
                        <td class="ltr mono text-xs" data-label="اقدام"><?= e((string) $log['action']) ?></td>
                        <td data-label="مدیر"><?= e((string) ($log['admin_name'] ?? '—')) ?></td>
                        <td class="text-xs" data-label="موجودیت"><?= e((string) ($log['entity_type'] ?? '—')) ?><?= !empty($log['entity_id']) ? ' #' . e((string) $log['entity_id']) : '' ?></td>
                        <td class="text-xs" data-label="جزئیات"><?= e(mb_strimwidth((string) ($log['details'] ?? ''), 0, 90, '…')) ?></td>
                        <td class="ltr text-xs" data-label="IP"><?= e((string) ($log['ip'] ?? '—')) ?></td>
                        <td class="text-xs" data-label="زمان"><?= fa_date($log['created_at']) ?></td>
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
                    <span class="page-info">صفحه <?= fa_digits($page) ?> از <?= fa_digits($pages) ?> · <?= fa_digits($total) ?> ردیف</span>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

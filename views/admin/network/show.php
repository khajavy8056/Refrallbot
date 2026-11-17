<?php
// یکسان‌سازی: ردیف‌های نوادگان ستون descendant_id دارند
$rows = [];
foreach ($descendants as $row) {
    $row['id'] = (int) ($row['descendant_id'] ?? $row['id'] ?? 0);
    $rows[] = $row;
}
$byParent = [];
foreach ($rows as $row) {
    $byParent[(int) ($row['referred_by'] ?? 0)][] = $row;
}
$renderNode = static function (array $parentRow, array $byParent, int $level, int $maxDepth) use (&$renderNode): void {
    $pid = (int) ($parentRow['id'] ?? 0);
    if ($level > $maxDepth || empty($byParent[$pid])) {
        return;
    }
    echo '<div class="tree-children">';
    if ($level >= $maxDepth) {
        echo '<div class="text-xs text-muted" style="padding:6px 12px">برای مشاهده سطوح پایین‌تر، عمق نمایش را افزایش دهید.</div>';
    }
    foreach ($byParent[$pid] as $child) {
        $hasChildren = !empty($byParent[(int) $child['id']]);
        $isActive = ($child['status'] ?? 'active') === 'active';
        echo '<div class="tree-node" data-node>';
        echo '<div style="display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:10px;flex-wrap:wrap">';
        echo $hasChildren
            ? '<button type="button" class="tree-toggle" data-tree-toggle aria-label="باز/بسته">▾</button>'
            : '<span class="tree-toggle leaf"></span>';
        echo '<span class="tree-badge">سطح ' . fa_digits($level) . '</span>';
        echo '<a href="' . url('admin/users/' . (int) $child['id']) . '" style="font-weight:600">'
            . e(trim(($child['first_name'] ?? '') . ' ' . ($child['last_name'] ?? '')) ?: 'کاربر #' . $child['id']) . '</a>';
        echo '<span class="text-xs text-muted ltr">' . e((string) ($child['telegram_id'] ?? '')) . '</span>';
        if (!empty($child['username'])) {
            echo '<span class="text-xs text-muted">@' . e($child['username']) . '</span>';
        }
        echo '<span class="badge badge-' . ($isActive ? 'success' : 'danger') . ' no-dot">'
            . ($isActive ? 'فعال' : 'مسدود') . '</span>';
        echo '<span class="text-xs text-muted">درآمد: ' . wtoman((int) ($child['total_earned'] ?? 0)) . '</span>';
        echo '<a href="' . url('admin/network/' . (int) $child['id']) . '" class="btn btn-ghost btn-sm" style="margin-inline-start:auto">درخت این کاربر</a>';
        echo '</div>';
        $renderNode($child, $byParent, $level + 1, $maxDepth);
        echo '</div>';
    }
    echo '</div>';
};
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">شبکه <?= e(trim($user['first_name'] . ' ' . $user['last_name'])) ?></h1>
        <p class="text-muted text-sm mb-0">
            شناسه تلگرام: <span class="ltr"><?= e((string) $user['telegram_id']) ?></span>
            · کد دعوت: <span class="ltr"><?= e((string) $user['referral_code']) ?></span>
        </p>
    </div>
    <div class="flex gap-1 wrap">
        <?php foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $d): ?>
            <a href="?depth=<?= $d ?>" class="btn btn-sm <?= $depth === $d ? '' : 'btn-secondary' ?>">تا سطح <?= fa_digits($d) ?></a>
        <?php endforeach; ?>
        <a href="<?= url('admin/users/' . $user['id']) ?>" class="btn btn-sm btn-ghost">پروفایل کاربر</a>
    </div>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr))">
    <div class="stat"><div class="stat-head"><div class="stat-icon">👤</div><div class="stat-label">زیرمجموعه مستقیم</div></div><div class="stat-value"><?= fa_digits((int) $user['direct_ref_count']) ?></div></div>
    <div class="stat info"><div class="stat-head"><div class="stat-icon">🕸</div><div class="stat-label">کل شبکه</div></div><div class="stat-value"><?= fa_digits((int) $user['total_ref_count']) ?></div></div>
    <div class="stat success"><div class="stat-head"><div class="stat-icon">💰</div><div class="stat-label">درآمد کل</div></div><div class="stat-value" style="font-size:1.05rem"><?= wtoman($user['total_earned']) ?></div></div>
    <div class="stat purple"><div class="stat-head"><div class="stat-icon">🧾</div><div class="stat-label">ردیف‌های نمایش‌داده‌شده</div></div><div class="stat-value"><?= fa_digits(count($descendants)) ?></div><div class="stat-foot">حداکثر ۳۰۰۰ نفر</div></div>
</div>

<?php if (!empty($ancestors)): ?>
<div class="card mb-3">
    <div class="card-header"><h2 class="card-title">زنجیره معرف‌ها (بالادست)</h2></div>
    <div class="card-body">
        <div class="flex gap-2 wrap items-center">
            <?php foreach ($ancestors as $d => $ancestor): ?>
                <?php if ($ancestor === null) continue; ?>
                <a href="<?= url('admin/network/' . $ancestor['id']) ?>" class="badge badge-info no-dot" style="text-decoration:none">
                    سطح <?= fa_digits((int) $d) ?>: <?= e(trim($ancestor['first_name'] . ' ' . $ancestor['last_name'])) ?>
                </a>
                <span class="text-muted">←</span>
            <?php endforeach; ?>
            <span class="badge badge-brand no-dot">این کاربر</span>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">درخت زیرمجموعه‌ها</h2>
        <span class="card-subtitle"><?= fa_digits(count($descendants)) ?> نفر تا سطح <?= fa_digits($depth) ?></span>
    </div>
    <div class="card-body">
        <?php if (empty($descendants)): ?>
            <div class="empty">
                <div class="empty-icon">🌳</div>
                <div class="empty-title">این کاربر هنوز زیرمجموعه‌ای ندارد</div>
                <div class="empty-text">لینک دعوت اختصاصی او را در پروفایل کاربر ببینید و در صورت نیاز ارسال کنید.</div>
            </div>
        <?php else: ?>
            <div class="tree" id="network-tree">
                <?php $renderNode($user, $byParent, 1, $depth); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-tree-toggle]');
    if (!btn) return;
    const node = btn.closest('[data-node]');
    if (!node) return;
    const children = node.querySelector(':scope > .tree-children');
    if (!children) return;
    const hidden = children.style.display === 'none';
    children.style.display = hidden ? '' : 'none';
    btn.textContent = hidden ? '▾' : '▸';
});
</script>

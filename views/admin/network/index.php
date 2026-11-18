<?php
$statusBadge = static fn(string $s): array => [
    'active' => ['فعال', 'success'],
    'blocked' => ['مسدود', 'danger'],
    'suspicious' => ['مشکوک', 'warning'],
][$s] ?? [$s, 'muted'];
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">شبکه زیرمجموعه‌ها</h1>
        <p class="text-muted text-sm mb-0">نمایش درختی تا ۸ سطح — جستجو با نام، نام کاربری، شناسه تلگرام یا کد دعوت</p>
    </div>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(190px,1fr))">
    <div class="stat"><div class="stat-head"><div class="stat-icon">🕸</div><div class="stat-label">کل ارتباطات شبکه</div></div><div class="stat-value"><?= fa_digits(number_format($stats['total_relations'])) ?></div><div class="stat-foot">ردیف در جدول بستار</div></div>
    <div class="stat info"><div class="stat-head"><div class="stat-icon">👥</div><div class="stat-label">کاربران معرفی‌شده</div></div><div class="stat-value"><?= fa_digits(number_format($stats['with_refs'])) ?></div><div class="stat-foot">دارای معرف</div></div>
    <div class="stat purple"><div class="stat-head"><div class="stat-icon">📏</div><div class="stat-label">عمیق‌ترین سطح</div></div><div class="stat-value"><?= fa_digits($stats['max_depth']) ?></div><div class="stat-foot">حداکثر ۸ سطح</div></div>
    <div class="stat <?= $stats['orphans'] > 0 ? 'warning' : '' ?>"><div class="stat-head"><div class="stat-icon">⚠️</div><div class="stat-label">ناسازگاری شمارنده</div></div><div class="stat-value"><?= fa_digits(number_format($stats['orphans'])) ?></div><div class="stat-foot">شمارنده بدون ارتباط واقعی</div></div>
</div>

<div class="card mb-3">
    <div class="card-header"><h2 class="card-title">جستجوی کاربر</h2></div>
    <div class="card-body">
        <form method="get" action="<?= url('admin/network') ?>" class="flex gap-2 wrap items-center">
            <input type="search" name="q" class="form-control" style="flex:1;min-width:220px" value="<?= e($q) ?>"
                   placeholder="مثال: 123456789 یا علی رضایی یا @ali یا کد دعوت AB12CD">
            <button class="btn" type="submit">جستجو و باز کردن درخت</button>
        </form>
        <?php if ($q !== ''): ?>
            <div class="alert alert-info mt-2 mb-0"><span class="alert-icon">ℹ️</span><div>کاربری با عبارت «<?= e($q) ?>» یافت نشد.</div></div>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">شبکه‌های ریشه</h2>
            <span class="card-subtitle">کاربران بدون معرف با بیشترین شبکه</span>
        </div>
        <?php if (empty($roots)): ?>
            <div class="empty"><div class="empty-icon">🌱</div><div class="empty-title">هنوز شبکه‌ای ساخته نشده</div><div class="empty-text">با ثبت اولین معرفی توسط کاربران، شبکه اینجا نمایش داده می‌شود.</div></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>کاربر</th><th>شناسه تلگرام</th><th>زیرمجموعه مستقیم</th><th>کل شبکه</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($roots as $root): ?>
                        <tr>
                            <td data-label="کاربر">
                                <div class="user-cell">
                                    <div class="avatar avatar-sm"><?= e(mb_substr($root['first_name'] ?: '؟', 0, 1)) ?></div>
                                    <div><div class="user-cell-name"><?= e(trim($root['first_name'] . ' ' . $root['last_name'])) ?></div>
                                        <div class="user-cell-meta"><?= $root['username'] ? '@' . e($root['username']) : 'بدون یوزرنیم' ?></div></div>
                                </div>
                            </td>
                            <td class="num" data-label="شناسه"><?= fa_digits((string) $root['telegram_id']) ?></td>
                            <td class="num" data-label="مستقیم"><?= fa_digits((int) $root['direct_ref_count']) ?></td>
                            <td data-label="کل شبکه"><span class="badge badge-brand no-dot"><?= fa_digits((int) $root['total_ref_count']) ?> نفر</span></td>
                            <td data-label=""><a href="<?= url('admin/network/' . $root['id']) ?>" class="btn btn-sm btn-secondary">مشاهده درخت</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">پرنفوذترین معرف‌ها</h2>
            <span class="card-subtitle">بر اساس کل شبکه</span>
        </div>
        <?php if (empty($topInviters)): ?>
            <div class="empty"><div class="empty-icon">🏆</div><div class="empty-title">داده‌ای برای نمایش نیست</div></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>#</th><th>کاربر</th><th>کل شبکه</th><th>درآمد</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($topInviters as $i => $row): ?>
                        <tr>
                            <td data-label="#" class="num"><?= fa_digits($i + 1) ?></td>
                            <td data-label="کاربر">
                                <?= e(trim($row['first_name'] . ' ' . $row['last_name'])) ?>
                                <div class="user-cell-meta"><?= $row['username'] ? '@' . e($row['username']) : '—' ?></div>
                            </td>
                            <td data-label="کل شبکه" class="num"><?= fa_digits((int) $row['total_ref_count']) ?></td>
                            <td data-label="درآمد" class="num"><?= wtoman($row['total_earned']) ?></td>
                            <td data-label=""><a href="<?= url('admin/network/' . $row['id']) ?>" class="btn btn-sm btn-ghost">درخت</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

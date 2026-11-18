<?php
$typeLabels = $types;
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">جشنواره‌ها و کمپین‌ها</h1>
        <p class="text-muted text-sm mb-0">پاداش‌های تشویقی با شرایط زمانی و تعداد دعوت — پرداخت‌ها در دفتر مالی ثبت می‌شوند</p>
    </div>
    <a href="<?= url('admin/campaigns/create') ?>" class="btn">➕ کمپین جدید</a>
</div>

<?php if (empty($campaigns)): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-icon">🎁</div>
            <div class="empty-title">هیچ کمپینی ساخته نشده است</div>
            <div class="empty-text">با ایجاد کمپین می‌توانید برای تعداد مشخصی دعوت، پاداش نقدی تعیین کنید. پرداخت جوایز به‌صورت خودکار توسط Cron انجام می‌شود.</div>
            <a href="<?= url('admin/campaigns/create') ?>" class="btn mt-2">ایجاد اولین کمپین</a>
        </div>
    </div>
<?php else: ?>
    <div class="grid-2">
        <?php foreach ($campaigns as $c): ?>
            <?php $st = $stats[(int) $c['id']] ?? []; ?>
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2 class="card-title"><?= e((string) $c['title']) ?></h2>
                        <span class="card-subtitle">
                            <?= e($typeLabels[$c['type']] ?? (string) $c['type']) ?>
                            · <?= fa_date($c['starts_at'], false) ?> تا <?= fa_date($c['ends_at'], false) ?>
                        </span>
                    </div>
                    <span class="badge badge-<?= !empty($c['is_active']) ? 'success' : 'muted' ?>"><?= !empty($c['is_active']) ? 'فعال' : 'غیرفعال' ?></span>
                </div>
                <div class="card-body">
                    <p class="text-sm text-muted" style="min-height:38px"><?= e(mb_strimwidth((string) ($c['description'] ?? ''), 0, 140, '…')) ?: '—' ?></p>
                    <div class="grid-3" style="gap:10px">
                        <div><div class="text-xs text-muted">شرکت‌کنندگان</div><div class="font-bold"><?= fa_digits(number_format((int) ($st['participants'] ?? 0))) ?></div></div>
                        <div><div class="text-xs text-muted">برندگان</div><div class="font-bold"><?= fa_digits(number_format((int) ($st['winners'] ?? 0))) ?></div></div>
                        <div><div class="text-xs text-muted">جوایز پرداختی</div><div class="font-bold"><?= wtoman((int) ($st['paid'] ?? 0)) ?></div></div>
                    </div>
                    <div class="divider"></div>
                    <div class="text-sm">
                        <div><strong>شرط تعداد دعوت:</strong> <?= fa_digits((int) $c['required_invites']) ?> نفر</div>
                        <div><strong>جایزه ثابت:</strong> <?= wtoman((int) $c['fixed_reward']) ?>
                            <?php if ((string) $c['percent_bonus'] !== '0'): ?> · <strong>درصد پاداش:</strong> <?= fa_digits((string) $c['percent_bonus']) ?>٪<?php endif; ?>
                        </div>
                        <div><strong>حداکثر برنده:</strong> <?= (int) $c['max_winners'] > 0 ? fa_digits((int) $c['max_winners']) . ' نفر' : 'بدون محدودیت' ?></div>
                    </div>
                </div>
                <div class="card-footer flex gap-2 wrap">
                    <a href="<?= url('admin/campaigns/' . $c['id']) ?>" class="btn btn-sm btn-secondary">جزئیات و شرکت‌کنندگان</a>
                    <form method="post" action="<?= url('admin/campaigns/' . $c['id'] . '/toggle') ?>" data-ajax>
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-ghost" type="submit"><?= !empty($c['is_active']) ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

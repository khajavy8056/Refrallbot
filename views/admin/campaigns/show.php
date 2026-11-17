<?php
$statusBadge = ['active' => 'success', 'winner' => 'purple', 'reached' => 'info', 'expired' => 'muted'];
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0"><?= e((string) $campaign['title']) ?></h1>
        <p class="text-muted text-sm mb-0">
            <?= e($types[$campaign['type']] ?? (string) $campaign['type']) ?>
            · <?= fa_date($campaign['starts_at'], false) ?> تا <?= fa_date($campaign['ends_at'], false) ?>
        </p>
    </div>
    <div class="flex gap-1">
        <a href="<?= url('admin/campaigns') ?>" class="btn btn-secondary btn-sm">بازگشت</a>
        <form method="post" action="<?= url('admin/campaigns/' . $campaign['id'] . '/toggle') ?>" data-ajax>
            <?= csrf_field() ?>
            <button class="btn btn-sm <?= !empty($campaign['is_active']) ? 'btn-ghost' : '' ?>" type="submit">
                <?= !empty($campaign['is_active']) ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?>
            </button>
        </form>
    </div>
</div>

<div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(190px,1fr))">
    <div class="stat"><div class="stat-head"><div class="stat-icon">👥</div><div class="stat-label">شرکت‌کنندگان</div></div>
        <div class="stat-value"><?= fa_digits(number_format($stats['participants'])) ?></div></div>
    <div class="stat success"><div class="stat-head"><div class="stat-icon">🏆</div><div class="stat-label">برندگان</div></div>
        <div class="stat-value"><?= fa_digits(number_format($stats['winners'])) ?></div></div>
    <div class="stat purple"><div class="stat-head"><div class="stat-icon">💰</div><div class="stat-label">جوایز پرداخت‌شده</div></div>
        <div class="stat-value" style="font-size:1.1rem"><?= wtoman($stats['paid']) ?></div></div>
    <div class="stat info"><div class="stat-head"><div class="stat-icon">🎯</div><div class="stat-label">جایزه هر نفر</div></div>
        <div class="stat-value" style="font-size:1.1rem"><?= wtoman((int) $campaign['fixed_reward']) ?></div>
        <div class="stat-foot">شرط: <?= fa_digits((int) $campaign['required_invites']) ?> دعوت</div></div>
</div>

<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header"><h2 class="card-title">پرداخت دستی جایزه</h2></div>
        <form method="post" action="<?= url('admin/campaigns/' . $campaign['id'] . '/grant') ?>" data-ajax>
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">کاربر (شناسه تلگرام، کد دعوت یا نام کاربری)</label>
                    <input type="text" name="user" class="form-control ltr" dir="ltr" required placeholder="123456789 یا AB12CD یا @ali">
                </div>
                <div class="form-group">
                    <label class="form-label">مبلغ جایزه (تومان)</label>
                    <input type="text" name="amount" class="form-control ltr" data-money inputmode="numeric" required>
                </div>
                <div class="form-group">
                    <label class="form-label">تعداد دعوت محقق‌شده (اختیاری)</label>
                    <input type="number" name="progress" class="form-control ltr" min="0" value="0">
                </div>
                <div class="alert alert-info mb-0">
                    <span class="alert-icon">ℹ️</span>
                    <div>هر کاربر فقط یک‌بار در هر کمپین جایزه می‌گیرد؛ پرداخت دوم با خطا رد می‌شود (یکتایی در سطح دیتابیس).</div>
                </div>
            </div>
            <div class="card-footer">
                <button class="btn btn-block" type="submit" data-confirm="جایزه پرداخت شود؟">💸 پرداخت جایزه</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">پرداخت خودکار</h2></div>
        <div class="card-body">
            <p class="text-sm text-muted">
                جوایز کمپین‌های نوع «تعداد دعوت مشخص» با اجرای Cron محاسبه و پرداخت می‌شوند.
                اکنون می‌توانید همین حالا پردازش را دستی اجرا کنید.
            </p>
            <form method="post" action="<?= url('admin/campaigns/' . $campaign['id'] . '/grant') ?>" data-ajax>
                <?= csrf_field() ?>
                <input type="hidden" name="auto" value="1">
                <input type="hidden" name="amount" value="0">
                <button class="btn btn-success btn-block" type="submit" data-confirm="پردازش خودکار جوایز این کمپین اجرا شود؟">⚙️ اجرای پردازش جوایز</button>
            </form>
            <div class="divider"></div>
            <div class="text-sm">
                <div><strong>مبلغ ثابت:</strong> <?= wtoman((int) $campaign['fixed_reward']) ?></div>
                <div><strong>درصد پاداش:</strong> <?= fa_digits((string) $campaign['percent_bonus']) ?>٪</div>
                <div><strong>حداکثر برنده:</strong> <?= (int) $campaign['max_winners'] ?: 'نامحدود' ?></div>
                <div><strong>وضعیت:</strong> <?= !empty($campaign['is_active']) ? 'فعال' : 'غیرفعال' ?></div>
            </div>
            <div class="divider"></div>
            <div class="text-sm" style="white-space:pre-wrap;line-height:1.9"><?= e((string) ($campaign['description'] ?? '')) ?></div>
        </div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2 class="card-title">شرکت‌کنندگان</h2></div>
        <?php if (empty($participants)): ?>
            <div class="empty" style="padding:26px"><div class="empty-icon">👥</div><div class="empty-title">هنوز شرکت‌کننده‌ای ثبت نشده</div></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>کاربر</th><th>پیشرفت</th><th>رتبه</th><th>وضعیت</th><th>جایزه</th></tr></thead>
                    <tbody>
                    <?php foreach ($participants as $p): ?>
                        <tr>
                            <td data-label="کاربر">
                                <a href="<?= url('admin/users/' . $p['user_id']) ?>"><?= e(trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''))) ?></a>
                                <div class="user-cell-meta ltr"><?= e((string) ($p['telegram_id'] ?? '')) ?></div>
                            </td>
                            <td class="num" data-label="پیشرفت"><?= fa_digits((int) $p['progress']) ?></td>
                            <td class="num" data-label="رتبه"><?= $p['rank'] !== null ? fa_digits((int) $p['rank']) : '—' ?></td>
                            <td data-label="وضعیت"><span class="badge badge-<?= $statusBadge[$p['status']] ?? 'muted' ?>"><?= e((string) $p['status']) ?></span></td>
                            <td class="num" data-label="جایزه"><?= wtoman((int) $p['rewarded_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">جوایز پرداخت‌شده</h2></div>
        <?php if (empty($rewards)): ?>
            <div class="empty" style="padding:26px"><div class="empty-icon">🎁</div><div class="empty-title">هنوز جایزه‌ای پرداخت نشده</div></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>کاربر</th><th>مبلغ</th><th>نوع</th><th>یادداشت</th><th>تاریخ</th></tr></thead>
                    <tbody>
                    <?php foreach ($rewards as $r): ?>
                        <tr>
                            <td data-label="کاربر"><?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?></td>
                            <td class="num" data-label="مبلغ"><?= wtoman((int) $r['amount']) ?></td>
                            <td data-label="نوع"><?= e((string) $r['type']) ?></td>
                            <td class="text-xs" data-label="یادداشت"><?= e((string) ($r['note'] ?? '—')) ?></td>
                            <td class="text-xs" data-label="تاریخ"><?= fa_date($r['granted_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

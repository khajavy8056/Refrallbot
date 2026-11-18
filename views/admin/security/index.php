<?php
$severityBadge = ['low' => 'muted', 'medium' => 'warning', 'high' => 'danger'];
$statusBadge = ['open' => 'warning', 'reviewed' => 'info', 'dismissed' => 'muted', 'confirmed' => 'danger'];
$userStatusLabel = ['active' => 'فعال', 'blocked' => 'مسدود', 'suspicious' => 'مشکوک'];
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">امنیت و کنترل تقلب</h1>
        <p class="text-muted text-sm mb-0">رویدادهای مشکوک ثبت‌شده به‌صورت خودکار توسط سیستم — قابل بررسی و اقدام</p>
    </div>
    <a href="<?= url('admin/logs') ?>" class="btn btn-secondary btn-sm">📋 گزارش فعالیت مدیران</a>
</div>

<div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr))">
    <div class="stat warning"><div class="stat-head"><div class="stat-icon">📌</div><div class="stat-label">رویداد بررسی‌نشده</div></div>
        <div class="stat-value"><?= fa_digits(number_format($counts['open'])) ?></div></div>
    <div class="stat danger"><div class="stat-head"><div class="stat-icon">🔥</div><div class="stat-label">رویداد با شدت بالا</div></div>
        <div class="stat-value"><?= fa_digits(number_format($counts['high'])) ?></div></div>
    <div class="stat"><div class="stat-head"><div class="stat-icon">👤</div><div class="stat-label">کاربران مشکوک</div></div>
        <div class="stat-value"><?= fa_digits(number_format($counts['suspicious'])) ?></div></div>
    <div class="stat info"><div class="stat-head"><div class="stat-icon">💳</div><div class="stat-label">کارت تکراری</div></div>
        <div class="stat-value"><?= fa_digits(number_format($counts['card_reuse'])) ?></div></div>
</div>

<div class="flex gap-1 wrap mb-3">
    <?php foreach (['' => 'همه', 'open' => 'بررسی‌نشده', 'reviewed' => 'بررسی‌شده', 'confirmed' => 'تأیید تخلف', 'dismissed' => 'رد‌شده'] as $key => $label): ?>
        <a href="<?= url('admin/security') . ($key !== '' ? '?status=' . $key : '') ?>"
           class="btn btn-sm <?= $statusFilter === $key ? '' : 'btn-secondary' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="card mb-3">
    <div class="card-header"><h2 class="card-title">رویدادهای امنیتی</h2></div>
    <?php if (empty($events)): ?>
        <div class="empty">
            <div class="empty-icon">🛡</div>
            <div class="empty-title">رویداد مشکوکی ثبت نشده</div>
            <div class="empty-text">سامانه به‌صورت خودکار چند حساب با IP یکسان، ثبت‌نام یا معرفی پرتعداد و استفاده مجدد از کارت را بررسی می‌کند.</div>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>#</th><th>نوع رویداد</th><th>شدت</th><th>کاربر</th><th>ریسک</th><th>وضعیت</th><th>تاریخ</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($events as $e): ?>
                    <tr>
                        <td class="num" data-label="#"><?= fa_digits((int) $e['id']) ?></td>
                        <td data-label="نوع"><?= e(\RB\Controllers\Admin\SecurityController::typeLabel((string) $e['type'])) ?>
                            <?php if (!empty($e['details'])): ?>
                                <div class="user-cell-meta"><?= e(mb_strimwidth((string) $e['details'], 0, 70, '…')) ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="شدت"><span class="badge badge-<?= $severityBadge[$e['severity']] ?? 'muted' ?>"><?= e((string) $e['severity']) ?></span></td>
                        <td data-label="کاربر">
                            <?php if (!empty($e['user_id'])): ?>
                                <a href="<?= url('admin/users/' . $e['user_id']) ?>"><?= e(trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? '')) ?: 'کاربر #' . $e['user_id']) ?></a>
                                <div class="user-cell-meta ltr"><?= e((string) ($e['telegram_id'] ?? '')) ?></div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="num" data-label="ریسک"><?= fa_digits((int) ($e['risk_score'] ?? 0)) ?></td>
                        <td data-label="وضعیت"><span class="badge badge-<?= $statusBadge[$e['status']] ?? 'muted' ?>"><?= e(\RB\Controllers\Admin\SecurityController::statusLabel((string) $e['status'])) ?></span></td>
                        <td class="text-xs" data-label="تاریخ"><?= fa_date($e['created_at']) ?></td>
                        <td data-label="">
                            <?php if ($e['status'] === 'open'): ?>
                                <div class="actions" style="flex-wrap:wrap">
                                    <form method="post" action="<?= url('admin/security/' . $e['id'] . '/review') ?>" data-ajax>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="status" value="reviewed">
                                        <button class="btn btn-sm btn-secondary" type="submit">بررسی شد</button>
                                    </form>
                                    <form method="post" action="<?= url('admin/security/' . $e['id'] . '/review') ?>" data-ajax>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="status" value="confirmed">
                                        <button class="btn btn-sm btn-danger" type="submit" data-confirm="تخلف تأیید شود؟">تأیید تخلف</button>
                                    </form>
                                    <form method="post" action="<?= url('admin/security/' . $e['id'] . '/review') ?>" data-ajax>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="status" value="dismissed">
                                        <button class="btn btn-sm btn-ghost" type="submit">رد</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <span class="text-xs text-muted"><?= !empty($e['reviewed_at']) ? fa_date($e['reviewed_at']) : '—' ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title">کاربران پرخطر</h2>
        <span class="card-subtitle">امتیاز ریسک ۲۰ یا بیشتر</span></div>
    <?php if (empty($suspiciousUsers)): ?>
        <div class="empty" style="padding:26px"><div class="empty-icon">✅</div><div class="empty-title">کاربر پرخطری وجود ندارد</div></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>کاربر</th><th>شناسه تلگرام</th><th>امتیاز ریسک</th><th>وضعیت</th><th>زیرمجموعه</th><th>درآمد</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($suspiciousUsers as $u): ?>
                    <tr>
                        <td data-label="کاربر"><?= e(trim($u['first_name'] . ' ' . $u['last_name'])) ?></td>
                        <td class="num" data-label="شناسه"><?= fa_digits((string) $u['telegram_id']) ?></td>
                        <td data-label="ریسک"><span class="badge badge-<?= (int) $u['risk_score'] >= 50 ? 'danger' : 'warning' ?>"><?= fa_digits((int) $u['risk_score']) ?></span></td>
                        <td data-label="وضعیت"><?= e($userStatusLabel[$u['status']] ?? (string) $u['status']) ?></td>
                        <td class="num" data-label="زیرمجموعه"><?= fa_digits((int) $u['total_ref_count']) ?></td>
                        <td class="num" data-label="درآمد"><?= wtoman((int) $u['total_earned']) ?></td>
                        <td data-label=""><a href="<?= url('admin/users/' . $u['id']) ?>" class="btn btn-sm btn-secondary">بررسی</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

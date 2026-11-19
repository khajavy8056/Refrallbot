<?php
$exportQuery = array_filter($filters, static fn($v) => $v !== null && $v !== '');
$exportUrl = url('admin/reports/' . $type . '/export') . ($exportQuery ? '?' . http_build_query($exportQuery) : '');
$moneyCols = ['amount', 'base_amount', 'total_earned', 'wallet_balance', 'paid', 'paid_amount', 'pending_amount', 'total_amount', 'direct_paid', 'indirect_paid', 'welcome_paid'];
$countCols = [
    'users', 'users_count', 'qualified_count', 'tx_count', 'registered_users', 'new_month',
    'linked_accounts', 'direct_referrals', 'indirect_referrals', 'qualified_referrals',
    'direct_ref_count', 'indirect_ref_count', 'total_ref_count', 'channel_verified_members',
    'direct_members', 'cross_members', 'verified_members', 'messages_sent', 'errors_24h',
    'total_targets', 'sent_count', 'failed_count', 'blocked_count', 'participants',
    'id', 'level', 'depth', 'risk_score',
];
$layerSum = $totals['summary'] ?? null;
$activeMsg = (isset($activeMessengers) && is_array($activeMessengers)) ? $activeMessengers : [];
$msgTot = (isset($messengerTotals) && is_array($messengerTotals)) ? $messengerTotals : [];
$canFilterPlat = !empty($platformFilterable) && count($activeMsg) > 1;
$errCats = (isset($errorCategories) && is_array($errorCategories)) ? $errorCategories : [];
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">📈 گزارش‌های مدیریتی و مالی</h1>
        <p class="text-muted text-sm mb-0">گزارش زنده با مجموع کل سیستم و تفکیک پیام‌رسان‌های فعال و متصل — خروجی CSV سازگار با Excel فارسی</p>
    </div>
    <div class="flex gap-2 wrap">
        <?php if ($type === 'errors' && !empty($rows)): ?>
            <form method="post" action="<?= url('admin/reports/errors/clear') ?>" data-ajax>
                <?= csrf_field() ?>
                <input type="hidden" name="platform" value="<?= e((string) ($filters['platform'] ?? '')) ?>">
                <button class="btn btn-danger btn-sm" type="submit" data-confirm="لاگ خطاهای نمایش‌داده‌شده پاک‌سازی شود؟">🧹 پاک‌سازی خطاها</button>
            </form>
        <?php endif; ?>
        <a href="<?= e($exportUrl) ?>" class="btn btn-secondary btn-sm">⬇️ دانلود خروجی CSV</a>
    </div>
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
<?php elseif (in_array($type, ['messengers', 'users', 'growth'], true)): ?>
    <div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(190px,1fr))">
        <div class="stat">
            <div class="stat-head"><div class="stat-icon">👥</div><div class="stat-label">مجموع کل کاربران سیستم</div></div>
            <div class="stat-value"><?= fa_digits(number_format((int) ($msgTot['total_users'] ?? 0))) ?></div>
            <div class="stat-foot">امروز: +<?= fa_digits((int) ($msgTot['today_users'] ?? 0)) ?> · ۳۰ روز اخیر: +<?= fa_digits((int) ($msgTot['month_users'] ?? 0)) ?></div>
        </div>
        <?php foreach ($activeMsg as $mp => $mi): ?>
            <div class="stat" style="border-top:3px solid <?= e((string) ($mi['color'] ?? 'var(--brand-500)')) ?>">
                <div class="stat-head"><div class="stat-icon"><?= e((string) ($mi['icon'] ?? '🤖')) ?></div><div class="stat-label"><?= e((string) ($mi['users_label'] ?? ('کاربران ' . ($mi['name'] ?? $mp)))) ?></div></div>
                <div class="stat-value"><?= fa_digits(number_format((int) ($mi['independent_users'] ?? $mi['registered_users'] ?? 0))) ?></div>
                <div class="stat-foot">امروز: +<?= fa_digits((int) ($mi['today_users'] ?? 0)) ?> · ۳۰ روز: +<?= fa_digits((int) ($mi['month_users'] ?? 0)) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php elseif ($type === 'referrals'): ?>
    <div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(190px,1fr))">
        <div class="stat info">
            <div class="stat-head"><div class="stat-icon">🎯</div><div class="stat-label">کل رفرال‌های سیستم</div></div>
            <div class="stat-value"><?= fa_digits(number_format((int) ($msgTot['direct_referrals'] ?? 0))) ?><small> مستقیم</small></div>
            <div class="stat-foot">غیرمستقیم: <?= fa_digits((int) ($msgTot['indirect_referrals'] ?? 0)) ?> · تاییدشده: <?= fa_digits((int) ($msgTot['qualified_referrals'] ?? 0)) ?></div>
        </div>
        <?php foreach ($activeMsg as $mp => $mi): ?>
            <div class="stat" style="border-top:3px solid <?= e((string) ($mi['color'] ?? 'var(--brand-500)')) ?>">
                <div class="stat-head"><div class="stat-icon"><?= e((string) ($mi['icon'] ?? '🤖')) ?></div><div class="stat-label">رفرال <?= e((string) ($mi['name'] ?? $mp)) ?></div></div>
                <div class="stat-value"><?= fa_digits(number_format((int) ($mi['direct_referrals'] ?? 0))) ?><small> مستقیم</small></div>
                <div class="stat-foot">غیرمستقیم: <?= fa_digits((int) ($mi['indirect_referrals'] ?? 0)) ?> · تاییدشده: <?= fa_digits((int) ($mi['qualified_referrals'] ?? 0)) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php elseif ($type === 'commissions'): ?>
    <div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(195px,1fr))">
        <div class="stat success">
            <div class="stat-head"><div class="stat-icon">💰</div><div class="stat-label">کل پاداش پرداختی سیستم</div></div>
            <div class="stat-value" style="font-size:1.05rem"><?= wtoman((int) ($msgTot['total_paid'] ?? 0)) ?></div>
            <div class="stat-foot">مستقیم: <?= wtoman((int) ($msgTot['direct_paid'] ?? 0)) ?> · غیرمستقیم: <?= wtoman((int) ($msgTot['indirect_paid'] ?? 0)) ?></div>
        </div>
        <?php foreach ($activeMsg as $mp => $mi): ?>
            <div class="stat" style="border-top:3px solid <?= e((string) ($mi['color'] ?? 'var(--brand-500)')) ?>">
                <div class="stat-head"><div class="stat-icon"><?= e((string) ($mi['icon'] ?? '🤖')) ?></div><div class="stat-label">پاداش <?= e((string) ($mi['name'] ?? $mp)) ?></div></div>
                <div class="stat-value" style="font-size:1.05rem"><?= wtoman((int) ($mi['total_paid'] ?? 0)) ?></div>
                <div class="stat-foot">L0: <?= wtoman((int) ($mi['direct_paid'] ?? 0)) ?> · L1+: <?= wtoman((int) ($mi['indirect_paid'] ?? 0)) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php elseif ($type === 'channels'): ?>
    <div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(195px,1fr))">
        <div class="stat info">
            <div class="stat-head"><div class="stat-icon">📢</div><div class="stat-label">کل عضویت‌های تأییدشده</div></div>
            <div class="stat-value"><?= fa_digits(number_format((int) ($msgTot['channel_members_total'] ?? 0))) ?></div>
            <div class="stat-foot"><?= fa_digits((int) ($msgTot['active_channels'] ?? 0)) ?> کانال فعال در سیستم</div>
        </div>
        <?php foreach ($activeMsg as $mp => $mi): ?>
            <div class="stat" style="border-top:3px solid <?= e((string) ($mi['color'] ?? 'var(--brand-500)')) ?>">
                <div class="stat-head"><div class="stat-icon"><?= e((string) ($mi['icon'] ?? '🤖')) ?></div><div class="stat-label">کانال‌های <?= e((string) ($mi['name'] ?? $mp)) ?></div></div>
                <div class="stat-value"><?= fa_digits(number_format((int) ($mi['channel_members_total'] ?? 0))) ?><small> تأیید</small></div>
                <div class="stat-foot"><?= fa_digits((int) ($mi['active_channels'] ?? 0)) ?> کانال فعال<?= \RB\Messengers\MessengerCapabilities::supports((string) $mp, 'channel_check_api') ? ' (API رسمی)' : ' (بدون API بررسی)' ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php elseif (in_array($type, ['messages', 'broadcasts'], true)): ?>
    <div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(195px,1fr))">
        <div class="stat">
            <div class="stat-head"><div class="stat-icon">✉️</div><div class="stat-label">کل پیام‌های ارسالی سیستم</div></div>
            <div class="stat-value"><?= fa_digits(number_format((int) ($msgTot['messages_sent'] ?? 0))) ?></div>
            <div class="stat-foot">همگانی: <?= fa_digits((int) ($msgTot['broadcasts_sent'] ?? 0)) ?> · ناموفق: <?= fa_digits((int) ($msgTot['messages_failed'] ?? 0)) ?></div>
        </div>
        <?php foreach ($activeMsg as $mp => $mi): ?>
            <div class="stat" style="border-top:3px solid <?= e((string) ($mi['color'] ?? 'var(--brand-500)')) ?>">
                <div class="stat-head"><div class="stat-icon"><?= e((string) ($mi['icon'] ?? '🤖')) ?></div><div class="stat-label">ارسالی <?= e((string) ($mi['name'] ?? $mp)) ?></div></div>
                <div class="stat-value"><?= fa_digits(number_format((int) ($mi['messages_sent'] ?? 0))) ?></div>
                <div class="stat-foot">دریافتی: <?= fa_digits((int) ($mi['updates_received'] ?? 0)) ?> · ناموفق: <?= fa_digits((int) ($mi['messages_failed'] ?? 0)) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php elseif ($type === 'errors'): ?>
    <div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(195px,1fr))">
        <div class="stat <?= ((int) ($msgTot['errors_24h'] ?? 0)) > 0 ? 'danger' : 'success' ?>">
            <div class="stat-head"><div class="stat-icon">🩺</div><div class="stat-label">خطاهای ارتباطی (۲۴ ساعت)</div></div>
            <div class="stat-value"><?= fa_digits(number_format((int) ($msgTot['errors_24h'] ?? 0))) ?></div>
            <div class="stat-foot">مجموع کل پیام‌رسان‌های فعال</div>
        </div>
        <?php foreach ($activeMsg as $mp => $mi): ?>
            <div class="stat" style="border-top:3px solid <?= e((string) ($mi['color'] ?? 'var(--brand-500)')) ?>">
                <div class="stat-head"><div class="stat-icon"><?= e((string) ($mi['icon'] ?? '🤖')) ?></div><div class="stat-label">سلامت <?= e((string) ($mi['name'] ?? $mp)) ?></div></div>
                <div class="stat-value"><?= fa_digits(number_format((int) ($mi['errors_24h'] ?? 0))) ?><small> خطا</small></div>
                <div class="stat-foot"><?= e((string) ($mi['status_badge']['label'] ?? 'متصل')) ?><?= !empty($mi['bot_username']) ? ' · @' . e(ltrim((string) $mi['bot_username'], '@')) : '' ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><?= e($types[$type]) ?></h2>
        <span class="card-subtitle">
            <?= fa_digits(count($rows)) ?> ردیف
            <?php if (isset($totals['amount'])): ?> · مجموع کل: <?= wtoman((int) $totals['amount']) ?><?php endif; ?>
            <?php if (isset($totals['count'])): ?> · جمع شمارشی: <?= fa_digits(number_format((int) $totals['count'])) ?><?php endif; ?>
        </span>
    </div>

    <?php if (!in_array($type, ['layers', 'messengers', 'campaigns'], true)): ?>
        <form method="get" action="<?= url('admin/reports/' . $type) ?>" class="filters">
            <?php if ($type !== 'growth'): ?>
                <div class="form-group" style="min-width:180px">
                    <label class="form-label">جستجو</label>
                    <input type="search" name="q" class="form-control" value="<?= e($filters['q'] ?? '') ?>" placeholder="نام، شناسه، کد…">
                </div>
            <?php endif; ?>
            <?php if ($canFilterPlat): ?>
                <div class="form-group">
                    <label class="form-label">پیام‌رسان فعال</label>
                    <select name="platform" class="form-select">
                        <option value="">🌐 همه پیام‌رسان‌های فعال</option>
                        <?php foreach ($activeMsg as $mp => $mi): ?>
                            <option value="<?= e($mp) ?>" <?= ($filters['platform'] ?? '') === $mp ? 'selected' : '' ?>>
                                <?= e(($mi['icon'] ?? '') . ' ' . ($mi['name'] ?? $mp)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <?php if ($type === 'errors' && !empty($errCats)): ?>
                <div class="form-group">
                    <label class="form-label">بخش خطا</label>
                    <select name="category" class="form-select">
                        <option value="">همه بخش‌ها</option>
                        <?php foreach ($errCats as $ck => $cl): ?>
                            <option value="<?= e($ck) ?>" <?= ($filters['category'] ?? '') === $ck ? 'selected' : '' ?>><?= e($cl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <?php if ($type !== 'channels'): ?>
                <div class="form-group">
                    <label class="form-label">از تاریخ</label>
                    <input type="text" name="from" class="form-control ltr" value="<?= e($filters['from'] ?? '') ?>" placeholder="1405/01/01">
                </div>
                <div class="form-group">
                    <label class="form-label">تا تاریخ</label>
                    <input type="text" name="to" class="form-control ltr" value="<?= e($filters['to'] ?? '') ?>" placeholder="1405/12/29">
                </div>
            <?php endif; ?>
            <div class="form-group">
                <button class="btn btn-sm" type="submit">اعمال</button>
                <a href="<?= url('admin/reports/' . $type) ?>" class="btn btn-ghost btn-sm">پاک کردن</a>
            </div>
        </form>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <div class="empty">
            <div class="empty-icon"><?= $type === 'errors' ? '✅' : '📊' ?></div>
            <div class="empty-title">
                <?php if ($type === 'messengers' && empty($activeMsg)): ?>
                    هنوز هیچ پیام‌رسانی فعال و متصل نشده است
                <?php elseif ($type === 'errors'): ?>
                    هیچ خطای ارتباطی ثبت نشده است
                <?php else: ?>
                    داده‌ای برای این گزارش وجود ندارد
                <?php endif; ?>
            </div>
            <div class="empty-text">
                <?php if ($type === 'messengers' && empty($activeMsg)): ?>
                    از بخش <a href="<?= url('admin/messengers') ?>">پیام‌رسان‌ها</a> ربات مورد نظر را فعال و متصل کنید تا آمار اختصاصی آن به‌صورت خودکار نمایش داده شود.
                <?php elseif ($type === 'errors'): ?>
                    ارتباط پیام‌رسان‌های فعال پایدار است و خطایی در بازه انتخابی ثبت نشده است.
                <?php else: ?>
                    پس از ثبت فعالیت در پیام‌رسان‌های فعال، گزارش‌ها به‌صورت زنده از دیتابیس ساخته می‌شوند.
                <?php endif; ?>
            </div>
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
                            $isCount = in_array($key, $countCols, true) || !empty($meta['is_count']);
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

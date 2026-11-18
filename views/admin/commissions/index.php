<?php
$conditions = ['joined' => 'عضویت در ربات (شروع)', 'profile' => 'تکمیل پروفایل (نام و نام خانوادگی)',
    'phone' => 'ثبت شماره تماس', 'channels' => 'عضویت در کانال‌های اجباری', 'approved' => 'تأیید دستی مدیر'];
$activeConditions = $settings->qualifyConditions ?: ['joined'];
$levelRows = [];
foreach ($settings->levels as $row) {
    $levelRows[(int) ($row['level'] ?? 0)] = $row;
}
$totalPercent = $settings->totalPercent();
$byLevel = [];
foreach ($stats['by_level'] as $row) {
    $byLevel[(int) $row['level']] = $row;
}
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">تنظیمات پورسانت و پاداش</h1>
        <p class="text-muted text-sm mb-0">
            نسخه فعال: <strong><?= fa_digits($settings->version) ?></strong>
            · ذخیره هر تغییر، نسخه جدید می‌سازد و تراکنش‌های قبلی دست‌نخورده می‌مانند
        </p>
    </div>
</div>

<div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(190px,1fr))">
    <div class="stat purple"><div class="stat-head"><div class="stat-icon">💰</div><div class="stat-label">کل پورسانت پرداخت‌شده</div></div>
        <div class="stat-value" style="font-size:1.1rem"><?= wtoman($stats['total_commissions']) ?></div>
        <div class="stat-foot">در <?= fa_digits($stats['events']) ?> رویداد پاداش</div></div>
    <div class="stat info"><div class="stat-head"><div class="stat-icon">🎯</div><div class="stat-label">پاداش مستقیم</div></div>
        <div class="stat-value" style="font-size:1.1rem"><?= $settings->directEnabled ? wtoman($settings->directReward) : 'غیرفعال' ?></div>
        <div class="stat-foot">برای هر معرفی واجد شرایط</div></div>
    <div class="stat success"><div class="stat-head"><div class="stat-icon">％</div><div class="stat-label">مجموع درصد لایه‌ها</div></div>
        <div class="stat-value"><?= fa_digits($totalPercent) ?>٪</div>
        <div class="stat-foot">حداکثر مجاز: ۱۰۰٪</div></div>
    <div class="stat <?= $settings->multiLevelEnabled ? 'success' : 'warning' ?>"><div class="stat-head"><div class="stat-icon">🪜</div><div class="stat-label">سیستم چندلایه</div></div>
        <div class="stat-value" style="font-size:1rem"><?= $settings->multiLevelEnabled ? 'فعال' : 'غیرفعال' ?></div>
        <div class="stat-foot"><?= fa_digits($settings->maxLevels) ?> لایه فعال</div></div>
</div>

<form method="post" action="<?= url('admin/commissions') ?>" id="commission-form">
    <?= csrf_field() ?>
    <div class="grid-2 mb-3">
        <div class="card">
            <div class="card-header"><h2 class="card-title">پاداش مستقیم (سطح ۰)</h2></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="checkbox">
                        <input type="checkbox" name="direct_enabled" value="1" <?= $settings->directEnabled ? 'checked' : '' ?>>
                        <span class="checkbox-text">پاداش مستقیم فعال باشد</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-label">مبلغ پاداش برای هر معرفی واجد شرایط (تومان)</label>
                    <input type="text" name="direct_reward" class="form-control ltr" data-money inputmode="numeric"
                           value="<?= e(number_format($settings->directReward)) ?>" required>
                    <div class="form-hint">این مبلغ به معرفِ مستقیمِ کاربر جدید پرداخت می‌شود. معادل ریالی فقط برای نمایش است.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">مبنای محاسبه درصد لایه‌ها</label>
                    <select name="percent_base" class="form-select">
                        <option value="direct_reward" <?= $settings->percentBase === 'direct_reward' ? 'selected' : '' ?>>مبلغ پاداش مستقیم</option>
                        <option value="fixed_base" <?= $settings->percentBase === 'fixed_base' ? 'selected' : '' ?>>مبلغ ثابت پایه (زیر)</option>
                        <option value="event_amount" <?= $settings->percentBase === 'event_amount' ? 'selected' : '' ?>>مبلغ رویداد (همان پاداش مستقیم رویداد)</option>
                    </select>
                    <div class="form-hint">درصدهای هر لایه روی این مبنا محاسبه می‌شوند.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">مبلغ ثابت پایه (تومان)</label>
                    <input type="text" name="fixed_base_amount" class="form-control ltr" data-money inputmode="numeric"
                           value="<?= e(number_format($settings->fixedBaseAmount)) ?>">
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">شرایط واجد شرایط شدن</h2></div>
            <div class="card-body">
                <?php foreach ($conditions as $key => $label): ?>
                    <label class="checkbox" style="display:flex;margin-bottom:8px">
                        <input type="checkbox" name="conditions[]" value="<?= e($key) ?>" <?= in_array($key, $activeConditions, true) ? 'checked' : '' ?>>
                        <span class="checkbox-text"><?= e($label) ?></span>
                    </label>
                <?php endforeach; ?>
                <div class="form-hint">پاداش و پورسانت فقط زمانی پرداخت می‌شود که کاربر معرفی‌شده همه شرایط انتخاب‌شده را داشته باشد.</div>
                <div class="divider"></div>
                <div class="form-group">
                    <label class="form-label">سقف پاداش هر رویداد (تومان — ۰ = بدون سقف)</label>
                    <input type="text" name="max_payout_per_event" class="form-control ltr" data-money inputmode="numeric"
                           value="<?= e(number_format($settings->maxPayoutPerEvent)) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">سقف کل پورسانت روزانه هر کاربر (تومان — ۰ = بدون سقف)</label>
                    <input type="text" name="daily_payout_cap" class="form-control ltr" data-money inputmode="numeric"
                           value="<?= e(number_format($settings->dailyPayoutCap)) ?>">
                </div>
                <label class="checkbox">
                    <input type="checkbox" name="blocked_users_no_commission" value="1" <?= $settings->blockedUsersNoCommission ? 'checked' : '' ?>>
                    <span class="checkbox-text">کاربران مسدود/مشکوک پورسانت نگیرند</span>
                </label>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h2 class="card-title">لایه‌های پورسانت (۱ تا ۸)</h2>
            <span class="card-subtitle">لایه غیرفعال، هیچ پورسانتی نمی‌گیرد (پرداخت به لایه بالاتر منتقل نمی‌شود)</span>
        </div>
        <div class="card-body">
            <div class="form-row mb-3">
                <div class="form-group">
                    <label class="checkbox">
                        <input type="checkbox" name="multi_level_enabled" value="1" <?= $settings->multiLevelEnabled ? 'checked' : '' ?>>
                        <span class="checkbox-text">سیستم پورسانت چندلایه فعال باشد</span>
                    </label>
                </div>
                <div class="form-group" style="max-width:220px">
                    <label class="form-label">تعداد لایه‌های فعال</label>
                    <select name="max_levels" class="form-select" id="max-levels">
                        <?php for ($i = 1; $i <= 8; $i++): ?>
                            <option value="<?= $i ?>" <?= $settings->maxLevels === $i ? 'selected' : '' ?>><?= fa_digits($i) ?> لایه</option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-inline-start:auto;text-align:end">
                    <label class="form-label">مجموع درصدها</label>
                    <div class="font-bold" id="total-percent"><?= fa_digits($totalPercent) ?>٪</div>
                </div>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>لایه</th><th>فعال</th><th>درصد</th><th>مبلغ تقریبی هر معرفی</th><th>پرداخت‌شده تا امروز</th><th>تعداد</th>
                    </tr></thead>
                    <tbody>
                    <?php for ($level = 1; $level <= 8; $level++): ?>
                        <?php
                        $row = $levelRows[$level] ?? ['percent' => '0', 'enabled' => false];
                        $percent = (string) ($row['percent'] ?? '0');
                        $enabled = (bool) ($row['enabled'] ?? false);
                        $base = $settings->percentBase === 'fixed_base' ? $settings->fixedBaseAmount : $settings->directReward;
                        $approx = (int) round($base * (float) $percent / 100);
                        $paid = (int) ($byLevel[$level]['s'] ?? 0);
                        $count = (int) ($byLevel[$level]['c'] ?? 0);
                        ?>
                        <tr data-level-row>
                            <td data-label="لایه"><span class="badge badge-brand no-dot">لایه <?= fa_digits($level) ?></span></td>
                            <td data-label="فعال">
                                <label class="switch">
                                    <input type="checkbox" name="levels[<?= $level ?>][enabled]" value="1" <?= $enabled ? 'checked' : '' ?> data-level-enabled>
                                    <span class="switch-track"></span>
                                </label>
                            </td>
                            <td data-label="درصد">
                                <div class="input-group" style="max-width:130px">
                                    <input type="text" class="form-control ltr" inputmode="decimal"
                                           name="levels[<?= $level ?>][percent]" value="<?= e($percent) ?>" data-level-percent>
                                    <span class="btn btn-secondary" style="pointer-events:none">٪</span>
                                </div>
                            </td>
                            <td data-label="مبلغ تقریبی" class="num" data-approx><?= fa_digits(number_format($approx)) ?> تومان</td>
                            <td data-label="پرداخت‌شده" class="num"><?= wtoman($paid) ?></td>
                            <td data-label="تعداد" class="num"><?= fa_digits($count) ?></td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <div class="alert alert-info mt-2 mb-0">
                <span class="alert-icon">ℹ️</span>
                <div>مجموع درصدهای لایه‌های فعال نباید از ۱۰۰٪ بیشتر شود. اگر لایه‌ای غیرفعال باشد، پورسانت آن حذف می‌شود و به لایه بالاتر منتقل نمی‌شود.</div>
            </div>
        </div>
        <div class="card-footer flex justify-between items-center wrap gap-2">
            <span class="text-xs text-muted">تغییرات به‌صورت نسخه جدید ذخیره می‌شود و روی رویدادهای آینده اثر می‌گذارد.</span>
            <button class="btn" type="submit" data-confirm="تنظیمات پورسانت به‌صورت نسخه جدید ذخیره شود؟">💾 ذخیره نسخه جدید</button>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header"><h2 class="card-title">تاریخچه نسخه‌ها</h2></div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>نسخه</th><th>پاداش مستقیم</th><th>چندلایه</th><th>لایه‌ها</th><th>مجموع درصد</th><th>مبنا</th><th>تاریخ</th></tr></thead>
            <tbody>
            <?php if (empty($versions)): ?>
                <tr><td colspan="7" class="text-center text-muted">هنوز نسخه‌ای ذخیره نشده — مقادیر پیش‌فرض اعمال است.</td></tr>
            <?php endif; ?>
            <?php foreach ($versions as $v): ?>
                <?php
                $levels = json_decode((string) ($v['levels'] ?? '[]'), true) ?: [];
                $sum = 0.0;
                foreach ($levels as $lv) {
                    if (!empty($lv['enabled'])) { $sum += (float) ($lv['percent'] ?? 0); }
                }
                $baseLabel = ['direct_reward' => 'پاداش مستقیم', 'fixed_base' => 'مبلغ ثابت', 'event_amount' => 'مبلغ رویداد'][$v['percent_base']] ?? $v['percent_base'];
                ?>
                <tr>
                    <td data-label="نسخه"><span class="badge badge-<?= (int) $v['id'] === $settings->versionId ? 'success' : 'muted' ?>"><?= fa_digits((int) $v['version']) ?><?= (int) $v['id'] === $settings->versionId ? ' (فعال)' : '' ?></span></td>
                    <td data-label="پاداش" class="num"><?= wtoman($v['direct_reward']) ?></td>
                    <td data-label="چندلایه"><?= !empty($v['multi_level_enabled']) ? 'فعال' : 'غیرفعال' ?></td>
                    <td data-label="لایه‌ها" class="num"><?= fa_digits((int) $v['max_levels']) ?></td>
                    <td data-label="مجموع" class="num"><?= fa_digits($sum) ?>٪</td>
                    <td data-label="مبنا"><?= e($baseLabel) ?></td>
                    <td class="text-xs" data-label="تاریخ"><?= fa_date($v['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('commission-form');
    if (!form) return;
    const base = <?= (int) ($settings->percentBase === 'fixed_base' ? $settings->fixedBaseAmount : $settings->directReward) ?>;
    const maxLevels = document.getElementById('max-levels');
    const totalEl = document.getElementById('total-percent');
    const rows = Array.from(form.querySelectorAll('[data-level-row]'));

    function recalc() {
        let total = 0;
        const limit = Number(maxLevels.value) || 8;
        rows.forEach(function (row, i) {
            const level = i + 1;
            const percentInput = row.querySelector('[data-level-percent]');
            const enabled = row.querySelector('[data-level-enabled]');
            const rawVal = (RB.enNum ? RB.enNum(percentInput.value) : String(percentInput.value)).replace(/٫/g, '.');
            const percent = parseFloat(rawVal.replace(/[^\d.]/g, '')) || 0;
            if (enabled.checked && level <= limit) total += percent;
            const approx = Math.round(base * percent / 100);
            const approxEl = row.querySelector('[data-approx]');
            if (approxEl) {
                approxEl.textContent = RB.faNum(approx.toLocaleString('en-US')) + ' تومان';
            }
            row.style.opacity = level <= limit ? '' : '.45';
            percentInput.disabled = !enabled.checked || level > limit;
        });
        totalEl.textContent = RB.faNum(total.toFixed(2).replace(/\.00$/, '')) + '٪';
        totalEl.style.color = total > 100 ? '#b91c1c' : '';
    }

    form.addEventListener('input', recalc);
    form.addEventListener('change', recalc);
    recalc();
})();
</script>

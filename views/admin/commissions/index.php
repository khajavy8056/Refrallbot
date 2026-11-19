<?php
/** @var \RB\Services\CommissionSettings $settings */
/** @var array<int,array> $versions */
/** @var array<string,string> $percentBases */
/** @var array<string,mixed> $stats */

$percentBases = $percentBases ?? \RB\Services\CommissionSettings::PERCENT_BASES;
$stats = $stats ?? [];
$versions = $versions ?? [];
$conditions = [
    'joined'   => 'عضویت در ربات یا مینی‌اپ (شروع)',
    'profile'  => 'تکمیل پروفایل (نام و نام خانوادگی)',
    'phone'    => 'ثبت شماره تماس',
    'channels' => 'عضویت در کانال‌های اجباری',
    'approved' => 'تأیید دستی مدیر',
];
$activeConditions = $settings->qualifyConditions ?: ['joined'];
$levelRows = [];
foreach ($settings->levels as $row) {
    $levelRows[(int) ($row['level'] ?? 0)] = $row;
}
$totalPercent = $settings->totalPercent();
$byLevel = [];
foreach (($stats['by_level'] ?? []) as $row) {
    $byLevel[(int) $row['level']] = $row;
}
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">💰 تنظیمات پورسانت و درآمد لایه‌ای</h1>
        <p class="text-muted text-sm mb-0">
            نسخه فعال: <strong><?= fa_digits($settings->version) ?></strong>
            · لایه ۰ (دعوت مستقیم) همیشه ثابت و تومانی است؛ لایه‌های ۱ تا ۸ (غیرمستقیم) به‌صورت ثابت تومانی یا درصدی قابل تنظیم‌اند.
        </p>
    </div>
    <div class="flex gap-1 wrap">
        <a href="<?= url('admin/reports/layers') ?>" class="btn btn-secondary btn-sm">📊 گزارش مالی لایه‌ها</a>
        <a href="<?= url('admin/invite-content') ?>" class="btn btn-outline btn-sm">🎨 محتوای دعوت و بنر</a>
    </div>
</div>

<div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(190px,1fr))">
    <div class="stat purple">
        <div class="stat-head"><div class="stat-icon">💎</div><div class="stat-label">کل پورسانت واریزشده</div></div>
        <div class="stat-value" style="font-size:1.1rem"><?= wtoman((int) $stats['total_commissions']) ?></div>
        <div class="stat-foot">در <?= fa_digits((int) $stats['events']) ?> رویداد واجد شرایط</div>
    </div>
    <div class="stat info">
        <div class="stat-head"><div class="stat-icon">🎯</div><div class="stat-label">لایه ۰ (دعوت مستقیم)</div></div>
        <div class="stat-value" style="font-size:1.1rem"><?= $settings->directEnabled ? wtoman($settings->directReward) : 'غیرفعال' ?></div>
        <div class="stat-foot">پرداخت‌شده: <?= wtoman((int) ($stats['direct_paid'] ?? 0)) ?></div>
    </div>
    <div class="stat <?= $settings->multiLevelEnabled ? 'success' : 'warning' ?>">
        <div class="stat-head"><div class="stat-icon">🕸</div><div class="stat-label">لایه‌های غیرمستقیم (۱ تا <?= fa_digits($settings->maxLevels) ?>)</div></div>
        <div class="stat-value" style="font-size:1.05rem"><?= $settings->multiLevelEnabled ? 'فعال' : 'غیرفعال' ?></div>
        <div class="stat-foot">پرداخت‌شده: <?= wtoman((int) ($stats['indirect_paid'] ?? 0)) ?></div>
    </div>
    <div class="stat success">
        <div class="stat-head"><div class="stat-icon">🎁</div><div class="stat-label">هدیه خوش‌آمد دعوت‌شده</div></div>
        <div class="stat-value" style="font-size:1.1rem"><?= $settings->inviteeEnabled ? wtoman($settings->inviteeReward) : 'غیرفعال' ?></div>
        <div class="stat-foot">پرداخت‌شده: <?= wtoman((int) ($stats['welcome_paid'] ?? 0)) ?></div>
    </div>
</div>

<form method="post" action="<?= url('admin/commissions') ?>" id="commission-form">
    <?= csrf_field() ?>
    <input type="hidden" name="direct_calc_type" value="fixed">

    <div class="grid-2 mb-3">
        <!-- کارت لایه ۰ و هدیه خوش‌آمد -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">🎯 لایه ۰ (دعوت مستقیم) و هدیه خوش‌آمدگویی</h2>
                <span class="card-subtitle">مبالغ ثابت و تومانی — پس از تکمیل شرایط واجدشرایط‌شدن واریز می‌شوند</span>
            </div>
            <div class="card-body">
                <div style="padding:12px;border:1px solid var(--border);border-radius:10px;margin-bottom:12px;background:var(--surface-2,rgba(99,102,241,.05))">
                    <div class="flex justify-between items-center mb-2 wrap gap-1">
                        <label class="checkbox">
                            <input type="checkbox" name="direct_enabled" id="direct-enabled" value="1" <?= $settings->directEnabled ? 'checked' : '' ?>>
                            <span class="checkbox-text font-bold">🎯 لایه ۰ — پاداش دعوت‌کننده مستقیم فعال باشد</span>
                        </label>
                        <span class="badge badge-info no-dot">ثابت (تومانی)</span>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">مبلغ ثابت پاداش لایه ۰ (تومان)</label>
                        <input type="text" name="direct_reward" id="direct-reward-input" class="form-control ltr" data-money inputmode="numeric"
                               value="<?= e(number_format($settings->directReward)) ?>" placeholder="مثلاً 10,000" required>
                        <div class="form-hint">اگر A کاربر B را دعوت کند، فقط این مبلغ ثابت به A واریز می‌شود (پورسانت لایه ۱ به معرفِ A تعلق می‌گیرد، نه دوباره به A).</div>
                    </div>
                </div>

                <div style="padding:12px;border:1px solid var(--border);border-radius:10px;margin-bottom:14px;background:var(--surface-2,rgba(16,185,129,.05))">
                    <div class="flex justify-between items-center mb-2 wrap gap-1">
                        <label class="checkbox">
                            <input type="checkbox" name="invitee_enabled" id="invitee-enabled" value="1" <?= $settings->inviteeEnabled ? 'checked' : '' ?>>
                            <span class="checkbox-text font-bold">🎁 هدیه خوش‌آمدگویی کاربر دعوت‌شده فعال باشد</span>
                        </label>
                        <span class="badge badge-success no-dot">ثابت (تومانی)</span>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label">مبلغ ثابت هدیه دعوت‌شده (تومان)</label>
                        <input type="text" name="invitee_reward" id="invitee-reward-input" class="form-control ltr" data-money inputmode="numeric"
                               value="<?= e(number_format($settings->inviteeReward)) ?>" placeholder="مثلاً 5,000">
                        <div class="form-hint">به کاربر جدیدی که با لینک دعوت عضو شده و شرایط را تکمیل کرده واریز می‌شود.</div>
                    </div>
                </div>

                <div class="alert alert-info mb-3" id="two-way-preview">
                    <span class="alert-icon">✨</span>
                    <div id="two-way-preview-text" class="text-sm">
                        در هر دعوت موفق: معرف مستقیم (لایه ۰) <b><?= $settings->directEnabled ? wtoman($settings->directReward) : '۰ تومان (غیرفعال)' ?></b>
                        و کاربر دعوت‌شده <b><?= $settings->inviteeEnabled ? wtoman($settings->inviteeReward) : '۰ تومان (غیرفعال)' ?></b> دریافت می‌کنند.
                    </div>
                </div>

                <div class="divider"></div>
                <div class="form-group">
                    <label class="form-label">📐 مبنای محاسبه برای لایه‌های «درصدی» (لایه‌های ۱ به بعد)</label>
                    <select name="percent_base" class="form-select" id="percent-base-select">
                        <?php foreach ($percentBases as $bk => $bl): ?>
                            <option value="<?= e($bk) ?>" <?= $settings->percentBase === $bk ? 'selected' : '' ?>><?= e($bl) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint">این مبنا فقط روی لایه‌هایی که نوع محاسبه آن‌ها «درصدی (٪)» انتخاب شده اعمال می‌شود؛ لایه‌های «مبلغ ثابت» دقیقاً همان مبلغ تومانی ثبت‌شده را می‌گیرند.</div>
                </div>
                <div class="form-group mb-0" id="fixed-base-wrap">
                    <label class="form-label">مبلغ پایه ثابت برای محاسبه درصدی (تومان)</label>
                    <input type="text" name="fixed_base_amount" id="fixed-base-input" class="form-control ltr" data-money inputmode="numeric"
                           value="<?= e(number_format($settings->fixedBaseAmount)) ?>" placeholder="مثلاً 20,000">
                    <div class="form-hint">وقتی مبنا روی «مبلغ پایه ثابت» باشد، درصد هر لایه در این عدد ضرب و به پایین گرد (تومان صحیح) می‌شود.</div>
                </div>
            </div>
        </div>

        <!-- کارت شرایط و سقف‌های ضدتقلب -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">🛡 شرایط پرداخت و سقف‌های کنترل مالی</h2>
                <span class="card-subtitle">اعمال یکسان روی تمام پیام‌رسان‌های فعال و متصل</span>
            </div>
            <div class="card-body">
                <?php
                $normalizedActive = array_map(static fn($c) => match ((string) $c) {
                    'contact' => 'phone',
                    'channel' => 'channels',
                    'profile_complete' => 'profile',
                    'manual_approval' => 'approved',
                    default => (string) $c,
                }, $activeConditions);
                ?>
                <?php foreach ($conditions as $key => $label): ?>
                    <label class="checkbox" style="display:flex;margin-bottom:8px">
                        <input type="checkbox" name="conditions[]" value="<?= e($key) ?>" <?= in_array($key, $normalizedActive, true) ? 'checked' : '' ?>>
                        <span class="checkbox-text"><?= e($label) ?></span>
                    </label>
                <?php endforeach; ?>
                <div class="form-hint">
                    تا پیش از تکمیل تمام شرایط بالا، هیچ پورسانتی (نه هدیه دعوت‌شده، نه لایه ۰ و نه لایه‌های ۱ تا ۸) واریز نمی‌شود. هر رویداد با کلید یکتای Idempotency فقط یک‌بار محاسبه می‌شود.
                </div>
                <div class="divider"></div>
                <div class="form-group">
                    <label class="form-label">سقف کل پرداخت در هر رویداد دعوت (تومان — ۰ = بدون سقف)</label>
                    <input type="text" name="max_payout_per_event" class="form-control ltr" data-money inputmode="numeric"
                           value="<?= e(number_format($settings->maxPayoutPerEvent)) ?>">
                    <div class="form-hint">مجموع پاداش لایه ۰ + لایه‌های غیرمستقیم در یک معرفی از این عدد تجاوز نمی‌کند.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">سقف پورسانت روزانه هر کاربر (تومان — ۰ = بدون سقف)</label>
                    <input type="text" name="daily_payout_cap" class="form-control ltr" data-money inputmode="numeric"
                           value="<?= e(number_format($settings->dailyPayoutCap)) ?>">
                    <div class="form-hint">اگر مجموع دریافتی امروز یک بالاسری از این سقف بگذرد، مابه‌التفاوت پرداخت نمی‌شود.</div>
                </div>
                <label class="checkbox">
                    <input type="checkbox" name="blocked_users_no_commission" value="1" <?= $settings->blockedUsersNoCommission ? 'checked' : '' ?>>
                    <span class="checkbox-text">کاربران مسدود پورسانت دریافت نکنند (بدون انتقال به لایه بالاتر)</span>
                </label>
            </div>
        </div>
    </div>

    <!-- جدول لایه‌های غیرمستقیم ۱ تا ۸ -->
    <div class="card mb-3">
        <div class="card-header">
            <div>
                <h2 class="card-title">🕸 پورسانت لایه‌های غیرمستقیم (لایه ۱ تا لایه ۸)</h2>
                <span class="card-subtitle">مثال زنجیره A → B → C → D: هنگام عضویت D، کاربر C پاداش لایه ۰ (مستقیم)، کاربر B پورسانت لایه ۱ و کاربر A پورسانت لایه ۲ را می‌گیرد.</span>
            </div>
        </div>
        <div class="card-body">
            <div class="form-row mb-3" style="align-items:center">
                <div class="form-group">
                    <label class="checkbox">
                        <input type="checkbox" name="multi_level_enabled" id="ml-enabled" value="1" <?= $settings->multiLevelEnabled ? 'checked' : '' ?>>
                        <span class="checkbox-text font-bold">فعال‌سازی پورسانت لایه‌های غیرمستقیم (لایه ۱ به بعد)</span>
                    </label>
                </div>
                <div class="form-group" style="max-width:220px">
                    <label class="form-label">حداکثر عمق لایه‌های غیرمستقیم</label>
                    <select name="max_levels" class="form-select" id="max-levels">
                        <?php for ($i = 1; $i <= 8; $i++): ?>
                            <option value="<?= $i ?>" <?= $settings->maxLevels === $i ? 'selected' : '' ?>>تا لایه <?= fa_digits($i) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-inline-start:auto;text-align:end">
                    <label class="form-label">جمع لایه‌های غیرمستقیم در هر معرفی</label>
                    <div class="font-bold" id="total-indirect-summary">—</div>
                </div>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>لایه</th>
                            <th>فعال</th>
                            <th>نوع محاسبه</th>
                            <th>مقدار (تومان یا ٪)</th>
                            <th>سقف هر رویداد (اختیاری)</th>
                            <th>خروجی هر معرفی</th>
                            <th>پرداخت‌شده تا امروز</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php for ($level = 1; $level <= 8; $level++): ?>
                        <?php
                        $row = $levelRows[$level] ?? ['enabled' => false, 'calc_type' => 'percent', 'amount' => 0, 'percent' => '0', 'max_amount' => 0];
                        $enabled = !empty($row['enabled']);
                        $calcType = in_array(($row['calc_type'] ?? 'percent'), ['fixed', 'percent'], true) ? (string) $row['calc_type'] : 'percent';
                        $fixedAmt = (int) ($row['amount'] ?? 0);
                        $percent = (string) ($row['percent'] ?? '0');
                        $maxAmt = (int) ($row['max_amount'] ?? 0);
                        $paid = (int) ($byLevel[$level]['s'] ?? 0);
                        $count = (int) ($byLevel[$level]['c'] ?? 0);
                        ?>
                        <tr data-level-row data-level="<?= $level ?>">
                            <td data-label="لایه">
                                <span class="badge badge-brand no-dot">لایه <?= fa_digits($level) ?></span>
                                <div class="text-xs text-muted">عمق <?= fa_digits($level + 1) ?></div>
                            </td>
                            <td data-label="فعال">
                                <label class="switch">
                                    <input type="checkbox" name="levels[<?= $level ?>][enabled]" value="1" <?= $enabled ? 'checked' : '' ?> data-level-enabled>
                                    <span class="switch-track"></span>
                                </label>
                            </td>
                            <td data-label="نوع محاسبه">
                                <select name="levels[<?= $level ?>][calc_type]" class="form-select" style="min-width:135px" data-level-type>
                                    <option value="fixed" <?= $calcType === 'fixed' ? 'selected' : '' ?>>💵 مبلغ ثابت (تومان)</option>
                                    <option value="percent" <?= $calcType === 'percent' ? 'selected' : '' ?>>📊 درصدی (٪)</option>
                                </select>
                            </td>
                            <td data-label="مقدار">
                                <div class="input-group <?= $calcType === 'fixed' ? '' : 'hidden' ?>" style="max-width:175px" data-fixed-wrap>
                                    <input type="text" class="form-control ltr" data-money inputmode="numeric"
                                           name="levels[<?= $level ?>][amount]" value="<?= e(number_format($fixedAmt)) ?>" placeholder="2,000" data-level-amount>
                                    <span class="btn btn-secondary" style="pointer-events:none;font-size:.75rem">تومان</span>
                                </div>
                                <div class="input-group <?= $calcType === 'percent' ? '' : 'hidden' ?>" style="max-width:150px" data-percent-wrap>
                                    <input type="text" class="form-control ltr" inputmode="decimal"
                                           name="levels[<?= $level ?>][percent]" value="<?= e($percent) ?>" placeholder="20" data-level-percent>
                                    <span class="btn btn-secondary" style="pointer-events:none">٪</span>
                                </div>
                            </td>
                            <td data-label="سقف لایه">
                                <input type="text" class="form-control ltr" data-money inputmode="numeric" style="max-width:140px"
                                       name="levels[<?= $level ?>][max_amount]" value="<?= $maxAmt > 0 ? e(number_format($maxAmt)) : '' ?>"
                                       placeholder="۰ = بدون سقف" data-level-cap>
                            </td>
                            <td data-label="خروجی هر معرفی" class="num font-bold" data-approx>—</td>
                            <td data-label="پرداخت‌شده" class="num text-xs">
                                <?= wtoman($paid) ?>
                                <div class="text-muted"><?= fa_digits($count) ?> بار</div>
                            </td>
                        </tr>
                    <?php endfor; ?>
                    </tbody>
                </table>
            </div>

            <div class="alert alert-info mt-2 mb-0">
                <span class="alert-icon">ℹ️</span>
                <div class="text-xs" style="line-height:1.9">
                    • می‌توانید برخی لایه‌ها را <b>مبلغ ثابت تومانی</b> و برخی دیگر را <b>درصدی</b> تنظیم کنید.<br>
                    • مجموع درصد لایه‌های «درصدیِ فعال» حداکثر ۱۰۰٪ است. لایه غیرفعال هیچ پورسانتی نمی‌گیرد و سهم آن به لایه بالاتر منتقل نمی‌شود.<br>
                    • هر تغییر با اسنپ‌شات نسخه‌بندی‌شده ذخیره می‌شود و گزارش‌ها و تراکنش‌های قبلی هرگز تغییر نمی‌کنند.
                </div>
            </div>
        </div>
        <div class="card-footer flex justify-between items-center wrap gap-2">
            <span class="text-xs text-muted">نسخه جدید بلافاصله پس از ذخیره روی معرفی‌های بعدی اعمال می‌شود.</span>
            <button class="btn" type="submit" data-confirm="تنظیمات پورسانت به‌صورت نسخه جدید ذخیره شود؟">💾 ذخیره نسخه جدید تنظیمات</button>
        </div>
    </div>
</form>

<!-- تاریخچه نسخه‌ها -->
<div class="card">
    <div class="card-header"><h2 class="card-title">📜 تاریخچه نسخه‌های تنظیمات پورسانت</h2></div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>نسخه</th>
                    <th>لایه ۰ (مستقیم)</th>
                    <th>هدیه دعوت‌شده</th>
                    <th>لایه‌های غیرمستقیم</th>
                    <th>ترکیب لایه‌های فعال</th>
                    <th>مبنای درصدی</th>
                    <th>تاریخ ثبت</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($versions)): ?>
                <tr><td colspan="7" class="text-center text-muted">هنوز نسخه‌ای ذخیره نشده — مقادیر پیش‌فرض اعمال است.</td></tr>
            <?php endif; ?>
            <?php foreach ($versions as $v): ?>
                <?php
                $vLevels = json_decode((string) ($v['levels'] ?? '[]'), true) ?: [];
                $chips = [];
                foreach ($vLevels as $lv) {
                    if (empty($lv['enabled'])) {
                        continue;
                    }
                    $ln = (int) ($lv['level'] ?? 0);
                    $ct = (string) ($lv['calc_type'] ?? 'percent');
                    $chips[] = 'L' . fa_digits($ln) . ': ' . ($ct === 'fixed'
                        ? wtoman((int) ($lv['amount'] ?? 0))
                        : (fa_digits((string) ($lv['percent'] ?? '0')) . '٪'));
                }
                $baseLabel = $percentBases[$v['percent_base']] ?? (string) $v['percent_base'];
                $vInvOn = !empty($v['invitee_enabled']) && (int) ($v['invitee_reward'] ?? 0) > 0;
                ?>
                <tr>
                    <td data-label="نسخه">
                        <span class="badge badge-<?= (int) $v['id'] === $settings->versionId ? 'success' : 'muted' ?>">
                            نسخه <?= fa_digits((int) $v['version']) ?><?= (int) $v['id'] === $settings->versionId ? ' (فعال)' : '' ?>
                        </span>
                    </td>
                    <td data-label="لایه ۰" class="num"><?= !empty($v['direct_enabled']) ? wtoman((int) $v['direct_reward']) : '<span class="text-muted">غیرفعال</span>' ?></td>
                    <td data-label="دعوت‌شده" class="num"><?= $vInvOn ? wtoman((int) $v['invitee_reward']) : '<span class="text-muted">غیرفعال</span>' ?></td>
                    <td data-label="چندلایه"><?= !empty($v['multi_level_enabled']) ? ('تا لایه ' . fa_digits((int) $v['max_levels'])) : '<span class="text-muted">غیرفعال</span>' ?></td>
                    <td data-label="ترکیب لایه‌ها" class="text-xs"><?= $chips !== [] ? e(implode(' · ', $chips)) : '—' ?></td>
                    <td data-label="مبنا" class="text-xs"><?= e($baseLabel) ?></td>
                    <td data-label="تاریخ" class="text-xs"><?= fa_date($v['created_at']) ?></td>
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
    const directEnabled = document.getElementById('direct-enabled');
    const directInput = document.getElementById('direct-reward-input');
    const inviteeEnabled = document.getElementById('invitee-enabled');
    const inviteeInput = document.getElementById('invitee-reward-input');
    const baseSelect = document.getElementById('percent-base-select');
    const fixedBaseInput = document.getElementById('fixed-base-input');
    const fixedBaseWrap = document.getElementById('fixed-base-wrap');
    const previewEl = document.getElementById('two-way-preview-text');
    const mlEnabled = document.getElementById('ml-enabled');
    const maxLevels = document.getElementById('max-levels');
    const summaryEl = document.getElementById('total-indirect-summary');
    const rows = Array.from(form.querySelectorAll('[data-level-row]'));

    function en(v) { return (window.RB && RB.enNum) ? RB.enNum(v) : String(v || ''); }
    function fa(v) { return (window.RB && RB.faNum) ? RB.faNum(v) : String(v || ''); }
    function parseMoney(el) {
        if (!el) return 0;
        return parseInt(en(el.value).replace(/[^\d]/g, ''), 10) || 0;
    }
    function fmtToman(n) { return fa((n || 0).toLocaleString('en-US')) + ' تومان'; }

    function recalc() {
        const dAmt = parseMoney(directInput);
        const iAmt = parseMoney(inviteeInput);
        const dOn = directEnabled && directEnabled.checked;
        const iOn = inviteeEnabled && inviteeEnabled.checked;
        const mlOn = mlEnabled && mlEnabled.checked;

        if (previewEl) {
            previewEl.innerHTML = 'در هر دعوت موفق: معرف مستقیم (لایه ۰) <b>' +
                (dOn && dAmt > 0 ? fmtToman(dAmt) : '۰ تومان (غیرفعال)') +
                '</b> و کاربر دعوت‌شده <b>' +
                (iOn && iAmt > 0 ? fmtToman(iAmt) : '۰ تومان (غیرفعال)') + '</b> دریافت می‌کنند.';
        }

        if (fixedBaseWrap && baseSelect) {
            fixedBaseWrap.style.opacity = baseSelect.value === 'fixed_base' ? '1' : '.6';
        }

        const base = (baseSelect && baseSelect.value === 'fixed_base') ? parseMoney(fixedBaseInput) : dAmt;
        const limit = Number(maxLevels.value) || 8;
        let totalToman = 0;
        let totalPct = 0;

        rows.forEach(function (row, idx) {
            const level = idx + 1;
            const inRange = level <= limit;
            const enBox = row.querySelector('[data-level-enabled]');
            const typeSel = row.querySelector('[data-level-type]');
            const fWrap = row.querySelector('[data-fixed-wrap]');
            const pWrap = row.querySelector('[data-percent-wrap]');
            const amtInp = row.querySelector('[data-level-amount]');
            const pctInp = row.querySelector('[data-level-percent]');
            const capInp = row.querySelector('[data-level-cap]');
            const approxEl = row.querySelector('[data-approx]');

            const isFixed = typeSel && typeSel.value === 'fixed';
            if (fWrap) fWrap.classList.toggle('hidden', !isFixed);
            if (pWrap) pWrap.classList.toggle('hidden', isFixed);

            const active = mlOn && inRange && enBox && enBox.checked;
            let out = 0;
            if (isFixed) {
                out = parseMoney(amtInp);
            } else {
                const p = parseFloat(en(pctInp ? pctInp.value : '0').replace(/٫/g, '.').replace(/[^\d.]/g, '')) || 0;
                if (active) totalPct += p;
                out = Math.floor(base * p / 100);
            }
            const cap = parseMoney(capInp);
            if (cap > 0 && out > cap) out = cap;

            if (active) totalToman += out;
            if (approxEl) {
                approxEl.textContent = active ? fmtToman(out) : 'غیرفعال';
                approxEl.style.opacity = active ? '1' : '.5';
            }
            row.style.opacity = (inRange && mlOn) ? '1' : '.45';
        });

        if (summaryEl) {
            if (!mlOn) {
                summaryEl.textContent = 'غیرفعال';
                summaryEl.style.color = '';
            } else {
                const pctNote = totalPct > 0 ? (' · جمع درصد: ' + fa(totalPct.toFixed(2).replace(/\.00$/, '')) + '٪') : '';
                summaryEl.textContent = fmtToman(totalToman) + pctNote;
                summaryEl.style.color = totalPct > 100 ? '#ef4444' : '';
            }
        }
    }

    form.addEventListener('input', recalc);
    form.addEventListener('change', recalc);
    recalc();
})();
</script>

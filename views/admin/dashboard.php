<?php
$statusLabel = [
    'connected' => ['متصل', 'success'],
    'error' => ['خطا در اتصال', 'danger'],
    'disconnected' => ['قطع', 'muted'],
][$overview['bot_status']] ?? ['نامشخص', 'muted'];

$ma = $memberAnalytics ?? [
    'labels' => $labels ?? [],
    'daily_total' => $growth ?? [],
    'daily_referred' => $referrals ?? [],
    'daily_direct' => [],
    'daily_qualified' => [],
    'cumulative' => [],
    'summary' => [
        'baseline' => 0, 'end_total' => $overview['total_users'] ?? 0,
        'period_new' => array_sum($growth ?? []), 'prev_period_new' => 0, 'growth_pct' => 0,
        'period_referred' => array_sum($referrals ?? []), 'period_direct' => 0, 'period_qualified' => 0,
        'daily_avg' => 0, 'peak_count' => 0, 'peak_label' => '—',
        'referral_share_pct' => 0, 'qualification_rate_pct' => 0, 'active_rate_pct' => 0,
    ],
];
$ms = $ma['summary'];
$comp = $memberComposition ?? [];
$netLevels = $networkLevels ?? [];
$uiMode = $botUiMode ?? \RB\Services\SettingsService::botUiMode();
$uiModeMeta = [
    'miniapp' => ['📱 حالت مینی‌اپ', 'brand'],
    'buttons' => ['⌨️ حالت دکمه‌ای', 'info'],
    'hybrid'  => ['✨ حالت ترکیبی', 'success'],
][$uiMode] ?? ['✨ حالت ترکیبی', 'success'];
$growthSign = $ms['growth_pct'] > 0 ? '+' : '';
$growthTone = $ms['growth_pct'] > 0 ? 'success' : ($ms['growth_pct'] < 0 ? 'danger' : 'muted');
?>
<div class="dash-hero mb-3">
    <div class="dash-hero-main">
        <div class="flex items-center gap-2 wrap mb-1">
            <h1 class="mb-0">داشبورد مدیریت و تحلیل رشد</h1>
            <span class="badge badge-<?= $growthTone ?> no-dot">
                <?= $growthSign . fa_digits((string) $ms['growth_pct']) ?>٪ رشد عضویت (<?= fa_digits($days) ?> روزه)
            </span>
        </div>
        <p class="text-muted text-sm mb-0">نمای زنده رشد اعضا، عملکرد شبکه زیرمجموعه‌گیری، کیف پول و وضعیت ربات تلگرام</p>
    </div>
    <div class="flex items-center gap-2 wrap">
        <a href="<?= url('admin/messengers') ?>" class="btn btn-sm" title="مدیریت پیام‌رسان‌ها">📡 پیام‌رسان‌ها</a>
        <a href="<?= url('admin/settings#ui-mode') ?>" class="btn btn-secondary btn-sm" title="تغییر سریع بین مینی‌اپ و حالت دکمه‌ای">
            <?= $uiModeMeta[0] ?> · تغییر
        </a>
        <div class="period-pills">
            <?php foreach ([7 => '۷ روز', 14 => '۱۴ روز', 30 => '۳۰ روز', 90 => '۹۰ روز'] as $d => $label): ?>
                <a href="?days=<?= $d ?>" class="period-pill <?= $days === $d ? 'active' : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if (!$overview['bot_connected']): ?>
    <div class="alert alert-warning">
        <span class="alert-icon">🤖</span>
        <div>
            ربات تلگرام متصل نیست. برای فعالسازی دریافت کاربران و ارسال پیام،
            <a href="<?= url('admin/telegram') ?>">توکن ربات را ثبت کنید</a>.
        </div>
    </div>
<?php elseif (!$overview['webhook_active']): ?>
    <div class="alert alert-warning">
        <span class="alert-icon">🔗</span>
        <div>
            ربات متصل است اما Webhook فعال نیست؛ ربات پیام‌ها را دریافت نمی‌کند.
            <a href="<?= url('admin/telegram') ?>">همین حالا تنظیم کنید</a>.
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($messengers) && is_array($messengers)): ?>
    <div class="card mb-3">
        <div class="card-body flex justify-between items-center wrap gap-2" style="padding:11px 16px">
            <div class="flex items-center gap-2 wrap">
                <strong class="text-sm">📡 وضعیت پیام‌رسان‌ها:</strong>
                <?php foreach ($messengers as $mp => $mi): ?>
                    <a href="<?= url('admin/messengers?tab=' . $mp) ?>" style="text-decoration:none" class="badge <?= e((string) ($mi['status_badge']['class'] ?? 'badge-muted')) ?>">
                        <?= e(($mi['icon'] ?? '') . ' ' . ($mi['name'] ?? $mp)) ?> · <?= fa_digits((int) ($mi['registered_users'] ?? 0)) ?> کاربر
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="flex gap-1 wrap">
                <a href="<?= url('admin/invite-content') ?>" class="btn btn-ghost btn-sm">🖼 بنرها و پیام دعوت</a>
                <a href="<?= url('admin/reports/layers') ?>" class="btn btn-ghost btn-sm">📊 گزارش لایه‌ها</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat">
        <div class="stat-head"><div class="stat-icon">👥</div><div class="stat-label">کل اعضای ربات</div></div>
        <div class="stat-value"><?= fa_digits(number_format($overview['total_users'])) ?></div>
        <div class="stat-foot">
            <span class="stat-chip positive">+<?= fa_digits(number_format($overview['new_today'])) ?> امروز</span>
            · <?= fa_digits(number_format($overview['new_week'])) ?> در ۷ روز
        </div>
    </div>
    <div class="stat success">
        <div class="stat-head"><div class="stat-icon">✅</div><div class="stat-label">کاربران فعال</div></div>
        <div class="stat-value"><?= fa_digits(number_format($overview['active_users'])) ?></div>
        <div class="stat-foot"><?= fa_digits((string) $ms['active_rate_pct']) ?>٪ کل اعضا · میانگین <?= fa_digits((string) $ms['daily_avg']) ?> عضو/روز</div>
    </div>
    <div class="stat info">
        <div class="stat-head"><div class="stat-icon">🕸</div><div class="stat-label">کل معرفی‌ها (رفرال)</div></div>
        <div class="stat-value"><?= fa_digits(number_format($overview['total_referrals'])) ?></div>
        <div class="stat-foot"><?= fa_digits(number_format($overview['qualified_referrals'])) ?> واجد شرایط (<?= fa_digits((string) $ms['referral_share_pct']) ?>٪ جذب رفرالی)</div>
    </div>
    <div class="stat purple">
        <div class="stat-head"><div class="stat-icon">💰</div><div class="stat-label">پورسانت پرداخت‌شده</div></div>
        <div class="stat-value"><?= fa_digits(number_format($overview['total_commissions'])) ?><small> تومان</small></div>
        <div class="stat-foot">مجموع درآمد کاربران: <?= wtoman($overview['total_earned']) ?></div>
    </div>
    <div class="stat danger">
        <div class="stat-head"><div class="stat-icon">🏦</div><div class="stat-label">در انتظار برداشت</div></div>
        <div class="stat-value"><?= fa_digits(number_format($overview['pending_withdrawals_amount'])) ?><small> تومان</small></div>
        <div class="stat-foot"><?= fa_digits($overview['pending_withdrawals_count']) ?> درخواست باز</div>
    </div>
    <div class="stat success">
        <div class="stat-head"><div class="stat-icon">💸</div><div class="stat-label">برداشت‌های انجام‌شده</div></div>
        <div class="stat-value"><?= fa_digits(number_format($overview['total_withdrawn'])) ?><small> تومان</small></div>
        <div class="stat-foot">موجودی کل کیف پول‌ها: <?= wtoman($overview['wallet_total_balance']) ?></div>
    </div>
    <div class="stat warning">
        <div class="stat-head"><div class="stat-icon">🛡</div><div class="stat-label">کاربران مشکوک</div></div>
        <div class="stat-value"><?= fa_digits(number_format($overview['suspicious_users'])) ?></div>
        <div class="stat-foot"><?= fa_digits($overview['pending_security_events']) ?> رویداد امنیتی باز</div>
    </div>
    <div class="stat <?= $overview['bot_connected'] ? 'success' : 'danger' ?>">
        <div class="stat-head"><div class="stat-icon">🤖</div><div class="stat-label">ربات و حالت نمایش</div></div>
        <div class="stat-value" style="font-size:1rem;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
            <span class="badge badge-<?= $statusLabel[1] ?>"><?= e($statusLabel[0]) ?></span>
            <a href="<?= url('admin/telegram#bot-mode') ?>" class="badge badge-<?= $uiModeMeta[1] ?> no-dot"><?= $uiModeMeta[0] ?></a>
        </div>
        <div class="stat-foot">
            <?= !empty($telegram['bot_username']) ? '@' . e($telegram['bot_username']) : 'توکن ثبت نشده' ?>
            · Webhook: <?= $overview['webhook_active'] ? 'فعال' : 'غیرفعال' ?>
        </div>
    </div>
</div>

<!-- ردیف ۱: نمودارهای اصلی رشد اعضا -->
<div class="grid-2 mb-3">
    <div class="card chart-card">
        <div class="card-header justify-between">
            <div>
                <h2 class="card-title">📈 رشد روزانه اعضای جدید</h2>
                <span class="card-subtitle">مقایسه کل عضویت، جذب از لینک دعوت و ورود مستقیم در <?= fa_digits($days) ?> روز اخیر</span>
            </div>
            <div class="chart-legend mt-0">
                <span><i class="legend-dot" style="background:#818cf8"></i> کل جدید</span>
                <span><i class="legend-dot" style="background:#10b981"></i> با لینک دعوت</span>
                <span><i class="legend-dot" style="background:#22d3ee"></i> مستقیم</span>
            </div>
        </div>
        <div class="card-body">
            <div class="chart-kpis">
                <div class="chart-kpi">
                    <span class="chart-kpi-label">عضویت در دوره</span>
                    <strong class="chart-kpi-val"><?= fa_digits(number_format($ms['period_new'])) ?> نفر</strong>
                </div>
                <div class="chart-kpi">
                    <span class="chart-kpi-label">جذب رفرالی</span>
                    <strong class="chart-kpi-val text-success"><?= fa_digits(number_format($ms['period_referred'])) ?> نفر</strong>
                </div>
                <div class="chart-kpi">
                    <span class="chart-kpi-label">اوج روزانه</span>
                    <strong class="chart-kpi-val"><?= fa_digits(number_format($ms['peak_count'])) ?> نفر <small>(<?= e($ms['peak_label']) ?>)</small></strong>
                </div>
            </div>
            <div class="chart-wrap" style="height:255px"><canvas id="chart-users"></canvas></div>
        </div>
    </div>

    <div class="card chart-card">
        <div class="card-header justify-between">
            <div>
                <h2 class="card-title">🚀 نمودار تجمعی کل اعضای ربات</h2>
                <span class="card-subtitle">روند افزایش جمعیت کل کاربران از <?= fa_digits(number_format($ms['baseline'])) ?> تا <?= fa_digits(number_format($ms['end_total'])) ?> نفر</span>
            </div>
            <span class="badge badge-<?= $growthTone ?> no-dot">
                <?= $growthSign . fa_digits((string) $ms['growth_pct']) ?>٪ نسبت به دوره قبل
            </span>
        </div>
        <div class="card-body">
            <div class="chart-kpis">
                <div class="chart-kpi">
                    <span class="chart-kpi-label">ابتدای بازه</span>
                    <strong class="chart-kpi-val"><?= fa_digits(number_format($ms['baseline'])) ?></strong>
                </div>
                <div class="chart-kpi">
                    <span class="chart-kpi-label">کل اعضای فعلی</span>
                    <strong class="chart-kpi-val text-accent"><?= fa_digits(number_format($ms['end_total'])) ?></strong>
                </div>
                <div class="chart-kpi">
                    <span class="chart-kpi-label">میانگین روزانه</span>
                    <strong class="chart-kpi-val"><?= fa_digits((string) $ms['daily_avg']) ?> نفر</strong>
                </div>
            </div>
            <div class="chart-wrap" style="height:255px"><canvas id="chart-cumulative"></canvas></div>
        </div>
    </div>
</div>

<!-- ردیف ۲: ترکیب اعضا + مقایسه ستونی دعوت‌ها و واجد شرایط شدن -->
<div class="grid-2 mb-3">
    <div class="card chart-card">
        <div class="card-header justify-between">
            <div>
                <h2 class="card-title">🎯 ترکیب اعضا و قیف تبدیل رفرال</h2>
                <span class="card-subtitle">نسبت اعضای دعوت‌شده به مستقیم و نرخ تأیید نهایی</span>
            </div>
        </div>
        <div class="card-body">
            <div class="donut-split">
                <div class="donut-box">
                    <canvas id="chart-composition"></canvas>
                </div>
                <div class="donut-meta">
                    <?php foreach ($comp as $seg): ?>
                        <div class="donut-legend-row">
                            <span class="donut-legend-name">
                                <i class="legend-dot" style="background:<?= e($seg['color']) ?>"></i>
                                <?= e($seg['label']) ?>
                            </span>
                            <strong class="num"><?= fa_digits(number_format((int) $seg['value'])) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="funnel-bars mt-3">
                <div class="funnel-row">
                    <div class="flex justify-between text-xs mb-1"><span>سهم جذب با لینک دعوت</span><strong><?= fa_digits((string) $ms['referral_share_pct']) ?>٪</strong></div>
                    <div class="meter"><span style="width:<?= (int) $ms['referral_share_pct'] ?>%;background:linear-gradient(90deg,#6366f1,#22d3ee)"></span></div>
                </div>
                <div class="funnel-row">
                    <div class="flex justify-between text-xs mb-1"><span>نرخ واجد شرایط شدن دعوت‌ها</span><strong><?= fa_digits((string) $ms['qualification_rate_pct']) ?>٪</strong></div>
                    <div class="meter"><span style="width:<?= (int) $ms['qualification_rate_pct'] ?>%;background:linear-gradient(90deg,#10b981,#34d399)"></span></div>
                </div>
                <div class="funnel-row">
                    <div class="flex justify-between text-xs mb-1"><span>نرخ سلامت و فعال‌بودن کاربران</span><strong><?= fa_digits((string) $ms['active_rate_pct']) ?>٪</strong></div>
                    <div class="meter"><span style="width:<?= (int) $ms['active_rate_pct'] ?>%;background:linear-gradient(90deg,#8b5cf6,#ec4899)"></span></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card chart-card">
        <div class="card-header justify-between">
            <div>
                <h2 class="card-title">📊 دعوت‌های موفق و تأییدشده روزانه</h2>
                <span class="card-subtitle">تعداد اعضای جذب‌شده با لینک دعوت در هر روز (<?= fa_digits($days) ?> روز اخیر)</span>
            </div>
            <span class="badge badge-info no-dot"><?= fa_digits(number_format($ms['period_qualified'])) ?> تأیید در بازه</span>
        </div>
        <div class="card-body">
            <div class="chart-wrap" style="height:210px"><canvas id="chart-daily-refs"></canvas></div>
            <?php if (!empty($netLevels)): ?>
                <div class="divider" style="margin:14px 0 10px"></div>
                <div class="text-xs font-bold mb-2 text-muted">گسترش شبکه در سطوح زیرمجموعه</div>
                <div class="level-bars-compact">
                    <?php
                    $maxLvlCount = max(1, ...array_map(static fn($r) => (int) $r['c'], $netLevels));
                    foreach (array_slice($netLevels, 0, 5) as $nl):
                        $pct = max(4, (int) round(((int) $nl['c'] / $maxLvlCount) * 100));
                    ?>
                        <div class="level-bar-item">
                            <span class="level-bar-lbl">سطح <?= fa_digits((int) $nl['level']) ?></span>
                            <div class="meter"><span style="width:<?= $pct ?>%"></span></div>
                            <strong class="level-bar-num"><?= fa_digits(number_format((int) $nl['c'])) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ردیف ۳: نمودارهای مالی (پورسانت و برداشت) -->
<div class="grid-2 mb-3">
    <div class="card chart-card">
        <div class="card-header justify-between">
            <div>
                <h2 class="card-title">💰 روند پرداخت پورسانت (تومان)</h2>
                <span class="card-subtitle">مجموع پاداش مستقیم و پورسانت لایه‌ای روزانه</span>
            </div>
            <a href="<?= url('admin/commissions') ?>" class="btn btn-ghost btn-sm">تنظیمات پورسانت ←</a>
        </div>
        <div class="card-body">
            <div class="chart-wrap" style="height:230px"><canvas id="chart-referrals"></canvas></div>
        </div>
    </div>
    <div class="card chart-card">
        <div class="card-header justify-between">
            <div>
                <h2 class="card-title">🏦 درخواست‌های برداشت (تومان)</h2>
                <span class="card-subtitle">حجم روزانه درخواست‌های ثبت‌شده</span>
            </div>
            <a href="<?= url('admin/withdrawals') ?>" class="btn btn-ghost btn-sm">مشاهده همه ←</a>
        </div>
        <div class="card-body">
            <div class="chart-wrap" style="height:230px"><canvas id="chart-withdrawals"></canvas></div>
        </div>
    </div>
</div>

<!-- ردیف ۴: توزیع پورسانت سطوح + برترین دعوت‌کنندگان -->
<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header justify-between">
            <h2 class="card-title">🏆 برترین دعوت‌کنندگان</h2>
            <a href="<?= url('admin/users') ?>" class="btn btn-ghost btn-sm">همه کاربران ←</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>کاربر</th><th>معرفی مستقیم</th><th>درآمد کل</th></tr></thead>
                <tbody>
                <?php if (empty($topUsers)): ?>
                    <tr><td colspan="3"><div class="empty"><div class="empty-text">کاربری ثبت نشده است.</div></div></td></tr>
                <?php endif; ?>
                <?php foreach ($topUsers as $index => $u): ?>
                    <tr>
                        <td data-label="کاربر">
                            <a href="<?= url('admin/users/' . (int) $u['id']) ?>" class="user-cell">
                                <div class="avatar avatar-sm"><?= fa_digits($index + 1) ?></div>
                                <div>
                                    <div class="user-cell-name"><?= e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'کاربر') ?></div>
                                    <div class="user-cell-meta"><?= $u['username'] ? '@' . e($u['username']) : '—' ?></div>
                                </div>
                            </a>
                        </td>
                        <td data-label="معرفی مستقیم" class="num"><span class="badge badge-brand no-dot"><?= fa_digits((int) $u['direct_ref_count']) ?> نفر</span></td>
                        <td data-label="درآمد کل"><?= wtoman((int) $u['total_earned']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">📐 توزیع پورسانت بر اساس سطح</h2></div>
        <div class="card-body">
            <?php if (empty($commissionLevels)): ?>
                <div class="empty" style="padding:32px 16px"><div class="empty-icon">📊</div><div class="empty-text">هنوز پورسانتی پرداخت نشده است.</div></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>سطح</th><th>تعداد تراکنش</th><th>مجموع مبلغ</th></tr></thead>
                        <tbody>
                        <?php foreach ($commissionLevels as $level): ?>
                            <tr>
                                <td data-label="سطح">
                                    <span class="badge badge-<?= (int) $level['level'] === 0 ? 'success' : 'purple' ?> no-dot">
                                        <?= (int) $level['level'] === 0 ? 'پاداش مستقیم' : 'لایه ' . fa_digits((int) $level['level']) ?>
                                    </span>
                                </td>
                                <td data-label="تعداد" class="num"><?= fa_digits(number_format((int) $level['c'])) ?></td>
                                <td data-label="مجموع"><?= wtoman((int) $level['s']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ردیف ۵: آخرین اعضای جذب‌شده + آخرین فعالیت مدیران -->
<div class="grid-2">
    <div class="card">
        <div class="card-header justify-between">
            <h2 class="card-title">👤 آخرین اعضای جذب‌شده</h2>
            <a href="<?= url('admin/users') ?>" class="btn btn-ghost btn-sm">مدیریت کاربران ←</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>کاربر</th><th>نحوه ورود</th><th>تاریخ عضویت</th><th>وضعیت</th></tr></thead>
                <tbody>
                <?php if (empty($recentUsers)): ?>
                    <tr><td colspan="4"><div class="empty"><div class="empty-icon">👤</div><div class="empty-text">هنوز کاربری ثبت نشده است.</div></div></td></tr>
                <?php endif; ?>
                <?php foreach ($recentUsers as $u): ?>
                    <tr>
                        <td data-label="کاربر">
                            <a href="<?= url('admin/users/' . (int) $u['id']) ?>" class="user-cell">
                                <div class="avatar avatar-sm"><?= e(mb_substr($u['first_name'] ?: '؟', 0, 1)) ?></div>
                                <div>
                                    <div class="user-cell-name"><?= e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'کاربر') ?></div>
                                    <div class="user-cell-meta"><?= $u['username'] ? '@' . e($u['username']) : fa_digits((string) $u['telegram_id']) ?></div>
                                </div>
                            </a>
                        </td>
                        <td data-label="نحوه ورود">
                            <?php if (!empty($u['referred_by'])): ?>
                                <span class="badge badge-info no-dot">🔗 دعوت‌شده</span>
                            <?php else: ?>
                                <span class="badge badge-muted no-dot">مستقیم</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="تاریخ" class="text-xs"><?= fa_date($u['joined_at']) ?></td>
                        <td data-label="وضعیت">
                            <span class="badge badge-<?= $u['status'] === 'active' ? 'success' : ($u['status'] === 'blocked' ? 'danger' : 'warning') ?>">
                                <?= $u['status'] === 'active' ? 'فعال' : ($u['status'] === 'blocked' ? 'مسدود' : 'مشکوک') ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header justify-between">
            <h2 class="card-title">📋 آخرین فعالیت مدیران</h2>
            <a href="<?= url('admin/logs') ?>" class="btn btn-ghost btn-sm">گزارش کامل ←</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <tbody>
                <?php if (empty($activity)): ?>
                    <tr><td><div class="empty"><div class="empty-text">فعالیتی ثبت نشده است.</div></div></td></tr>
                <?php endif; ?>
                <?php foreach ($activity as $log): ?>
                    <tr>
                        <td data-label="فعالیت">
                            <div style="font-weight:600;font-size:.83rem"><?= e(RB\Services\AuditService::label((string) $log['action'])) ?></div>
                            <div class="text-xs text-muted"><?= e($log['admin_name'] ?? 'سیستم') ?> · <?= fa_date($log['created_at']) ?></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
window.addEventListener('load', function () {
    const labels = <?= json_encode($ma['labels'], JSON_UNESCAPED_UNICODE) ?>;

    RB.lineChart(document.getElementById('chart-users'), {
        labels: labels,
        unit: 'نفر',
        series: [
            { name: 'کل اعضای جدید', data: <?= json_encode($ma['daily_total']) ?>, color: '#818cf8', fill: true },
            { name: 'با لینک دعوت', data: <?= json_encode($ma['daily_referred']) ?>, color: '#10b981', fill: false },
            { name: 'مستقیم', data: <?= json_encode($ma['daily_direct']) ?>, color: '#22d3ee', fill: false, dashed: true }
        ]
    });

    RB.lineChart(document.getElementById('chart-cumulative'), {
        labels: labels,
        unit: 'نفر',
        series: [
            { name: 'کل اعضا', data: <?= json_encode($ma['cumulative']) ?>, color: '#06b6d4', fill: true }
        ]
    });

    RB.donutChart(document.getElementById('chart-composition'), {
        data: <?= json_encode(array_map(static fn($c) => ['label' => $c['label'], 'value' => $c['value']], $comp), JSON_UNESCAPED_UNICODE) ?>,
        colors: <?= json_encode(array_column($comp, 'color')) ?>,
        centerValue: '<?= fa_digits(number_format((int) $overview['total_users'])) ?>',
        centerLabel: 'کل اعضا'
    });

    RB.barChart(document.getElementById('chart-daily-refs'), {
        labels: labels,
        data: <?= json_encode($ma['daily_referred']) ?>,
        name: 'دعوت جدید',
        unit: 'نفر',
        color: '#6366f1'
    });

    RB.lineChart(document.getElementById('chart-referrals'), {
        labels: labels,
        unit: 'تومان',
        series: [
            { name: 'پورسانت پرداختی', data: <?= json_encode($payouts) ?>, color: '#10b981', fill: true }
        ]
    });

    RB.barChart(document.getElementById('chart-withdrawals'), {
        labels: labels,
        data: <?= json_encode($withdrawalSeries) ?>,
        name: 'درخواست برداشت',
        unit: 'تومان',
        color: '#f59e0b'
    });
});
</script>

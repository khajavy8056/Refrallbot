<?php
$name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'کاربر';
$statusMap = ['active' => ['فعال', 'success'], 'blocked' => ['مسدود', 'danger'], 'suspicious' => ['مشکوک', 'warning']];
$st = $statusMap[$user['status']] ?? ['نامشخص', 'muted'];
$verifyMap = ['pending' => ['در انتظار تأیید', 'warning'], 'verified' => ['تأییدشده', 'success'], 'rejected' => ['ردشده', 'danger']];
$vf = $verifyMap[$user['verify_status']] ?? ['نامشخص', 'muted'];
$isSuper = (($currentAdmin['role_slug'] ?? '') === 'superadmin');
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div class="flex items-center gap-3">
        <a href="<?= url('admin/users') ?>" class="btn btn-ghost btn-icon" title="بازگشت">→</a>
        <div class="avatar avatar-lg"><?= e(mb_substr($user['first_name'] ?: 'ک', 0, 1)) ?></div>
        <div>
            <h1 class="mb-0" style="font-size:1.25rem"><?= e($name) ?></h1>
            <div class="text-sm text-muted">
                <?= $user['username'] ? '@' . e($user['username']) . ' · ' : '' ?>
                شناسه: <span class="mono"><?= fa_digits((string) $user['telegram_id']) ?></span>
                · کد دعوت: <span class="mono"><?= e($user['referral_code']) ?></span>
            </div>
        </div>
    </div>
    <div class="flex gap-2 wrap">
        <span class="badge badge-<?= $st[1] ?>"><?= e($st[0]) ?></span>
        <span class="badge badge-<?= $vf[1] ?>"><?= e($vf[0]) ?></span>
        <a href="<?= url('admin/network/' . (int) $user['id']) ?>" class="btn btn-secondary btn-sm">🕸 مشاهده شبکه</a>
    </div>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(185px,1fr))">
    <div class="stat"><div class="stat-head"><div class="stat-icon">👛</div><div class="stat-label">موجودی کیف پول</div></div><div class="stat-value"><?= fa_digits(number_format($walletSummary['balance'])) ?><small> تومان</small></div><div class="stat-foot">در انتظار: <?= wtoman($walletSummary['pending']) ?></div></div>
    <div class="stat success"><div class="stat-head"><div class="stat-icon">💰</div><div class="stat-label">درآمد کل</div></div><div class="stat-value"><?= fa_digits(number_format($walletSummary['total_earned'])) ?><small> تومان</small></div><div class="stat-foot">معرفی <?= wtoman($walletSummary['direct_earned']) ?><?= !empty($walletSummary['welcome_earned']) ? ' · خوش‌آمد ' . wtoman($walletSummary['welcome_earned']) : '' ?></div></div>
    <div class="stat info"><div class="stat-head"><div class="stat-icon">🕸</div><div class="stat-label">زیرمجموعه</div></div><div class="stat-value"><?= fa_digits($user['direct_ref_count']) ?><small> مستقیم</small></div><div class="stat-foot">کل شبکه: <?= fa_digits($user['total_ref_count']) ?></div></div>
    <div class="stat warning"><div class="stat-head"><div class="stat-icon">💸</div><div class="stat-label">برداشت‌ها</div></div><div class="stat-value"><?= fa_digits(number_format($walletSummary['total_withdrawn'])) ?><small> تومان</small></div><div class="stat-foot"><?= fa_digits($walletSummary['withdrawal_count']) ?> درخواست</div></div>
</div>

<?php if (!$reconcile['match']): ?>
    <div class="alert alert-warning">
        <span class="alert-icon">⚖️</span>
        <div>
            <strong>عدم تطبیق مالی!</strong> مانده دفتر مالی (<?= wtoman($reconcile['ledger']) ?>) با موجودی کیف پول (<?= wtoman($reconcile['wallet']) ?>)
            و موجودی کاربر (<?= wtoman($reconcile['user']) ?>) برابر نیست. لطفاً بررسی کنید.
        </div>
    </div>
<?php endif; ?>

<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header"><h2 class="card-title">اطلاعات حساب</h2></div>
        <div class="table-wrap">
            <table class="table">
                <tbody>
                <tr><td class="text-muted" style="width:45%">پیام‌رسان‌های متصل</td><td>
                    <?php if (!empty($messengerAccounts)): ?>
                        <div class="flex gap-1 wrap">
                            <?php foreach ($messengerAccounts as $ma): ?>
                                <span class="badge badge-<?= !empty($ma['is_primary']) ? 'brand' : 'info' ?> no-dot">
                                    <?= e(\RB\Messengers\MessengerCapabilities::label((string) $ma['platform'])) ?>
                                    <span class="ltr">(<?= e((string) $ma['external_user_id']) ?>)</span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <span class="badge badge-brand no-dot"><?= e(\RB\Messengers\MessengerCapabilities::label((string) ($user['entry_platform'] ?? 'telegram'))) ?></span>
                    <?php endif; ?>
                </td></tr>
                <tr><td class="text-muted">شماره تماس</td><td><?= $user['phone'] ? e($user['phone']) : '<span class="text-muted">ثبت نشده</span>' ?></td></tr>
                <tr><td class="text-muted">تاریخ عضویت</td><td><?= fa_date($user['joined_at']) ?></td></tr>
                <tr><td class="text-muted">آخرین فعالیت</td><td><?= fa_date($user['last_activity_at']) ?></td></tr>
                <tr><td class="text-muted">تاریخ واجد شرایط شدن</td><td><?= fa_date($user['qualified_at']) ?></td></tr>
                <tr><td class="text-muted">معرف مستقیم</td><td>
                    <?php if ($referrer): ?>
                        <a href="<?= url('admin/users/' . (int) $referrer['id']) ?>"><?= e(trim(($referrer['first_name'] ?? '') . ' ' . ($referrer['last_name'] ?? '')) ?: 'کاربر') ?></a>
                        <span class="text-xs text-muted">(<?= e($referrer['referral_code']) ?>)</span>
                    <?php else: ?><span class="text-muted">بدون معرف</span><?php endif; ?>
                </td></tr>
                <tr><td class="text-muted">تاریخ ثبت معرف</td><td><?= fa_date($user['referred_at']) ?></td></tr>
                <tr><td class="text-muted">IP ثبت‌نام</td><td class="mono text-sm"><?= e($user['signup_ip'] ?? '—') ?></td></tr>
                <tr><td class="text-muted">IP آخرین بازدید</td><td class="mono text-sm"><?= e($user['last_ip'] ?? '—') ?></td></tr>
                <tr><td class="text-muted">امتیاز ریسک</td><td>
                    <span class="badge badge-<?= (int) $user['risk_score'] >= 45 ? 'danger' : ((int) $user['risk_score'] > 0 ? 'warning' : 'success') ?>">
                        <?= fa_digits((int) $user['risk_score']) ?>
                    </span>
                </td></tr>
                <tr><td class="text-muted">عضویت کانال‌ها تأیید شده</td><td><?= $user['channels_verified'] ? '✅ بله' : '—' ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" id="status">
        <div class="card-header"><h2 class="card-title">عملیات مدیریتی</h2></div>
        <div class="card-body">
            <?php if (($user['verify_status'] ?? 'pending') !== 'verified'): ?>
                <form method="post" action="<?= url('admin/users/' . (int) $user['id'] . '/approve') ?>" class="mb-3">
                    <?= csrf_field() ?>
                    <button class="btn btn-success btn-sm" type="submit" data-confirm="عضویت این کاربر تأیید شود؟ در صورت تکمیل سایر شرایط، پاداش دعوت به معرف و کاربر واریز خواهد شد.">
                        ✅ تأیید عضویت و بررسی پاداش دعوت
                    </button>
                </form>
                <hr class="divider">
            <?php endif; ?>

            <form method="post" action="<?= url('admin/users/' . (int) $user['id'] . '/status') ?>" class="mb-3">
                <?= csrf_field() ?>
                <div class="form-label">تغییر وضعیت حساب</div>
                <div class="input-group mb-2">
                    <select name="status" class="form-select">
                        <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>فعال</option>
                        <option value="blocked" <?= $user['status'] === 'blocked' ? 'selected' : '' ?>>مسدود</option>
                        <option value="suspicious" <?= $user['status'] === 'suspicious' ? 'selected' : '' ?>>مشکوک</option>
                    </select>
                    <button class="btn" type="submit">ثبت</button>
                </div>
                <input type="text" name="reason" class="form-control" placeholder="دلیل تغییر (اختیاری)" style="margin-bottom:8px">
                <div class="form-hint mb-0">هر تغییر وضعیت در گزارش فعالیت مدیران ثبت می‌شود.</div>
            </form>

            <hr class="divider">

            <?php if ($isSuper): ?>
            <form method="post" action="<?= url('admin/users/' . (int) $user['id'] . '/wallet') ?>">
                <?= csrf_field() ?>
                <div class="form-label">اصلاح مالی (با ثبت سابقه کامل)</div>
                <div class="form-row mb-2">
                    <input type="text" name="amount" class="form-control ltr" placeholder="مبلغ به تومان" data-money required>
                    <select name="type" class="form-select">
                        <option value="credit">واریز به کیف پول</option>
                        <option value="debit">برداشت از کیف پول</option>
                    </select>
                </div>
                <input type="text" name="reason" class="form-control mb-2" placeholder="دلیل اصلاح (الزامی)" required>
                <button class="btn btn-warning" type="submit" data-confirm="این اصلاح مالی انجام شود؟ سابقه آن حذف نمی‌شود.">ثبت اصلاح مالی</button>
            </form>
            <?php else: ?>
                <div class="alert alert-info mb-0"><span class="alert-icon">ℹ️</span><div>اصلاح مالی و تغییر معرف فقط توسط «مدیر کل» قابل انجام است.</div></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($isSuper): ?>
    <div class="card mb-3">
        <div class="card-header">
            <h2 class="card-title">تغییر معرف</h2>
            <span class="card-subtitle">عملیات حساس — سابقه کامل ثبت می‌شود</span>
        </div>
        <div class="card-body">
            <form method="post" action="<?= url('admin/users/' . (int) $user['id'] . '/referrer') ?>">
                <?= csrf_field() ?>
                <div class="form-row mb-2">
                    <div>
                        <label class="form-label">معرف جدید</label>
                        <input type="text" name="referrer" class="form-control ltr" placeholder="شناسه تلگرام / کد دعوت / نام کاربری">
                        <div class="form-hint">برای حذف معرف، این فیلد را خالی بگذارید.</div>
                    </div>
                    <div>
                        <label class="form-label">دلیل تغییر <span class="req">*</span></label>
                        <input type="text" name="reason" class="form-control" required placeholder="دلیل تغییر معرف">
                    </div>
                </div>
                <button class="btn btn-danger" data-confirm="تغییر معرف باعث بازسازی زنجیره شبکه این کاربر و زیرمجموعه‌هایش می‌شود. ادامه می‌دهید؟">
                    اعمال تغییر معرف
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header">
        <h2 class="card-title">تراکنش‌های مالی</h2>
        <span class="card-subtitle"><?= fa_digits($txTotal) ?> تراکنش</span>
    </div>
    <?php if (empty($transactions)): ?>
        <div class="empty"><div class="empty-icon">🧾</div><div class="empty-text">تراکنشی ثبت نشده است.</div></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>کد</th><th>نوع</th><th>مبلغ</th><th>وضعیت</th><th>توضیح</th><th>تاریخ</th></tr></thead>
                <tbody>
                <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td data-label="کد" class="mono text-xs"><?= e($tx['tx_code']) ?></td>
                        <td data-label="نوع">
                            <span class="badge badge-<?= RB\Bot\TransactionLabels::typeClass((string) $tx['type']) ?>">
                                <?= e(RB\Bot\TransactionLabels::label((string) $tx['type'])) ?>
                            </span>
                        </td>
                        <td data-label="مبلغ" class="<?= $tx['direction'] === 'credit' ? 'text-success' : 'text-danger' ?> font-bold">
                            <?= $tx['direction'] === 'credit' ? '+' : '−' ?><?= wtoman((int) $tx['amount']) ?>
                        </td>
                        <td data-label="وضعیت">
                            <span class="badge badge-<?= RB\Bot\TransactionLabels::statusClass((string) $tx['status']) ?>">
                                <?= e(RB\Bot\TransactionLabels::status((string) $tx['status'])) ?>
                            </span>
                        </td>
                        <td data-label="توضیح" class="text-sm"><?= e($tx['description'] ?? '—') ?></td>
                        <td data-label="تاریخ" class="text-sm text-muted"><?= fa_date($tx['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header"><h2 class="card-title">زیرمجموعه‌های مستقیم</h2><span class="card-subtitle"><?= fa_digits(count($directRefs)) ?> نفر</span></div>
        <?php if (empty($directRefs)): ?>
            <div class="empty"><div class="empty-text">زیرمجموعه مستقیمی ندارد.</div></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>کاربر</th><th>عضویت</th><th>درآمد ایجادشده</th></tr></thead>
                    <tbody>
                    <?php foreach ($directRefs as $ref): ?>
                        <tr>
                            <td data-label="کاربر"><a href="<?= url('admin/users/' . (int) $ref['id']) ?>"><?= e(trim(($ref['first_name'] ?? '') . ' ' . ($ref['last_name'] ?? '')) ?: 'کاربر') ?></a></td>
                            <td data-label="عضویت" class="text-sm text-muted"><?= fa_date($ref['joined_at'], false) ?></td>
                            <td data-label="درآمد"><?= wtoman((int) $ref['total_earned']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">درخواست‌های برداشت</h2></div>
        <?php if (empty($withdrawals)): ?>
            <div class="empty"><div class="empty-text">درخواست برداشتی ندارد.</div></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>کد</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
                    <tbody>
                    <?php foreach ($withdrawals as $w): ?>
                        <tr>
                            <td data-label="کد"><a href="<?= url('admin/withdrawals/' . (int) $w['id']) ?>" class="mono text-xs"><?= e($w['request_code']) ?></a></td>
                            <td data-label="مبلغ"><?= wtoman((int) $w['amount']) ?></td>
                            <td data-label="وضعیت">
                                <span class="badge badge-<?= in_array($w['status'], ['paid'], true) ? 'success' : (in_array($w['status'], ['rejected', 'cancelled'], true) ? 'danger' : 'warning') ?>">
                                    <?= e(RB\Services\WithdrawalService::statusLabel((string) $w['status'])) ?>
                                </span>
                            </td>
                            <td data-label="تاریخ" class="text-sm text-muted"><?= fa_date($w['created_at'], false) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2 class="card-title">پورسانت‌های دریافتی</h2></div>
        <?php if (empty($commissions)): ?>
            <div class="empty"><div class="empty-text">پورسانتی دریافت نکرده است.</div></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>منبع</th><th>سطح</th><th>مبلغ</th><th>وضعیت</th></tr></thead>
                    <tbody>
                    <?php foreach ($commissions as $c): ?>
                        <tr>
                            <td data-label="منبع" class="text-sm"><?= e(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: 'کاربر') ?></td>
                            <td data-label="سطح"><?= ($c['type'] ?? '') === 'welcome' ? '🎁 خوش‌آمدگویی' : ((int) $c['level'] === 0 ? '🎯 معرفی مستقیم' : 'لایه ' . fa_digits((int) $c['level'])) ?></td>
                            <td data-label="مبلغ" class="font-bold"><?= wtoman((int) $c['amount']) ?></td>
                            <td data-label="وضعیت">
                                <span class="badge badge-<?= $c['status'] === 'approved' ? 'success' : 'danger' ?>">
                                    <?= $c['status'] === 'approved' ? 'پرداخت‌شده' : 'برگشت‌خورده' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">رویدادهای امنیتی و مشکوک</h2></div>
        <?php if (empty($security)): ?>
            <div class="empty"><div class="empty-icon">🛡</div><div class="empty-text">رویداد امنیتی ثبت نشده است.</div></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>نوع</th><th>شدت</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
                    <tbody>
                    <?php foreach ($security as $s): ?>
                        <tr>
                            <td data-label="نوع" class="text-sm"><?= e(\RB\Controllers\Admin\SecurityController::typeLabel((string) $s['type'])) ?></td>
                            <td data-label="شدت">
                                <span class="badge badge-<?= $s['severity'] === 'high' ? 'danger' : ($s['severity'] === 'medium' ? 'warning' : 'muted') ?>">
                                    <?= $s['severity'] === 'high' ? 'بالا' : ($s['severity'] === 'medium' ? 'متوسط' : 'کم') ?>
                                </span>
                            </td>
                            <td data-label="وضعیت" class="text-sm"><?= e(\RB\Controllers\Admin\SecurityController::statusLabel((string) $s['status'])) ?></td>
                            <td data-label="تاریخ" class="text-sm text-muted"><?= fa_date($s['created_at'], false) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

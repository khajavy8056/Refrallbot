<?php
$status = (string) $request['status'];
$badges = ['new' => 'info', 'reviewing' => 'warning', 'approved' => 'brand', 'paying' => 'warning',
    'paid' => 'success', 'rejected' => 'danger', 'cancelled' => 'muted'];
$canApprove = in_array($status, ['new', 'reviewing'], true);
$canReject = in_array($status, ['new', 'reviewing', 'approved'], true);
$canPay = in_array($status, ['approved', 'paying'], true);
$statusFlow = ['new', 'reviewing', 'approved', 'paying', 'paid'];
$currentIndex = array_search($status, $statusFlow, true);
?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">درخواست <span class="ltr mono"><?= e((string) $request['request_code']) ?></span></h1>
        <p class="text-muted text-sm mb-0">
            ثبت‌شده در <?= fa_date($request['created_at']) ?>
            · وضعیت:
            <span class="badge badge-<?= $badges[$status] ?? 'muted' ?>"><?= e(\RB\Services\WithdrawalService::statusLabel($status)) ?></span>
        </p>
    </div>
    <div class="flex gap-1">
        <a href="<?= url('admin/users/' . $request['user_id']) ?>" class="btn btn-secondary btn-sm">پروفایل کاربر</a>
        <a href="<?= url('admin/withdrawals') ?>" class="btn btn-ghost btn-sm">بازگشت به فهرست</a>
    </div>
</div>

<div class="stepper mb-3">
    <?php foreach ($statusFlow as $i => $step): ?>
        <?php
        $done = $currentIndex !== false && $i < $currentIndex;
        $active = $currentIndex !== false && $i === $currentIndex;
        $failed = in_array($status, ['rejected', 'cancelled'], true) && $i === 0;
        ?>
        <div class="step <?= $active ? 'active' : ($done ? 'done' : '') ?>">
            <span class="step-num"><?= $failed ? '✕' : fa_digits($i + 1) ?></span>
            <span class="text-sm"><?= e(\RB\Services\WithdrawalService::statusLabel($step)) ?></span>
        </div>
        <?php if ($i < count($statusFlow) - 1): ?><div class="step-line"></div><?php endif; ?>
    <?php endforeach; ?>
</div>

<?php if (in_array($status, ['rejected', 'cancelled'], true)): ?>
    <div class="alert alert-error">
        <span class="alert-icon">↩️</span>
        <div>
            این درخواست <?= $status === 'rejected' ? 'رد شده' : 'توسط کاربر لغو شده' ?> است و مبلغ آن به موجودی کیف پول کاربر بازگشته است.
            <?php if (!empty($request['reject_reason'])): ?>
                <div class="mt-2"><strong>دلیل:</strong> <?= e((string) $request['reject_reason']) ?></div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="stats-grid mb-3" style="grid-template-columns:repeat(auto-fill,minmax(190px,1fr))">
    <div class="stat purple"><div class="stat-head"><div class="stat-icon">💳</div><div class="stat-label">مبلغ درخواست</div></div>
        <div class="stat-value"><?= fa_digits(number_format((int) $request['amount'])) ?><small> تومان</small></div>
        <div class="stat-foot">معادل <?= fa_digits(number_format((int) $request['amount'] * 10)) ?> ریال</div></div>
    <div class="stat"><div class="stat-head"><div class="stat-icon">👛</div><div class="stat-label">موجودی فعلی کاربر</div></div>
        <div class="stat-value"><?= fa_digits(number_format($walletSummary['balance'])) ?><small> تومان</small></div>
        <div class="stat-foot">معوق: <?= wtoman($walletSummary['pending']) ?></div></div>
    <div class="stat info"><div class="stat-head"><div class="stat-icon">🏦</div><div class="stat-label">کارت مقصد</div></div>
        <div class="stat-value ltr" style="font-size:1.05rem"><?= e($maskedCard ?: '—') ?></div>
        <div class="stat-foot"><?= e((string) ($request['bank_name'] ?? '—')) ?></div></div>
    <div class="stat <?= $status === 'paid' ? 'success' : 'warning' ?>"><div class="stat-head"><div class="stat-icon">🧾</div><div class="stat-label">پرداخت</div></div>
        <div class="stat-value" style="font-size:1.05rem"><?= !empty($request['tracking_code']) ? e((string) $request['tracking_code']) : 'ثبت نشده' ?></div>
        <div class="stat-foot"><?= !empty($request['paid_at']) ? fa_date($request['paid_at']) : 'در انتظار' ?></div></div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2 class="card-title">اطلاعات درخواست و کاربر</h2></div>
        <div class="table-wrap">
            <table class="table">
                <tbody>
                <tr><td>کاربر</td><td><a href="<?= url('admin/users/' . $request['user_id']) ?>"><?= e(trim($request['first_name'] . ' ' . $request['last_name'])) ?></a></td></tr>
                <tr><td>شناسه تلگرام</td><td class="num"><?= fa_digits((string) $request['telegram_id']) ?></td></tr>
                <tr><td>نام کاربری</td><td class="ltr"><?= $request['username'] ? '@' . e((string) $request['username']) : '—' ?></td></tr>
                <tr><td>شماره کارت کامل</td><td>
                    <?php if ($card !== null): ?>
                        <span class="ltr mono" id="card-number" style="white-space:pre-wrap;overflow-wrap:anywhere"><?= e($card) ?></span>
                        <button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($card) ?>">کپی</button>
                    <?php else: ?>
                        <span class="text-muted">قابل رمزگشایی نیست (کلید رمزنگاری تغییر کرده)</span>
                    <?php endif; ?>
                </td></tr>
                <tr><td></td><td class="text-muted text-xs">شماره بدون اعتبارسنجی ذخیره شده است؛ پیش از واریز آن را بررسی کنید.</td></tr>
                <tr><td>صاحب کارت</td><td><?= e((string) ($request['card_holder'] ?? '—')) ?></td></tr>
                <tr><td>شماره شبا</td><td class="ltr"><?= e((string) ($request['iban'] ?? '—')) ?></td></tr>
                <tr><td>IP ثبت درخواست</td><td class="ltr"><?= e((string) ($request['client_ip'] ?? '—')) ?></td></tr>
                <tr><td>بررسی‌کننده</td><td><?= $reviewer ? e($reviewer['name']) . ' — ' . fa_date($request['reviewed_at']) : '—' ?></td></tr>
                <tr><td>پرداخت‌کننده</td><td><?= $payer ? e($payer['name']) . ' — ' . fa_date($request['paid_at']) : '—' ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">عملیات</h2>
            <span class="card-subtitle">هر عملیات در دفتر مالی و لاگ مدیران ثبت می‌شود</span>
        </div>
        <div class="card-body flex flex-col gap-3">
            <?php if ($canApprove): ?>
                <form method="post" action="<?= url('admin/withdrawals/' . $request['id'] . '/approve') ?>" data-ajax>
                    <?= csrf_field() ?>
                    <button class="btn btn-success btn-block" type="submit" data-confirm="درخواست تأیید شود و در صف پرداخت قرار گیرد؟">
                        ✓ تأیید درخواست
                    </button>
                    <div class="form-hint">پس از تأیید، دکمه «ثبت پرداخت» فعال می‌شود.</div>
                </form>
            <?php endif; ?>

            <?php if ($canPay): ?>
                <form method="post" action="<?= url('admin/withdrawals/' . $request['id'] . '/pay') ?>" data-ajax>
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label">کد پیگیری / شماره تراکنش بانکی</label>
                        <input type="text" name="tracking_code" class="form-control ltr" maxlength="70" placeholder="مثال: 123456789012">
                    </div>
                    <div class="form-group">
                        <label class="form-label">یادداشت داخلی</label>
                        <input type="text" name="note" class="form-control" maxlength="240" placeholder="اختیاری">
                    </div>
                    <button class="btn btn-block" type="submit" data-confirm="پرداخت این درخواست ثبت شود؟ مبلغ از حساب رزروشده کسر می‌شود.">
                        💸 ثبت پرداخت انجام‌شده
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($canReject): ?>
                <form method="post" action="<?= url('admin/withdrawals/' . $request['id'] . '/reject') ?>" data-ajax>
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label">دلیل رد درخواست (اجباری)</label>
                        <textarea name="reason" class="form-control" rows="2" maxlength="240" required placeholder="مثال: شماره کارت با نام کاربر هم‌خوانی ندارد"></textarea>
                    </div>
                    <button class="btn btn-danger btn-block" type="submit" data-confirm="درخواست رد شود و مبلغ به کیف پول کاربر بازگردد؟">
                        ✕ رد درخواست و بازگشت مبلغ
                    </button>
                </form>
            <?php endif; ?>

            <?php if (!$canApprove && !$canPay && !$canReject): ?>
                <div class="empty" style="padding:20px">
                    <div class="empty-icon">🔒</div>
                    <div class="empty-title">این درخواست بسته شده است</div>
                    <div class="empty-text">در وضعیت «<?= e(\RB\Services\WithdrawalService::statusLabel($status)) ?>» امکان تغییر وجود ندارد. تمام تغییرات قبلی در لاگ ثبت شده‌اند.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header"><h2 class="card-title">پرداخت‌های ثبت‌شده</h2></div>
        <?php if (empty($payments)): ?>
            <div class="empty" style="padding:24px"><div class="empty-icon">🧾</div><div class="empty-title">هنوز پرداختی ثبت نشده</div></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>مبلغ</th><th>روش</th><th>مرجع</th><th>مدیر</th><th>تاریخ</th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td class="num" data-label="مبلغ"><?= wtoman($p['amount']) ?></td>
                            <td data-label="روش"><?= e((string) $p['method']) ?></td>
                            <td class="ltr" data-label="مرجع"><?= e((string) ($p['reference'] ?? '—')) ?></td>
                            <td data-label="مدیر"><?= e((string) ($p['admin_name'] ?? '—')) ?></td>
                            <td class="text-xs" data-label="تاریخ"><?= fa_date($p['paid_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">سابقه درخواست‌های این کاربر</h2></div>
        <?php if (empty($history)): ?>
            <div class="empty" style="padding:24px"><div class="empty-icon">📁</div><div class="empty-title">سابقه دیگری وجود ندارد</div></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>کد</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($history as $h): ?>
                        <tr>
                            <td class="ltr mono" data-label="کد"><?= e((string) $h['request_code']) ?></td>
                            <td class="num" data-label="مبلغ"><?= wtoman($h['amount']) ?></td>
                            <td data-label="وضعیت"><span class="badge badge-<?= $badges[$h['status']] ?? 'muted' ?>"><?= e(\RB\Services\WithdrawalService::statusLabel((string) $h['status'])) ?></span></td>
                            <td class="text-xs" data-label="تاریخ"><?= fa_date($h['created_at']) ?></td>
                            <td data-label=""><a href="<?= url('admin/withdrawals/' . $h['id']) ?>" class="btn btn-ghost btn-sm">مشاهده</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

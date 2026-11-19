<div class="alert alert-info">سطح‌بندی به‌صورت پیش‌فرض خاموش است. فقط رویدادهای تأییدشده و برگشت‌نخورده شمارش می‌شوند؛ دعوتی که آستانه را تکمیل می‌کند با نرخ قبلی پرداخت می‌شود. شرایط اعتبار را در تنظیمات پورسانت و امنیت سخت‌گیرانه انتخاب کنید.</div>
<div class="alert alert-info">بودجه، سقف تجمیعی پرداخت‌های این سیستم از زمان فعال‌سازی است (نه بودجه روزانه). سقف هر سطح برای هر کاربر شامل پاداش دعوت و ارتقاست. پاداش‌های قبلی بازنویسی نمی‌شوند. پورسانت‌های سایر لایه‌ها و کمپین‌ها مشمول این بودجه نیستند.</div>
<div class="card"><div class="card-header"><h3>کنترل اصلی و بودجه</h3></div><div class="card-body">
<form method="post" action="<?= url('admin/user-levels/config') ?>">
<?= csrf_field() ?>
<label>سقف بودجه کل (تومان) <input class="form-control" type="number" min="0" name="budget" required value="<?= e((string)$config['budget']) ?>"></label>
<p>مصرف‌شده: <?= e(wtoman((int)$config['spent'])) ?> — بودجه در صورت برگشت جایزه آزاد نمی‌شود.</p>
<label><input type="checkbox" name="enabled" value="1" <?= !empty($config['enabled']) ? 'checked' : '' ?>> فعال‌سازی سطح‌بندی و پرداخت‌های آن</label>
<button class="btn btn-primary" type="submit">ذخیره تنظیمات</button>
</form></div></div>
<?php
$levelForm = static function (?array $level): void {
    $v = $level ?? ['id'=>0,'name'=>'','icon'=>'🏅','badge'=>'','color'=>'#6366f1','min_invites'=>5,'invite_reward'=>0,'promotion_reward'=>0,'user_cap'=>1,'benefits'=>'','enabled'=>1];
?>
<form method="post" action="<?= url('admin/user-levels/save') ?>" class="card-body">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
<div class="grid grid-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
<label>نام رتبه<input class="form-control" name="name" maxlength="100" required value="<?= e($v['name']) ?>"></label>
<label>آیکون (متن یا ایموجی)<input class="form-control" name="icon" maxlength="32" required value="<?= e($v['icon']) ?>"></label>
<label>نشان افتخار<input class="form-control" name="badge" maxlength="100" value="<?= e($v['badge']) ?>"></label>
<label>رنگ<input class="form-control" type="color" name="color" value="<?= e($v['color']) ?>"></label>
<label>حداقل دعوت معتبر<input class="form-control" type="number" min="1" max="100000000" name="min_invites" required value="<?= (int)$v['min_invites'] ?>"></label>
<label>پاداش هر دعوت بعدی (تومان)<input class="form-control" type="number" min="0" name="invite_reward" required value="<?= (int)$v['invite_reward'] ?>"></label>
<label>جایزه یک‌باره ارتقا (تومان)<input class="form-control" type="number" min="0" name="promotion_reward" required value="<?= (int)$v['promotion_reward'] ?>"></label>
<label>سقف کل پرداخت به هر کاربر در این سطح (تومان)<input class="form-control" type="number" min="1" name="user_cap" required value="<?= (int)$v['user_cap'] ?>"></label>
<label>شرح مزایا<input class="form-control" name="benefits" maxlength="2000" value="<?= e($v['benefits'] ?? '') ?>"></label>
</div><p><label><input type="checkbox" name="enabled" value="1" <?= !empty($v['enabled']) ? 'checked' : '' ?>> سطح فعال باشد</label></p>
<button class="btn btn-primary" type="submit">ذخیره سطح</button>
</form>
<?php }; ?>
<div class="card"><div class="card-header"><h3>افزودن سطح جدید</h3></div><?php $levelForm(null); ?></div>
<?php foreach ($levels as $level): ?>
<div class="card"><div class="card-header"><h3><?= e($level['icon'] . ' ' . $level['name']) ?></h3></div>
<?php $levelForm($level); ?>
<form method="post" action="<?= url('admin/user-levels/delete') ?>" class="card-body" onsubmit="return confirm('این سطح حذف شود؟ سوابق مالی حفظ می‌شود.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$level['id'] ?>"><button class="btn btn-danger" type="submit">حذف سطح</button></form></div>
<?php endforeach; ?>
<div class="card"><div class="card-header"><h3>گزارش پرداخت سطح‌ها</h3></div><div class="card-body">
<?php foreach ($payments as $payment): ?><p><?= e($payment['name']) ?> — <?= $payment['kind'] === 'invite' ? 'دعوت' : 'ارتقا' ?>: <?= fa_digits((int)$payment['count']) ?> پرداخت، <?= e(wtoman((int)$payment['total'])) ?></p><?php endforeach; ?>
<?php if (!$payments): ?><p>پرداختی ثبت نشده است.</p><?php endif; ?>
</div></div>
<div class="card"><div class="card-header"><h3>کاربران و وضعیت سطح</h3></div><div class="card-body">
<form method="get" action="<?= url('admin/user-levels') ?>"><label>سطح <select name="level"><option value="0">همه</option><?php foreach ($levels as $level): ?><option value="<?= (int)$level['id'] ?>" <?= $filter === (int)$level['id'] ? 'selected' : '' ?>><?= e($level['name']) ?></option><?php endforeach; ?></select></label><button class="btn btn-secondary">فیلتر</button></form>
<p>هر صفحه ۱۰۰ کاربر را بررسی می‌کند؛ فیلتر فقط در صفحه جاری اعمال می‌شود.</p>
<?php foreach ($users as $row): $profile = $row['level_profile']; ?><p><a href="<?= url('admin/users/' . (int)$row['id']) ?>"><?= e(trim($row['first_name'] . ' ' . $row['last_name']) ?: ($row['username'] ?: '#'.$row['id'])) ?></a> — <?= e($profile['current']['name'] ?? 'بدون سطح') ?> — <?= fa_digits((int)($profile['count'] ?? 0)) ?> دعوت معتبر</p><?php endforeach; ?>
<p><?php if ($page > 1): ?><a href="<?= url('admin/user-levels?page=' . ($page - 1) . '&level=' . $filter) ?>">صفحه قبل</a> · <?php endif; ?><a href="<?= url('admin/user-levels?page=' . ($page + 1) . '&level=' . $filter) ?>">صفحه بعد</a></p>
</div></div>

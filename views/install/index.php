<?php
$step = (int) ($step ?? 1);
$stepLabels = [1 => 'پیش‌نیازها', 2 => 'پایگاه داده', 3 => 'ساخت جداول', 4 => 'حساب مدیر', 5 => 'تنظیمات پایه', 6 => 'پایان'];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrf) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title>نصب سامانه — رفرال‌بات</title>
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body style="background:linear-gradient(160deg,#eef2ff,#f5f7fb 40%,#ecfeff)">
<div style="max-width:860px;margin:0 auto;padding:32px 16px 60px">

    <div class="text-center mb-4">
        <div style="width:62px;height:62px;border-radius:20px;background:linear-gradient(135deg,#4f46e5,#06b6d4);display:grid;place-items:center;margin:0 auto 14px;color:#fff;font-weight:800;font-size:1.5rem;box-shadow:0 10px 30px rgba(79,70,229,.35)">R</div>
        <h1 style="margin-bottom:4px">نصب سامانه مدیریت رفرال و پورسانت</h1>
        <p class="text-muted text-sm mb-0">در چند مرحله ساده، سامانه را روی هاست خود راه‌اندازی کنید</p>
    </div>

    <div class="stepper mb-4">
        <?php $i = 1; $count = count($stepLabels); foreach ($stepLabels as $num => $label): ?>
            <div class="step <?= $step === $num ? 'active' : ($step > $num ? 'done' : '') ?>">
                <div class="step-num"><?= $step > $num ? '✓' : fa_digits($num) ?></div>
                <span style="white-space:nowrap;font-size:.72rem"><?= e($label) ?></span>
            </div>
            <?php if ($i < $count): ?><div class="step-line"></div><?php endif; ?>
        <?php $i++; endforeach; ?>
    </div>

    <!-- مرحله ۱ -->
    <div class="card" id="panel-1" <?= $step !== 1 ? 'style="display:none"' : '' ?>>
        <div class="card-header"><h2 class="card-title">بررسی پیش‌نیازهای سرور</h2></div>
        <div class="card-body">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>پیش‌نیاز</th><th>وضعیت</th><th>مقدار</th></tr></thead>
                    <tbody>
                    <?php foreach ($requirements as $req): ?>
                        <tr>
                            <td data-label="پیش‌نیاز"><?= e($req['label']) ?></td>
                            <td data-label="وضعیت"><span class="badge badge-<?= $req['ok'] ? 'success' : 'danger' ?>"><?= $req['ok'] ? 'تأیید' : 'نقص' ?></span></td>
                            <td data-label="مقدار" class="text-sm text-muted"><?= e($req['value']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$isHttps): ?>
                <div class="alert alert-warning mt-3">
                    <span class="alert-icon">🔒</span>
                    <div>
                        <strong>شما بدون HTTPS هستید.</strong>
                        ورود به پنل از طریق IP و HTTP کار می‌کند، اما:
                        <ul style="margin:8px 0 0;padding-inline-start:20px">
                            <li><strong>Webhook تلگرام</strong> فقط با HTTPS و گواهی معتبر کار می‌کند؛ آدرس IP خالی پذیرفته نمی‌شود.</li>
                            <li><strong>Mini App</strong> تنها در بستر HTTPS باز می‌شود.</li>
                        </ul>
                        راهکارهای عملی در فایل <code>docs/DEPLOY.md</code> آمده است.
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($fatal): ?>
                <div class="alert alert-error mt-3">
                    <span class="alert-icon">⛔</span>
                    <div>برخی پیش‌نیازهای الزامی تأمین نشده است. تا رفع آن‌ها امکان ادامه نصب وجود ندارد.</div>
                </div>
            <?php endif; ?>

            <button class="btn btn-lg mt-3" id="btn-step-1" <?= $fatal ? 'disabled' : '' ?>>مرحله بعد: اتصال پایگاه داده ←</button>
        </div>
    </div>

    <!-- مرحله ۲ -->
    <div class="card" id="panel-2" style="display:none">
        <div class="card-header"><h2 class="card-title">اتصال به پایگاه داده MySQL</h2></div>
        <div class="card-body">
            <div class="alert alert-info">
                <span class="alert-icon">ℹ️</span>
                <div>اطلاعات اتصال را از بخش «MySQL Databases» کنترل پنل هاست خود بردارید. پایگاه داده و کاربر را از قبل بسازید.</div>
            </div>
            <form id="form-db">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">هاست پایگاه داده <span class="req">*</span></label>
                        <input type="text" class="form-control ltr" name="db_host" value="localhost" required>
                        <div class="form-hint">معمولاً <code>localhost</code></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">پورت</label>
                        <input type="text" class="form-control ltr" name="db_port" value="3306">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">نام پایگاه داده <span class="req">*</span></label>
                    <input type="text" class="form-control ltr" name="db_name" required placeholder="cpaneluser_refrallbot">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">نام کاربری <span class="req">*</span></label>
                        <input type="text" class="form-control ltr" name="db_user" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label">رمز عبور</label>
                        <input type="password" class="form-control ltr" name="db_pass" autocomplete="new-password">
                    </div>
                </div>
                <div id="db-result"></div>
                <button type="submit" class="btn btn-lg">تست اتصال و ادامه ←</button>
            </form>
        </div>
    </div>

    <!-- مرحله ۳ -->
    <div class="card" id="panel-3" style="display:none">
        <div class="card-header"><h2 class="card-title">ساخت جداول پایگاه داده</h2></div>
        <div class="card-body">
            <p class="text-sm text-muted">با کلیک روی دکمه زیر، تمام جداول موردنیاز ساخته می‌شوند و تنظیمات پیش‌فرض درج می‌گردد. این عملیات قابل تکرار است و داده‌های موجود را حذف نمی‌کند.</p>
            <div id="migrate-result"></div>
            <button class="btn btn-lg" id="btn-migrate">ساخت جداول و ادامه ←</button>
        </div>
    </div>

    <!-- مرحله ۴ -->
    <div class="card" id="panel-4" style="display:none">
        <div class="card-header"><h2 class="card-title">ایجاد حساب مدیر کل</h2></div>
        <div class="card-body">
            <div class="alert alert-info">
                <span class="alert-icon">🔑</span>
                <div>
                    رمز عبور باید حداقل ۱۰ کاراکتر و شامل حروف بزرگ، کوچک و عدد باشد.
                    <strong>این اطلاعات را در جای امن نگه دارید</strong> — رمز به‌صورت هش ذخیره می‌شود و قابل بازیابی نیست.
                </div>
            </div>
            <form id="form-admin">
                <div class="form-group">
                    <label class="form-label">نام و نام خانوادگی <span class="req">*</span></label>
                    <input type="text" class="form-control" name="name" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">نام کاربری <span class="req">*</span></label>
                        <input type="text" class="form-control ltr" name="username" required autocomplete="off" placeholder="admin">
                        <div class="form-hint">حروف لاتین، عدد، نقطه و زیرخط — ۴ تا ۴۰ کاراکتر</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">شناسه عددی تلگرام (اختیاری)</label>
                        <input type="text" class="form-control ltr" name="telegram_id" placeholder="123456789">
                        <div class="form-hint">برای ورود از داخل ربات و نمایش دکمه پنل مدیریت</div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">رمز عبور <span class="req">*</span></label>
                        <input type="password" class="form-control ltr" name="password" required autocomplete="new-password" minlength="10">
                    </div>
                    <div class="form-group">
                        <label class="form-label">تکرار رمز عبور <span class="req">*</span></label>
                        <input type="password" class="form-control ltr" name="password_confirm" required autocomplete="new-password" minlength="10">
                    </div>
                </div>
                <div id="admin-result"></div>
                <button type="submit" class="btn btn-lg">ایجاد مدیر و ادامه ←</button>
            </form>
        </div>
    </div>

    <!-- مرحله ۵ -->
    <div class="card" id="panel-5" style="display:none">
        <div class="card-header"><h2 class="card-title">تنظیمات پایه</h2></div>
        <div class="card-body">
            <form id="form-settings">
                <div class="form-group">
                    <label class="form-label">نام سامانه</label>
                    <input type="text" class="form-control" name="app_name" value="رفرال‌بات" required>
                </div>
                <div class="form-group">
                    <label class="form-label">آدرس پایه سامانه</label>
                    <input type="text" class="form-control ltr" name="base_url" value="<?= e($origin) ?>">
                    <div class="form-hint">
                        اگر دامنه دارید آدرس دامنه را وارد کنید (مثلاً <code>https://ref.example.com</code>).
                        این آدرس برای ساخت لینک Webhook و Mini App استفاده می‌شود.
                    </div>
                </div>
                <div id="settings-result"></div>
                <button type="submit" class="btn btn-lg">ذخیره و ادامه ←</button>
            </form>
        </div>
    </div>

    <!-- مرحله ۶ -->
    <div class="card" id="panel-6" style="display:none">
        <div class="card-body" style="text-align:center;padding:40px 22px">
            <div style="font-size:3rem;margin-bottom:12px">🎉</div>
            <h2 class="mb-2">نصب با موفقیت تکمیل شد</h2>
            <p class="text-muted">اکنون می‌توانید وارد پنل مدیریت شوید و توکن ربات تلگرام را ثبت کنید.</p>
            <div class="alert alert-warning" style="text-align:right">
                <span class="alert-icon">🛡</span>
                <div>
                    صفحه نصب اکنون غیرفعال شده است. مطمئن شوید فایل <code>.env</code> از طریق مرورگر قابل دانلود نیست
                    و دسترسی پوشه <code>storage/</code> فقط برای سرور باز است.
                </div>
            </div>
            <a href="<?= url('admin/login') ?>" class="btn btn-lg mt-2">ورود به پنل مدیریت ←</a>
        </div>
    </div>

    <p class="text-center text-xs text-muted mt-4">پس از نصب، این صفحه به‌صورت خودکار غیرفعال می‌شود.</p>
</div>

<div class="toasts"></div>
<script src="<?= asset('js/app.js') ?>"></script>
<script>
(function () {
  const panels = {1:'panel-1',2:'panel-2',3:'panel-3',4:'panel-4',5:'panel-5',6:'panel-6'};
  const steps = document.querySelectorAll('.step');

  function show(step) {
    Object.entries(panels).forEach(([n, id]) => {
      const el = document.getElementById(id);
      if (el) el.style.display = String(n) === String(step) ? '' : 'none';
    });
    steps.forEach((el, i) => {
      el.classList.toggle('active', i + 1 === step);
      el.classList.toggle('done', i + 1 < step);
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  function busy(btn, on) {
    if (!btn) return;
    if (on) { btn.dataset.html = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spinner" style="width:16px;height:16px;border-width:2px"></span> در حال اجرا…'; }
    else { btn.disabled = false; btn.innerHTML = btn.dataset.html || btn.innerHTML; }
  }
  function box(id, ok, msg) {
    document.getElementById(id).innerHTML = '<div class="alert alert-' + (ok ? 'success' : 'error') +
      '"><span class="alert-icon">' + (ok ? '✓' : '⚠️') + '</span><div>' + RB.escape(msg) + '</div></div>';
  }

  document.getElementById('btn-step-1').addEventListener('click', () => show(2));

  document.getElementById('form-db').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = this.querySelector('[type=submit]');
    busy(btn, true);
    const payload = {};
    new FormData(this).forEach((v, k) => payload[k] = v);
    const result = await RB.post('<?= url('install/database') ?>', payload);
    busy(btn, false);
    box('db-result', result.ok, result.ok ? result.message : result.error);
    if (result.ok) setTimeout(() => show(3), 700);
  });

  document.getElementById('btn-migrate').addEventListener('click', async function () {
    busy(this, true);
    const result = await RB.post('<?= url('install/migrate') ?>', {});
    busy(this, false);
    box('migrate-result', result.ok, result.ok
      ? result.message + ' — تعداد جداول: ' + RB.faNum(result.tables || 0)
      : result.error);
    if (result.ok) setTimeout(() => show(4), 800);
  });

  document.getElementById('form-admin').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = this.querySelector('[type=submit]');
    busy(btn, true);
    const payload = {};
    new FormData(this).forEach((v, k) => payload[k] = v);
    const result = await RB.post('<?= url('install/admin') ?>', payload);
    busy(btn, false);
    box('admin-result', result.ok, result.ok ? result.message : result.error);
    if (result.ok) setTimeout(() => show(5), 700);
  });

  document.getElementById('form-settings').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = this.querySelector('[type=submit]');
    busy(btn, true);
    const payload = {};
    new FormData(this).forEach((v, k) => payload[k] = v);
    const r1 = await RB.post('<?= url('install/settings') ?>', payload);
    if (!r1.ok) { busy(btn, false); box('settings-result', false, r1.error); return; }
    const r2 = await RB.post('<?= url('install/finish') ?>', {});
    busy(btn, false);
    if (r2.ok) show(6); else box('settings-result', false, r2.error);
  });

  show(<?= (int) $step ?>);
})();
</script>
</body>
</html>

<?php $isSuper = (($currentAdmin['role_slug'] ?? '') === 'superadmin'); ?>
<div class="flex items-center justify-between wrap gap-3 mb-3">
    <div>
        <h1 class="mb-0">مدیران سامانه</h1>
        <p class="text-muted text-sm mb-0">ایجاد مدیر جدید، تغییر رمز و فعال/غیرفعال‌سازی — رمزها با bcrypt/argon هش می‌شوند</p>
    </div>
</div>

<?php if (!$isSuper): ?>
    <div class="alert alert-warning">
        <span class="alert-icon">🔒</span>
        <div>فقط «مدیر کل» می‌تواند مدیران را مدیریت کند. شما فقط امکان تغییر رمز خود را دارید.</div>
    </div>
<?php endif; ?>

<div class="grid-2">
    <?php if ($isSuper): ?>
    <div class="card">
        <div class="card-header"><h2 class="card-title">مدیر جدید</h2></div>
        <form method="post" action="<?= url('admin/admins') ?>" data-ajax>
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">نام نمایشی</label>
                    <input type="text" name="name" class="form-control" maxlength="110" required>
                </div>
                <div class="form-group">
                    <label class="form-label">نام کاربری (لاتین)</label>
                    <input type="text" name="username" class="form-control ltr" dir="ltr" required pattern="[A-Za-z0-9_.]{4,40}" placeholder="admin2">
                </div>
                <div class="form-group">
                    <label class="form-label">رمز عبور</label>
                    <input type="password" name="password" class="form-control ltr" dir="ltr" required autocomplete="new-password">
                    <div class="form-hint">حداقل ۱۰ کاراکتر شامل حروف بزرگ، کوچک و عدد.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">شناسه تلگرام (برای ارسال پیام آزمایشی و ورود با لینک)</label>
                    <input type="text" name="telegram_user_id" class="form-control ltr" dir="ltr" placeholder="123456789">
                </div>
                <div class="form-group">
                    <label class="form-label">نقش</label>
                    <select name="role_id" class="form-select">
                        <option value="0">مدیر کل (پیش‌فرض)</option>
                        <?php
                        $roles = \RB\Support\Database::instance()->all('SELECT id, name FROM admin_roles ORDER BY id');
                        foreach ($roles as $role): ?>
                            <option value="<?= (int) $role['id'] ?>"><?= e((string) $role['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="card-footer"><button class="btn btn-block" type="submit">➕ ایجاد مدیر</button></div>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2 class="card-title">فهرست مدیران</h2></div>
        <?php if (empty($admins)): ?>
            <div class="empty"><div class="empty-icon">🔑</div><div class="empty-title">مدیری یافت نشد</div></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>نام</th><th>نام کاربری</th><th>نقش</th><th>وضعیت</th><th>آخرین ورود</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($admins as $a): ?>
                        <tr>
                            <td data-label="نام"><?= e((string) $a['name']) ?>
                                <?php if (!empty($a['telegram_user_id'])): ?>
                                    <div class="user-cell-meta ltr"><?= e((string) $a['telegram_user_id']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="ltr mono" data-label="نام کاربری"><?= e((string) $a['username']) ?></td>
                            <td data-label="نقش"><?= e((string) ($a['role_name'] ?? '—')) ?></td>
                            <td data-label="وضعیت"><span class="badge badge-<?= !empty($a['is_active']) ? 'success' : 'muted' ?>"><?= !empty($a['is_active']) ? 'فعال' : 'غیرفعال' ?></span></td>
                            <td class="text-xs" data-label="آخرین ورود"><?= fa_date($a['last_login_at']) ?></td>
                            <td data-label="">
                                <div class="actions" style="flex-wrap:wrap">
                                    <?php if ($isSuper || (int) $a['id'] === (int) ($currentAdmin['id'] ?? 0)): ?>
                                        <button class="btn btn-sm btn-secondary" type="button" data-modal-open="pwd-<?= (int) $a['id'] ?>">رمز</button>
                                    <?php endif; ?>
                                    <?php if ($isSuper && (int) $a['id'] !== (int) ($currentAdmin['id'] ?? 0)): ?>
                                        <form method="post" action="<?= url('admin/admins/' . $a['id'] . '/toggle') ?>" data-ajax>
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-ghost" type="submit"><?= !empty($a['is_active']) ? 'غیرفعال' : 'فعال' ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <div class="modal-backdrop" id="pwd-<?= (int) $a['id'] ?>">
                                    <div class="modal">
                                        <div class="modal-header">
                                            <h2 class="modal-title">تغییر رمز <?= e((string) $a['name']) ?></h2>
                                            <button class="btn btn-ghost btn-icon" type="button" data-modal-close>✕</button>
                                        </div>
                                        <form method="post" action="<?= url('admin/admins/' . $a['id'] . '/password') ?>" data-ajax>
                                            <?= csrf_field() ?>
                                            <div class="modal-body">
                                                <div class="form-group">
                                                    <label class="form-label">رمز جدید</label>
                                                    <input type="password" name="password" class="form-control ltr" dir="ltr" required autocomplete="new-password">
                                                    <div class="form-hint">حداقل ۱۰ کاراکتر شامل حروف بزرگ، کوچک و عدد.</div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button class="btn" type="submit">ذخیره رمز</button>
                                                <button class="btn btn-ghost" type="button" data-modal-close>انصراف</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

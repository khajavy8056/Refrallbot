<?php
/** @var array<int,array> $messages */
/** @var array<int,array> $banners */
/** @var ?array $editMessage */
/** @var ?array $editBanner */
/** @var string $appName */
/** @var string $inviterRewardFa */
/** @var string $inviteeRewardFa */
/** @var string $sampleLink */

$vars = [
    '{invite_link}'    => 'لینک دعوت اختصاصی کاربر',
    '{referral_code}'  => 'کد دعوت کاربر',
    '{user_name}'      => 'نام کاربر دعوت‌کننده',
    '{app_name}'       => 'نام سامانه',
    '{inviter_reward}' => 'مبلغ پاداش دعوت‌کننده',
    '{invitee_reward}' => 'مبلغ هدیه دوست دعوت‌شده',
    '{platform_name}'  => 'نام پیام‌رسان',
];
?>
<div class="page-head">
    <div>
        <h1 class="page-title">🎨 استودیوی محتوای دعوت و بنرهای تبلیغاتی</h1>
        <p class="page-sub">قالب‌های پیام دعوت و بنرهای تصویری که کاربران در ربات و مینی‌اپ (تلگرام، بله، روبیکا و ایتا) همراه لینک دعوت خود برای دوستان ارسال می‌کنند</p>
    </div>
</div>

<div class="alert alert-info mb-3">
    <span class="alert-icon">🛡</span>
    <div class="text-sm">
        <strong>تضمین صددرصدی لینک دعوت:</strong> حتی اگر در متن قالب یا کپشن بنر متغیر <code class="ltr">{invite_link}</code> را ننویسید، سامانه هنگام نمایش، کپی و ارسال بنر به‌صورت خودکار لینک دعوت اختصاصی هر کاربر را در انتهای پیام اضافه می‌کند تا هیچ دعوتی بدون ثبت معرف از دست نرود.
    </div>
</div>

<!-- ======================= بخش ۱: بنرهای تبلیغاتی ======================= -->
<div class="grid-2 mb-3" id="banners">
    <div class="card">
        <div class="card-header justify-between">
            <h2 class="card-title"><?= $editBanner ? '✏️ ویرایش بنر تبلیغاتی' : '➕ افزودن بنر تبلیغاتی جدید' ?></h2>
            <?php if ($editBanner): ?>
                <a href="<?= url('admin/invite-content#banners') ?>" class="btn btn-ghost btn-sm">انصراف</a>
            <?php endif; ?>
        </div>
        <form method="post" action="<?= url('admin/invite-content/banners/save') ?>" enctype="multipart/form-data" class="card-body">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) ($editBanner['id'] ?? 0) ?>">

            <div class="form-group">
                <label class="form-label">عنوان بنر (نمایش در لیست انتخاب کاربر)</label>
                <input type="text" name="title" class="form-control" required maxlength="120"
                       value="<?= e((string) ($editBanner['title'] ?? '')) ?>" placeholder="مثلاً: بنر طلایی هدیه عضویت">
            </div>

            <div class="form-group">
                <label class="form-label">تصویر بنر (JPG، PNG یا WebP — حداکثر ۵ مگابایت، نسبت ۱۶:۹ پیشنهادی)</label>
                <?php if (!empty($editBanner['public_url'])): ?>
                    <div style="margin-bottom:8px;border-radius:10px;overflow:hidden;max-width:260px;border:1px solid var(--border)">
                        <img src="<?= e($editBanner['public_url']) ?>" alt="" style="width:100%;display:block">
                    </div>
                <?php endif; ?>
                <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp" <?= $editBanner ? '' : 'required' ?>>
                <?php if ($editBanner): ?>
                    <div class="form-hint">اگر فایل جدید انتخاب نکنید، تصویر فعلی حفظ می‌شود.</div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">کپشن پیشنهادی بنر (اختیاری — از متغیرها پشتیبانی می‌کند)</label>
                <textarea name="caption" id="banner-caption-input" class="form-control" rows="4" maxlength="950"
                          placeholder="🎁 به {app_name} بپیوند و {invitee_reward} هدیه خوش‌آمدگویی بگیر!&#10;&#10;🔗 {invite_link}"><?= e((string) ($editBanner['caption'] ?? '')) ?></textarea>
                <div class="flex gap-1 mt-1" style="flex-wrap:wrap">
                    <?php foreach ($vars as $vk => $vl): ?>
                        <button type="button" class="btn btn-ghost btn-sm" style="padding:2px 8px;font-size:.72rem"
                                title="<?= e($vl) ?>" onclick="rbInsertVar('banner-caption-input','<?= e($vk) ?>')">
                            <span class="ltr"><?= e($vk) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">ترتیب نمایش</label>
                    <input type="number" name="sort_order" class="form-control ltr" value="<?= (int) ($editBanner['sort_order'] ?? 10) ?>" min="0" max="999">
                </div>
                <div class="form-group" style="display:flex;flex-direction:column;justify-content:center;gap:6px;padding-top:18px">
                    <label class="check-row">
                        <input type="checkbox" name="is_enabled" value="1" <?= (!$editBanner || !empty($editBanner['is_enabled'])) ? 'checked' : '' ?>>
                        <span>فعال و قابل انتخاب توسط کاربر</span>
                    </label>
                    <label class="check-row">
                        <input type="checkbox" name="is_default" value="1" <?= !empty($editBanner['is_default']) ? 'checked' : '' ?>>
                        <span>بنر پیش‌فرض</span>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn"><?= $editBanner ? '💾 ذخیره تغییرات بنر' : '📤 آپلود و ثبت بنر' ?></button>
        </form>
    </div>

    <div class="card">
        <div class="card-header justify-between">
            <h2 class="card-title">🖼 بنرهای تبلیغاتی موجود (<?= fa_digits(count($banners)) ?>)</h2>
        </div>
        <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px">
            <?php foreach ($banners as $b): ?>
                <div style="border:1px solid var(--border);border-radius:14px;overflow:hidden;background:var(--surface-2);display:flex;flex-direction:column">
                    <div style="position:relative;aspect-ratio:16/9;background:#0f172a">
                        <?php if (!empty($b['public_url'])): ?>
                            <img src="<?= e($b['public_url']) ?>" alt="<?= e($b['title']) ?>" style="width:100%;height:100%;object-fit:cover;display:block">
                        <?php endif; ?>
                        <div style="position:absolute;top:8px;right:8px;display:flex;gap:4px">
                            <?php if (!empty($b['is_default'])): ?>
                                <span class="badge badge-brand no-dot">⭐ پیش‌فرض</span>
                            <?php endif; ?>
                            <span class="badge <?= !empty($b['is_enabled']) ? 'badge-success' : 'badge-muted' ?> no-dot">
                                <?= !empty($b['is_enabled']) ? 'فعال' : 'غیرفعال' ?>
                            </span>
                        </div>
                    </div>
                    <div style="padding:10px 12px;flex:1;display:flex;flex-direction:column;justify-content:space-between;gap:8px">
                        <div>
                            <strong class="text-sm"><?= e($b['title']) ?></strong>
                            <div class="text-xs text-muted mt-1">ارسال‌شده: <?= fa_digits((int) ($b['share_count'] ?? 0)) ?> بار</div>
                        </div>
                        <div class="flex gap-1" style="flex-wrap:wrap">
                            <a href="<?= url('admin/invite-content?edit_banner=' . (int) $b['id'] . '#banners') ?>" class="btn btn-secondary btn-sm">ویرایش</a>
                            <form method="post" action="<?= url('admin/invite-content/banners/' . (int) $b['id'] . '/toggle') ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-ghost btn-sm"><?= !empty($b['is_enabled']) ? 'غیرفعال' : 'فعال' ?></button>
                            </form>
                            <?php if (empty($b['is_default']) && !empty($b['is_enabled'])): ?>
                                <form method="post" action="<?= url('admin/invite-content/banners/' . (int) $b['id'] . '/default') ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-ghost btn-sm" title="تنظیم به‌عنوان پیش‌فرض">⭐</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= url('admin/invite-content/banners/' . (int) $b['id'] . '/delete') ?>"
                                  onsubmit="return confirm('این بنر تبلیغاتی حذف شود؟');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-danger btn-sm">🗑</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ======================= بخش ۲: قالب‌های پیام دعوت ======================= -->
<div class="grid-2" id="messages">
    <div class="card">
        <div class="card-header justify-between">
            <h2 class="card-title"><?= $editMessage ? '✏️ ویرایش قالب پیام دعوت' : '➕ افزودن قالب پیام دعوت جدید' ?></h2>
            <?php if ($editMessage): ?>
                <a href="<?= url('admin/invite-content#messages') ?>" class="btn btn-ghost btn-sm">انصراف</a>
            <?php endif; ?>
        </div>
        <form method="post" action="<?= url('admin/invite-content/messages/save') ?>" class="card-body">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) ($editMessage['id'] ?? 0) ?>">

            <div class="form-group">
                <label class="form-label">عنوان قالب (مثلاً: دعوت صمیمی، کسب درآمد شبکه‌ای)</label>
                <input type="text" name="title" class="form-control" required maxlength="120"
                       value="<?= e((string) ($editMessage['title'] ?? '')) ?>" placeholder="دعوت دوستانه همراه هدیه">
            </div>

            <div class="form-group">
                <label class="form-label">متن پیام دعوت</label>
                <textarea name="body" id="msg-body-input" class="form-control" rows="6" required maxlength="2000"
                          oninput="rbUpdateMsgPreview()"><?= e((string) ($editMessage['body'] ?? "سلام! 👋\nمن عضو {app_name} شدم. با لینک دعوت من وارد شو تا {invitee_reward} هدیه خوش‌آمدگویی بگیری و خودت هم از دعوت دوستانت درآمد نقدی کسب کنی:\n\n🔗 {invite_link}")) ?></textarea>
                <div class="text-xs text-muted mt-1 mb-1">درج سریع متغیرها:</div>
                <div class="flex gap-1" style="flex-wrap:wrap">
                    <?php foreach ($vars as $vk => $vl): ?>
                        <button type="button" class="btn btn-ghost btn-sm" style="padding:2px 8px;font-size:.72rem"
                                title="<?= e($vl) ?>" onclick="rbInsertVar('msg-body-input','<?= e($vk) ?>');rbUpdateMsgPreview()">
                            <span class="ltr"><?= e($vk) ?></span> — <?= e($vl) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">👁 پیش‌نمایش زنده خروجی برای کاربر</label>
                <div id="msg-live-preview" style="padding:12px;border-radius:12px;border:1px solid var(--border);background:var(--surface-2);font-size:.82rem;line-height:1.9;white-space:pre-wrap"></div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">ترتیب نمایش</label>
                    <input type="number" name="sort_order" class="form-control ltr" value="<?= (int) ($editMessage['sort_order'] ?? 10) ?>" min="0" max="999">
                </div>
                <div class="form-group" style="display:flex;flex-direction:column;justify-content:center;gap:6px;padding-top:18px">
                    <label class="check-row">
                        <input type="checkbox" name="is_enabled" value="1" <?= (!$editMessage || !empty($editMessage['is_enabled'])) ? 'checked' : '' ?>>
                        <span>فعال</span>
                    </label>
                    <label class="check-row">
                        <input type="checkbox" name="is_default" value="1" <?= !empty($editMessage['is_default']) ? 'checked' : '' ?>>
                        <span>قالب پیش‌فرض</span>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn"><?= $editMessage ? '💾 ذخیره تغییرات قالب' : '➕ ذخیره قالب پیام' ?></button>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2 class="card-title">📝 قالب‌های پیام دعوت (<?= fa_digits(count($messages)) ?>)</h2></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
            <?php foreach ($messages as $m): ?>
                <div style="border:1px solid var(--border);border-radius:13px;padding:12px 14px;background:var(--surface-2)">
                    <div class="flex justify-between items-center mb-1" style="gap:8px;flex-wrap:wrap">
                        <div class="flex items-center gap-1">
                            <strong><?= e($m['title']) ?></strong>
                            <?php if (!empty($m['is_default'])): ?><span class="badge badge-brand no-dot">⭐ پیش‌فرض</span><?php endif; ?>
                            <span class="badge <?= !empty($m['is_enabled']) ? 'badge-success' : 'badge-muted' ?> no-dot">
                                <?= !empty($m['is_enabled']) ? 'فعال' : 'غیرفعال' ?>
                            </span>
                        </div>
                        <span class="text-xs text-muted">استفاده: <?= fa_digits((int) ($m['share_count'] ?? 0)) ?> بار</span>
                    </div>
                    <div class="text-xs" style="white-space:pre-wrap;line-height:1.85;padding:8px 10px;border-radius:9px;background:var(--surface);margin:8px 0"><?= e($m['preview']) ?></div>
                    <div class="flex gap-1" style="flex-wrap:wrap">
                        <a href="<?= url('admin/invite-content?edit_message=' . (int) $m['id'] . '#messages') ?>" class="btn btn-secondary btn-sm">✏️ ویرایش</a>
                        <form method="post" action="<?= url('admin/invite-content/messages/' . (int) $m['id'] . '/toggle') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-ghost btn-sm"><?= !empty($m['is_enabled']) ? 'غیرفعال' : 'فعال' ?></button>
                        </form>
                        <?php if (empty($m['is_default']) && !empty($m['is_enabled'])): ?>
                            <form method="post" action="<?= url('admin/invite-content/messages/' . (int) $m['id'] . '/default') ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-ghost btn-sm">⭐ پیش‌فرض</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= url('admin/invite-content/messages/' . (int) $m['id'] . '/delete') ?>"
                              onsubmit="return confirm('این قالب پیام دعوت حذف شود؟');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-danger btn-sm">🗑 حذف</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
const RB_SAMPLE_VARS = {
  '{invite_link}': <?= json_encode($sampleLink, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
  '{referral_code}': 'INV8X2K9',
  '{user_name}': 'سارا محمدی',
  '{app_name}': <?= json_encode($appName, JSON_UNESCAPED_UNICODE) ?>,
  '{inviter_reward}': <?= json_encode($inviterRewardFa, JSON_UNESCAPED_UNICODE) ?>,
  '{invitee_reward}': <?= json_encode($inviteeRewardFa, JSON_UNESCAPED_UNICODE) ?>,
  '{platform_name}': 'تلگرام'
};
function rbInsertVar(id, token) {
  const ta = document.getElementById(id);
  if (!ta) return;
  const s = ta.selectionStart || ta.value.length;
  const e = ta.selectionEnd || ta.value.length;
  ta.value = ta.value.slice(0, s) + token + ta.value.slice(e);
  ta.focus();
  ta.selectionStart = ta.selectionEnd = s + token.length;
}
function rbUpdateMsgPreview() {
  const ta = document.getElementById('msg-body-input');
  const box = document.getElementById('msg-live-preview');
  if (!ta || !box) return;
  let txt = ta.value || '';
  Object.keys(RB_SAMPLE_VARS).forEach(function (k) {
    txt = txt.split(k).join(RB_SAMPLE_VARS[k]);
  });
  const lnk = RB_SAMPLE_VARS['{invite_link}'];
  if (txt.indexOf(lnk) === -1) {
    txt = txt.trim() + '\n\n🔗 لینک دعوت من:\n' + lnk;
  }
  box.textContent = txt;
}
document.addEventListener('DOMContentLoaded', rbUpdateMsgPreview);
</script>

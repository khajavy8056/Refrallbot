<?php
use RB\Messengers\MessengerCapabilities;
use RB\Services\MembershipService;

$activeConns = $activeConnections ?? [];
$activeKeys = $activePlatformKeys ?? array_keys($activeConns);
$grouped = $groupedChannels ?? [];
$counts = $memberCounts ?? [];
$hasMultipleActive = count($activeKeys) > 1;
?>
<div class="flex justify-between items-center mb-3 wrap gap-2">
    <div>
        <h1 class="mb-0">کانال‌های اجباری</h1>
        <div class="text-sm text-muted">مدیریت تفکیک‌شده کانال‌های الزامی ورود و فعال‌سازی پاداش برای پیام‌رسان‌های فعال و متصل</div>
    </div>
    <div class="flex gap-1 wrap">
        <?php if ($activeKeys !== []): ?>
            <a href="<?= url('admin/channels?check=1') ?>" class="btn btn-secondary btn-sm">🔍 بررسی سلامت دسترسی ربات‌ها</a>
        <?php endif; ?>
        <a href="<?= url('admin/messengers') ?>" class="btn btn-ghost btn-sm">📡 مدیریت پیام‌رسان‌ها</a>
    </div>
</div>

<!-- آمار کلی + تفکیک پیام‌رسان‌های فعال و متصل (بدون نمایش پیام‌رسان غیرفعال) -->
<div class="stats-grid mb-3">
    <div class="stat">
        <div class="stat-head"><div class="stat-icon">📢</div><div class="stat-label">کل کانال‌های فعال سیستم</div></div>
        <div class="stat-value"><?= fa_digits((int) ($totalActiveChannels ?? 0)) ?></div>
        <div class="stat-foot">مجموع عضویت‌های ثبت‌شده: <strong><?= fa_digits(number_format((int) ($totalVerifiedMembers ?? 0))) ?></strong></div>
    </div>
    <?php foreach ($activeConns as $plat => $conn):
        $pChannels = $grouped[$plat] ?? [];
        $pActiveCount = count(array_filter($pChannels, static fn(array $c): bool => !empty($c['is_active'])));
        $pMembers = 0;
        $pCross = 0;
        foreach ($pChannels as $pc) {
            $mc = $counts[(int) $pc['id']] ?? ['total' => 0, 'api_cross' => 0];
            $pMembers += (int) $mc['total'];
            $pCross += (int) $mc['api_cross'];
        }
    ?>
        <div class="stat info">
            <div class="stat-head">
                <div class="stat-icon"><?= e(MessengerCapabilities::icon($plat)) ?></div>
                <div class="stat-label">کانال‌های <?= e(MessengerCapabilities::label($plat)) ?></div>
            </div>
            <div class="stat-value"><?= fa_digits($pActiveCount) ?><small> کانال فعال</small></div>
            <div class="stat-foot">
                عضویت تأییدشده: <strong><?= fa_digits(number_format($pMembers)) ?></strong>
                <?php if ($pCross > 0): ?>
                    · میان‌پیام‌رسانی: <?= fa_digits(number_format($pCross)) ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- تنظیمات عمومی دروازه ورود -->
<div class="card mb-3">
    <div class="card-header"><h2 class="card-title">⚙️ تنظیمات دروازه ورود و شرط عضویت</h2></div>
    <form method="post" action="<?= url('admin/channels/settings') ?>" class="card-body">
        <?= csrf_field() ?>
        <div class="form-row">
            <label class="form-check">
                <input type="checkbox" name="force_channel_membership" value="1" <?= !empty($enforcement) ? 'checked' : '' ?>>
                <div>
                    <strong>اجباری بودن عضویت در کانال‌ها</strong>
                    <div class="text-xs text-muted">تا زمان تکمیل عضویت در تمام کانال‌های الزامی تعیین‌شده، ادامه کار در ربات و آزادسازی پاداش رفرال متوقف می‌ماند.</div>
                </div>
            </label>
            <label class="form-check">
                <input type="checkbox" name="contact_required" value="1" <?= !empty($contactRequired) ? 'checked' : '' ?>>
                <div>
                    <strong>دریافت اجباری شماره تماس کاربر</strong>
                    <div class="text-xs text-muted">در پیام‌رسان‌های پشتیبان دکمه اشتراک شماره تماس، ثبت شماره خود کاربر پیش از ورود الزامی می‌شود.</div>
                </div>
            </label>
        </div>
        <button class="btn btn-sm mt-2" type="submit">💾 ذخیره تنظیمات دروازه</button>
    </form>
</div>

<?php if ($activeKeys === []): ?>
    <div class="card">
        <div class="card-body empty-state">
            <div class="empty-icon">📡</div>
            <div class="empty-title">هنوز هیچ پیام‌رسانی فعال و متصل نشده است</div>
            <div class="empty-desc">
                تنظیمات و بخش‌های کانال اجباری هر پیام‌رسان فقط پس از فعال‌سازی و اتصال موفق همان پیام‌رسان نمایش داده می‌شود.
            </div>
            <a href="<?= url('admin/messengers') ?>" class="btn btn-sm mt-2">اتصال پیام‌رسان در بخش پیام‌رسان‌ها</a>
        </div>
    </div>
<?php else: ?>

    <?php if (!empty($health)): ?>
        <div class="card mb-3">
            <div class="card-header"><h2 class="card-title">🩺 نتیجه بررسی سلامت دسترسی ربات‌ها به کانال‌ها</h2></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>پیام‌رسان</th><th>عنوان کانال</th><th>وضعیت دسترسی</th><th>توضیحات فنی</th></tr></thead>
                    <tbody>
                    <?php foreach ($health as $row):
                        $rp = strtolower((string) ($row['platform'] ?? 'telegram'));
                    ?>
                        <tr>
                            <td data-label="پیام‌رسان"><span class="badge badge-brand no-dot"><?= e(MessengerCapabilities::badge($rp)) ?></span></td>
                            <td data-label="کانال"><strong><?= e((string) $row['title']) ?></strong></td>
                            <td data-label="وضعیت"><span class="badge <?= !empty($row['ok']) ? 'badge-success' : 'badge-danger' ?>"><?= !empty($row['ok']) ? 'آماده و سالم' : 'نیازمند بررسی' ?></span></td>
                            <td data-label="توضیحات" class="text-sm"><?= e((string) $row['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- فرم افزودن / ویرایش کانال (محدود به پیام‌رسان‌های فعال و متصل) -->
    <div class="card mb-3" id="channel-form-card">
        <div class="card-header flex justify-between items-center wrap gap-1">
            <h2 class="card-title" id="channel-form-title">➕ افزودن کانال اجباری جدید</h2>
            <button type="button" class="btn btn-ghost btn-sm hidden" id="channel-cancel-edit">انصراف از ویرایش</button>
        </div>
        <form method="post" action="<?= url('admin/channels/save') ?>" class="card-body" id="channel-form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="ch-id" value="0">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="ch-platform">پیام‌رسان میزبان کانال</label>
                    <?php if (count($activeKeys) === 1):
                        $onlyPlat = $activeKeys[0];
                    ?>
                        <input type="hidden" name="platform" id="ch-platform" value="<?= e($onlyPlat) ?>">
                        <div class="form-control" style="background:var(--bg-muted);font-weight:600">
                            <?= e(MessengerCapabilities::badge($onlyPlat)) ?>
                            <?php if (!empty($activeConns[$onlyPlat]['bot_username'])): ?>
                                <span class="text-muted text-xs ltr">(@<?= e((string) $activeConns[$onlyPlat]['bot_username']) ?>)</span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <select name="platform" class="form-select" id="ch-platform">
                            <?php foreach ($activeConns as $plat => $conn): ?>
                                <option value="<?= e($plat) ?>"
                                        data-can-api="<?= MessengerCapabilities::supports($plat, 'channel_check_api') ? '1' : '0' ?>"
                                        data-can-cross="<?= MessengerCapabilities::canVerifyCrossMessengerChannel($plat) ? '1' : '0' ?>">
                                    <?= e(MessengerCapabilities::badge($plat)) ?>
                                    <?= MessengerCapabilities::supports($plat, 'channel_check_api') ? '— بررسی رسمی API (getChatMember)' : '— لینک عضویت + تأیید کاربری' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="ch-title">عنوان نمایشی کانال</label>
                    <input type="text" name="title" id="ch-title" class="form-control" required maxlength="150" placeholder="مثلاً: کانال رسمی اطلاع‌رسانی">
                </div>

                <div class="form-group">
                    <label class="form-label" for="ch-chat-id">شناسه یا یوزرنیم کانال</label>
                    <input type="text" name="chat_id" id="ch-chat-id" class="form-control ltr" dir="ltr" placeholder="@mychannel یا -1001234567890">
                    <div class="form-hint" id="ch-chat-hint">برای بررسی خودکار API، ربات همان پیام‌رسان باید در کانال ادمین باشد.</div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="ch-invite">لینک عضویت کانال (HTTPS)</label>
                    <input type="url" name="invite_link" id="ch-invite" class="form-control ltr" dir="ltr" placeholder="https://t.me/mychannel">
                    <div class="form-hint">برای کانال‌های خصوصی الزامی است؛ برای کانال‌های عمومی از روی @username خودکار ساخته می‌شود.</div>
                </div>

                <div class="form-group" id="ch-mode-group">
                    <label class="form-label" for="ch-mode">روش راستی‌آزمایی عضویت</label>
                    <select name="check_mode" id="ch-mode" class="form-select">
                        <option value="api">بررسی خودکار و واقعی با API رسمی (getChatMember)</option>
                        <option value="self_confirm">نمایش لینک عضویت + دکمه «عضو شدم» (فقط بومی)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="ch-order">ترتیب نمایش</label>
                    <input type="number" name="sort_order" id="ch-order" class="form-control ltr" value="0" min="0" max="999">
                </div>
            </div>

            <?php if ($hasMultipleActive): ?>
                <!-- بخش هدف‌گذاری شرطی و میان‌پیام‌رسانی (فقط وقتی بیش از یک پیام‌رسان فعال و متصل است) -->
                <div class="card mb-2" style="background:var(--bg-muted);border-style:dashed" id="ch-cross-box">
                    <div class="card-body" style="padding:12px 14px">
                        <div class="font-bold text-sm mb-1">🔗 عضویت در این کانال برای کاربران ورودی از کدام پیام‌رسان‌ها الزامی باشد؟</div>
                        <div id="ch-targets-wrap" class="flex gap-3 wrap mb-2">
                            <?php foreach ($activeConns as $tPlat => $tConn): ?>
                                <label class="form-check" style="margin:0">
                                    <input type="checkbox" name="target_platforms[]" value="<?= e($tPlat) ?>" class="ch-target-cb" data-plat="<?= e($tPlat) ?>">
                                    <span><strong><?= e(MessengerCapabilities::icon($tPlat) . ' ' . MessengerCapabilities::usersLabel($tPlat)) ?></strong></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-group mb-1" id="ch-cross-note-wrap">
                            <label class="form-label text-xs" for="ch-cross-note">توضیح راهنمای اختیاری برای کاربران سایر پیام‌رسان‌ها</label>
                            <input type="text" name="cross_rule_note" id="ch-cross-note" class="form-control" maxlength="250"
                                   placeholder="مثلاً: برای فعال‌سازی حساب و دریافت پاداش، عضویت در کانال رسمی تلگرام نیز الزامی است.">
                        </div>
                        <div class="text-xs text-muted" id="ch-api-note"></div>
                    </div>
                </div>
            <?php else: ?>
                <div class="text-xs text-muted mb-2">
                    ℹ️ <?= e(MessengerCapabilities::crossChannelNote($activeKeys[0])) ?>
                </div>
            <?php endif; ?>

            <div class="flex justify-between items-center wrap gap-2 mt-2">
                <label class="form-check" style="margin:0">
                    <input type="checkbox" name="is_active" id="ch-active" value="1" checked>
                    <span><strong>کانال فعال باشد</strong></span>
                </label>
                <button class="btn" type="submit" id="ch-submit-btn">➕ ثبت کانال اجباری</button>
            </div>
        </form>
    </div>

    <!-- بخش‌های کاملاً مستقل برای هر پیام‌رسان فعال و متصل -->
    <?php foreach ($activeConns as $plat => $conn):
        $pChannels = $grouped[$plat] ?? [];
        $supportsApi = MessengerCapabilities::supports($plat, 'channel_check_api');
    ?>
        <div class="card mb-3">
            <div class="card-header flex justify-between items-center wrap gap-2">
                <div class="flex items-center gap-2 wrap">
                    <h2 class="card-title mb-0">
                        <?= e(MessengerCapabilities::icon($plat)) ?> کانال‌های اجباری <?= e(MessengerCapabilities::label($plat)) ?>
                    </h2>
                    <?php if (!empty($conn['bot_username'])): ?>
                        <span class="badge badge-success no-dot ltr">@<?= e((string) $conn['bot_username']) ?></span>
                    <?php endif; ?>
                    <span class="badge <?= $supportsApi ? 'badge-info' : 'badge-warning' ?> no-dot">
                        <?= $supportsApi ? '✅ پشتیبانی رسمی getChatMember' : '⚠️ فاقد getChatMember رسمی (لینک + تأیید کاربری)' ?>
                    </span>
                </div>
                <span class="text-xs text-muted"><?= fa_digits(count($pChannels)) ?> کانال ثبت‌شده</span>
            </div>

            <?php if ($pChannels === []): ?>
                <div class="card-body text-sm text-muted">
                    هنوز کانال اجباری برای <?= e(MessengerCapabilities::badge($plat)) ?> ثبت نشده است. از فرم بالا می‌توانید کانال جدید اضافه کنید.
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>عنوان کانال</th>
                            <th>شناسه / لینک</th>
                            <th>روش بررسی</th>
                            <th>الزامی برای ورودی‌های</th>
                            <th>آمار عضویت</th>
                            <th>وضعیت</th>
                            <th>عملیات</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pChannels as $ch):
                            $cid = (int) $ch['id'];
                            $cm = (string) ($ch['check_mode'] ?? ($supportsApi ? 'api' : 'self_confirm'));
                            $isApi = ($supportsApi && $cm === 'api');
                            $targets = MembershipService::resolveTargetPlatforms($ch);
                            // فقط پیام‌رسان‌های فعال و متصل فعلی را نمایش بده
                            $visibleTargets = array_values(array_filter($targets, static fn(string $tp): bool => in_array($tp, $activeKeys, true)));
                            if ($visibleTargets === []) {
                                $visibleTargets = [$plat];
                            }
                            $mc = $counts[$cid] ?? ['total' => 0, 'api_direct' => 0, 'api_cross' => 0, 'self_declared' => 0];
                            $joinUrl = MembershipService::channelUrl($ch);
                            $editPayload = [
                                'id' => $cid,
                                'platform' => $plat,
                                'title' => (string) $ch['title'],
                                'chat_id' => (string) $ch['chat_id'],
                                'invite_link' => (string) ($ch['invite_link'] ?? ''),
                                'check_mode' => $isApi ? 'api' : 'self_confirm',
                                'sort_order' => (int) ($ch['sort_order'] ?? 0),
                                'is_active' => !empty($ch['is_active']) ? 1 : 0,
                                'targets' => $visibleTargets,
                                'cross_rule_note' => (string) ($ch['cross_rule_note'] ?? ''),
                            ];
                        ?>
                            <tr>
                                <td data-label="عنوان">
                                    <strong><?= e((string) $ch['title']) ?></strong>
                                    <?php if (!empty($ch['cross_rule_note'])): ?>
                                        <div class="text-xs text-muted"><?= e((string) $ch['cross_rule_note']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="شناسه / لینک" class="ltr text-sm">
                                    <code><?= e((string) $ch['chat_id']) ?></code>
                                    <?php if ($joinUrl): ?>
                                        <div><a href="<?= e($joinUrl) ?>" target="_blank" rel="noopener" class="text-xs">مشاهده لینک ↗</a></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="روش بررسی">
                                    <span class="badge <?= $isApi ? 'badge-success' : 'badge-warning' ?> no-dot">
                                        <?= $isApi ? 'API رسمی (getChatMember)' : 'لینک + تأیید کاربری' ?>
                                    </span>
                                </td>
                                <td data-label="الزامی برای">
                                    <div class="flex gap-1 wrap">
                                        <?php foreach ($visibleTargets as $tp): ?>
                                            <span class="badge <?= $tp === $plat ? 'badge-brand' : 'badge-info' ?> no-dot">
                                                <?= e(MessengerCapabilities::icon($tp) . ' ' . MessengerCapabilities::usersLabel($tp)) ?>
                                                <?= $tp !== $plat ? ' (میان‌پیام‌رسانی)' : '' ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td data-label="آمار عضویت" class="text-sm">
                                    <strong><?= fa_digits(number_format((int) $mc['total'])) ?></strong> نفر
                                    <?php if ((int) $mc['api_cross'] > 0): ?>
                                        <div class="text-xs text-muted">
                                            مستقیم: <?= fa_digits((int) $mc['api_direct']) ?> · میان‌پیام‌رسانی: <?= fa_digits((int) $mc['api_cross']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="وضعیت">
                                    <span class="badge <?= !empty($ch['is_active']) ? 'badge-success' : 'badge-muted' ?>">
                                        <?= !empty($ch['is_active']) ? 'فعال' : 'غیرفعال' ?>
                                    </span>
                                </td>
                                <td data-label="عملیات">
                                    <div class="flex gap-1 wrap">
                                        <button type="button" class="btn btn-secondary btn-sm js-edit-channel"
                                                data-channel="<?= e((string) json_encode($editPayload, JSON_UNESCAPED_UNICODE)) ?>">ویرایش</button>
                                        <form method="post" action="<?= url('admin/channels/' . $cid . '/delete') ?>"
                                              onsubmit="return confirm('آیا از حذف کانال «<?= e((string) $ch['title']) ?>» مطمئن هستید؟');">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <script>
    (function () {
        const notes = <?= json_encode(array_combine(
            $activeKeys,
            array_map(static fn(string $p): string => MessengerCapabilities::crossChannelNote($p), $activeKeys)
        ), JSON_UNESCAPED_UNICODE) ?>;
        const apiSupport = <?= json_encode(array_combine(
            $activeKeys,
            array_map(static fn(string $p): bool => MessengerCapabilities::supports($p, 'channel_check_api'), $activeKeys)
        )) ?>;
        const crossSupport = <?= json_encode(array_combine(
            $activeKeys,
            array_map(static fn(string $p): bool => MessengerCapabilities::canVerifyCrossMessengerChannel($p), $activeKeys)
        )) ?>;

        const platEl = document.getElementById('ch-platform');
        const modeEl = document.getElementById('ch-mode');
        const modeGroup = document.getElementById('ch-mode-group');
        const noteEl = document.getElementById('ch-api-note');
        const crossNoteWrap = document.getElementById('ch-cross-note-wrap');
        const targetCbs = Array.from(document.querySelectorAll('.ch-target-cb'));
        const idEl = document.getElementById('ch-id');
        const titleHeading = document.getElementById('channel-form-title');
        const submitBtn = document.getElementById('ch-submit-btn');
        const cancelBtn = document.getElementById('channel-cancel-edit');

        function syncPlatformUi(preserveTargets) {
            if (!platEl) return;
            const p = platEl.value;
            const canApi = !!apiSupport[p];
            const canCross = !!crossSupport[p] && (!modeEl || modeEl.value === 'api');

            if (modeEl && modeGroup) {
                if (!canApi) {
                    modeEl.value = 'self_confirm';
                    modeGroup.style.opacity = '0.65';
                    modeEl.querySelector('option[value="api"]').disabled = true;
                } else {
                    modeGroup.style.opacity = '1';
                    modeEl.querySelector('option[value="api"]').disabled = false;
                    if (!preserveTargets && idEl.value === '0') modeEl.value = 'api';
                }
            }

            const effectiveCross = !!crossSupport[p] && (!modeEl || modeEl.value === 'api');
            targetCbs.forEach(function (cb) {
                const isSame = (cb.dataset.plat === p);
                if (!effectiveCross) {
                    cb.checked = isSame;
                    cb.disabled = !isSame;
                } else {
                    cb.disabled = false;
                    if (!preserveTargets && idEl.value === '0') {
                        cb.checked = isSame;
                    }
                }
            });

            if (crossNoteWrap) {
                const anyCrossChecked = targetCbs.some(cb => cb.checked && cb.dataset.plat !== p);
                crossNoteWrap.style.display = (effectiveCross && anyCrossChecked) ? '' : 'none';
            }
            if (noteEl) {
                noteEl.textContent = 'ℹ️ ' + (notes[p] || '');
            }
        }

        if (platEl) platEl.addEventListener('change', function () { syncPlatformUi(false); });
        if (modeEl) modeEl.addEventListener('change', function () { syncPlatformUi(true); });
        targetCbs.forEach(cb => cb.addEventListener('change', function () { syncPlatformUi(true); }));

        document.querySelectorAll('.js-edit-channel').forEach(function (btn) {
            btn.addEventListener('click', function () {
                try {
                    const d = JSON.parse(btn.dataset.channel || '{}');
                    idEl.value = String(d.id || 0);
                    if (platEl) platEl.value = d.platform || platEl.value;
                    document.getElementById('ch-title').value = d.title || '';
                    document.getElementById('ch-chat-id').value = d.chat_id || '';
                    document.getElementById('ch-invite').value = d.invite_link || '';
                    if (modeEl) modeEl.value = d.check_mode || 'api';
                    document.getElementById('ch-order').value = String(d.sort_order || 0);
                    document.getElementById('ch-active').checked = !!d.is_active;
                    const cn = document.getElementById('ch-cross-note');
                    if (cn) cn.value = d.cross_rule_note || '';
                    const targets = Array.isArray(d.targets) ? d.targets : [d.platform];
                    targetCbs.forEach(cb => { cb.checked = targets.includes(cb.dataset.plat); });
                    titleHeading.textContent = '✏️ ویرایش کانال «' + (d.title || '') + '»';
                    submitBtn.textContent = '💾 ذخیره تغییرات کانال';
                    if (cancelBtn) cancelBtn.classList.remove('hidden');
                    syncPlatformUi(true);
                    document.getElementById('channel-form-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
                } catch (e) {}
            });
        });

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                document.getElementById('channel-form').reset();
                idEl.value = '0';
                titleHeading.textContent = '➕ افزودن کانال اجباری جدید';
                submitBtn.textContent = '➕ ثبت کانال اجباری';
                cancelBtn.classList.add('hidden');
                syncPlatformUi(false);
            });
        }

        syncPlatformUi(false);
    })();
    </script>
<?php endif; ?>

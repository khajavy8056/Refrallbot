<?php
use RB\Messengers\MessengerCapabilities;
/** @var array<string,array> $connections */
/** @var array<string,string> $webhookUrls */
/** @var string $selected */
/** @var array<string,array> $perfByPlatform */
/** @var string $uiMode */
/** @var array<string,array> $platformUiModes */
/** @var string $miniappUrl */
/** @var bool $hasHttps */
/** @var string $cronToken */
/** @var array<string,array> $capabilityMatrix */

$uiLabels = [
    'miniapp' => 'فقط مینی‌اپ',
    'buttons' => 'فقط دکمه‌های ربات',
    'hybrid'  => 'ترکیبی (مینی‌اپ + دکمه‌ها)',
];
$conn = $connections[$selected];
$meta = $conn['meta'] ?? [];
$caps = $meta['capabilities'] ?? ($meta['supports'] ?? []);
$extra = [];
if (!empty($conn['extra_config'])) {
    $dec = is_array($conn['extra_config']) ? $conn['extra_config'] : json_decode((string) $conn['extra_config'], true);
    if (is_array($dec)) {
        $extra = $dec;
    }
}
$resolvedUi = $platformUiModes[$selected] ?? ['effective' => $conn['ui_mode'] ?? 'buttons', 'note' => ''];
$cronUrl = rtrim(\RB\Support\Request::origin(), '/') . url('cron/run') . '?token=' . rawurlencode($cronToken) . '&task=messengers';
?>
<div class="page-head">
    <div>
        <h1 class="page-title">📡 مدیریت یکپارچه پیام‌رسان‌ها</h1>
        <p class="page-sub">اتصال مستقل و هم‌زمان ربات به تلگرام، بله، روبیکا و ایتا با کیف پول، شبکه زیرمجموعه و موتور پورسانت واحد</p>
    </div>
    <div class="flex gap-1" style="flex-wrap:wrap">
        <a href="<?= url('admin/settings#ui-mode') ?>" class="btn btn-secondary btn-sm">🎛 حالت رابط کاربری: <?= e($uiLabels[$uiMode] ?? $uiMode) ?></a>
        <a href="<?= url('admin/invite-content') ?>" class="btn btn-sm">🎨 محتوای دعوت و بنر</a>
    </div>
</div>

<!-- کارت‌های انتخاب و وضعیت ۴ پیام‌رسان -->
<div class="kpi-grid mb-3">
    <?php foreach ($connections as $p => $item): ?>
        <?php
        $badge = $item['status_badge'] ?? ['label' => 'نامشخص', 'class' => 'badge-muted'];
        $perf = $perfByPlatform[$p] ?? [];
        $isCurrent = ($p === $selected);
        $isActConn = !empty($item['is_active_connected']);
        $pUi = (string) ($platformUiModes[$p]['effective'] ?? ($item['ui_mode']['effective'] ?? 'buttons'));
        ?>
        <a href="<?= url('admin/messengers?tab=' . $p) ?>" class="card" style="text-decoration:none;color:inherit;border-width:2px;border-color:<?= $isCurrent ? 'var(--brand-500)' : 'var(--border)' ?>;transition:all .15s;opacity:<?= ($isActConn || $isCurrent) ? '1' : '.78' ?>">
            <div class="card-body" style="padding:14px 16px">
                <div class="flex justify-between items-center mb-1">
                    <strong style="font-size:1rem"><?= e(($item['meta']['icon'] ?? '🤖') . ' ' . ($item['meta']['name'] ?? $p)) ?></strong>
                    <span class="badge <?= e($badge['class']) ?>"><?= e($badge['label']) ?></span>
                </div>
                <?php if ($isActConn): ?>
                    <div class="text-xs text-muted ltr" style="margin-bottom:8px">
                        <?= !empty($item['bot_username']) ? '@' . e($item['bot_username']) : 'متصل و فعال' ?>
                    </div>
                    <div class="flex justify-between text-xs" style="border-top:1px solid var(--border);padding-top:8px">
                        <span><?= e($item['meta']['icon'] ?? '👥') ?> <?= e(MessengerCapabilities::usersLabel($p)) ?>: <strong><?= fa_digits((int) ($perf['independent_users'] ?? $perf['registered_users'] ?? 0)) ?></strong></span>
                        <span class="text-muted"><?= e($uiLabels[$pUi] ?? $pUi) ?></span>
                    </div>
                <?php else: ?>
                    <div class="text-xs text-muted" style="margin-top:8px;border-top:1px dashed var(--border);padding-top:8px">
                        غیرفعال — برای اتصال و نمایش تنظیمات انتخاب کنید
                    </div>
                <?php endif; ?>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<!-- پنل پیکربندی پیام‌رسان انتخاب‌شده -->
<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header justify-between">
            <div>
                <h2 class="card-title"><?= e(($meta['icon'] ?? '🤖') . ' پیکربندی ' . ($meta['name'] ?? $selected)) ?></h2>
                <span class="card-subtitle"><?= e($meta['summary'] ?? '') ?></span>
            </div>
            <form method="post" action="<?= url('admin/messengers/' . $selected . '/toggle') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="enabled" value="<?= $conn['is_enabled'] ? '0' : '1' ?>">
                <button type="submit" class="btn btn-sm <?= $conn['is_enabled'] ? 'btn-warning' : 'btn-success' ?>">
                    <?= $conn['is_enabled'] ? '⏸ غیرفعال‌سازی' : '▶️ فعال‌سازی' ?>
                </button>
            </form>
        </div>

        <form method="post" action="<?= url('admin/messengers/' . $selected . '/save') ?>" class="card-body">
            <?= csrf_field() ?>

            <?php if (!empty($conn['last_error'])): ?>
                <div class="alert alert-danger mb-2">
                    <span class="alert-icon">⚠️</span>
                    <div class="text-xs"><strong>آخرین خطای ثبت‌شده (ایزوله):</strong><br><?= e($conn['last_error']) ?></div>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label">
                    <?= $selected === 'eitaa' ? 'توکن برنامک ایتا (از developer.eitaa.com)' : 'توکن ربات (Bot Token)' ?>
                </label>
                <input type="password" name="bot_token" class="form-control ltr" autocomplete="new-password"
                       placeholder="<?= $conn['has_token'] ? ('توکن فعلی: ' . $conn['masked_token'] . ' (برای تغییر وارد کنید)') : '123456789:AAExxxxxxxxxxxxxxxx' ?>">
                <div class="form-hint">
                    <?php if ($conn['has_token']): ?>
                        ✅ توکن فعلی به‌صورت رمزنگاری‌شده (AES-256-GCM) ذخیره شده است: <code class="ltr"><?= e($conn['masked_token']) ?></code>. اگر خالی بگذارید، توکن فعلی حفظ می‌شود.
                    <?php else: ?>
                        <?php if ($selected === 'telegram'): ?>توکن را از <code>@BotFather</code> در تلگرام دریافت کنید.
                        <?php elseif ($selected === 'bale'): ?>توکن را از بازوی رسمی <code>@BotFather</code> در پیام‌رسان بله دریافت کنید.
                        <?php elseif ($selected === 'rubika'): ?>توکن را از ربات رسمی <code>@BotFather</code> در روبیکا دریافت کنید.
                        <?php elseif ($selected === 'eitaa'): ?>توکن برنامک (Mini App) را از پنل توسعه‌دهندگان <code>developer.eitaa.com</code> برای اعتبارسنجی HMAC امضای <code>initData</code> وارد کنید.
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">نام کاربری ربات / برنامک (بدون @)</label>
                    <input type="text" name="bot_username" class="form-control ltr" value="<?= e((string) ($conn['bot_username'] ?? '')) ?>"
                           placeholder="example_bot" <?= $selected === 'eitaa' ? 'required' : '' ?>>
                    <div class="form-hint">
                        <?= $selected === 'eitaa' ? 'برای ساخت لینک دعوت https://eitaa.com/bot/app?startapp=CODE الزامی است.' : 'در تلگرام، بله و روبیکا پس از تست اتصال به‌صورت خودکار از getMe دریافت می‌شود.' ?>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">نام نمایشی ربات</label>
                    <input type="text" name="bot_name" class="form-control" value="<?= e((string) ($conn['bot_name'] ?? '')) ?>" placeholder="رفرال‌بات">
                </div>
            </div>

            <?php if ($selected === 'eitaa'): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">شناسه کوتاه برنامک (App Short Name)</label>
                        <input type="text" name="app_slug" class="form-control ltr" value="<?= e((string) ($extra['app_slug'] ?? 'app')) ?>" placeholder="app">
                        <div class="form-hint">نام کوتاه برنامک در ایتا (برای لینک <code>eitaa.com/BOT/SLUG?startapp=CODE</code>).</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">توکن ایتایار برای ارسال به کانال (اختیاری)</label>
                        <input type="password" name="secondary_token" class="form-control ltr" autocomplete="new-password"
                               placeholder="<?= $conn['has_secondary_token'] ? ('فعلی: ' . $conn['masked_secondary_token']) : 'اختیاری — از eitaayar.ir' ?>">
                        <div class="form-hint">ایتایار رسمی فقط ارسال به کانال/گروه را پشتیبانی می‌کند (نه چت خصوصی کاربر).</div>
                    </div>
                </div>
                <div class="alert alert-info mb-2">
                    <span class="alert-icon">ℹ️</span>
                    <div class="text-xs" style="line-height:1.85">
                        <strong>شفافیت فنی ایتا:</strong> پلتفرم رسمی ایتا در حال حاضر از <b>برنامک (Mini App)</b> با SDK رسمی، احراز هویت امضاشده <code>initData</code>، دریافت شماره تماس (<code>requestContact</code>) و پارامتر دعوت <code>startapp</code> پشتیبانی کامل می‌کند؛ اما API عمومی ربات دوطرفه (Webhook گفتگو) ارائه نمی‌دهد. بنابراین کاربران ایتا تمام امکانات (استودیوی دعوت، شبکه، کیف پول و برداشت) را مستقیماً در مینی‌اپ با بهترین تجربه دریافت می‌کنند.
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="check-row">
                    <input type="checkbox" name="is_enabled" value="1" <?= $conn['is_enabled'] ? 'checked' : '' ?>>
                    <span>این پیام‌رسان فعال باشد (پاسخ‌گویی، ثبت‌نام و لینک دعوت فعال)</span>
                </label>
            </div>

            <?php if (!empty($conn['is_active_connected']) && MessengerCapabilities::supports($selected, 'polling')): ?>
                <div class="form-group">
                    <label class="check-row">
                        <input type="checkbox" name="polling_enabled" value="1" <?= $conn['polling_enabled'] ? 'checked' : '' ?>>
                        <span>فعال‌سازی دریافت آپدیت با Polling زمان‌بندی‌شده (جایگزین Webhook در هاست‌های محدود)</span>
                    </label>
                </div>
            <?php endif; ?>

            <div class="flex gap-1" style="flex-wrap:wrap">
                <button type="submit" class="btn">💾 <?= !empty($conn['is_active_connected']) ? 'ذخیره تنظیمات اتصال' : 'ذخیره توکن و برقراری اتصال' ?></button>
            </div>
        </form>

        <?php if ($conn['has_token']): ?>
            <div class="card-footer flex justify-between items-center" style="flex-wrap:wrap;gap:8px">
                <div class="flex gap-1" style="flex-wrap:wrap">
                    <form method="post" action="<?= url('admin/messengers/' . $selected . '/test') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-secondary btn-sm">🔍 تست اتصال</button>
                    </form>
                    <?php if (!empty($conn['is_active_connected'])): ?>
                        <?php if (MessengerCapabilities::supports($selected, 'menu_commands_api')): ?>
                            <form method="post" action="<?= url('admin/messengers/' . $selected . '/commands') ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-ghost btn-sm">📋 همگام‌سازی دستورات</button>
                            </form>
                        <?php endif; ?>
                        <a href="<?= url('admin/bot-profile?platform=' . $selected) ?>" class="btn btn-ghost btn-sm">🎨 پروفایل ربات</a>
                        <a href="<?= url('admin/channels') ?>" class="btn btn-ghost btn-sm">📢 کانال‌های اجباری</a>
                    <?php endif; ?>
                </div>
                <form method="post" action="<?= url('admin/messengers/' . $selected . '/disconnect') ?>"
                      onsubmit="return confirm('توکن این پیام‌رسان حذف و اتصال آن قطع شود؟ داده‌های کاربران و کیف پول کاملاً محفوظ می‌ماند.');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger btn-sm">🗑 حذف توکن</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <!-- ستون دوم: تنظیمات عملیاتی و آمار فقط زمانی نمایش داده می‌شود که پیام‌رسان فعال و با موفقیت متصل باشد -->
    <div style="display:flex;flex-direction:column;gap:14px">
        <?php if (empty($conn['is_active_connected'])): ?>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">📘 راهنمای فعال‌سازی و اتصال <?= e(($meta['icon'] ?? '') . ' ' . ($meta['name'] ?? '')) ?></h2>
                </div>
                <div class="card-body text-sm" style="line-height:2">
                    <div class="alert alert-info mb-2">
                        <span class="alert-icon">🔒</span>
                        <div class="text-xs">
                            این پیام‌رسان هنوز فعال و متصل نیست؛ به همین دلیل تمام تنظیمات اختصاصی، بخش کانال اجباری، گزینه‌های ارسال و آمار آن در کل پنل مدیریت مخفی نگه داشته شده‌اند و بلافاصله پس از اتصال موفق به‌صورت خودکار ظاهر می‌شوند.
                        </div>
                    </div>
                    <?php if (!empty($meta['guide_steps']) && is_array($meta['guide_steps'])): ?>
                        <ol style="padding-right:18px;margin:0">
                            <?php foreach ($meta['guide_steps'] as $step): ?>
                                <li class="text-xs mb-1"><?= e((string) $step) ?></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <?php if (MessengerCapabilities::supports($selected, 'incoming_webhook')): ?>
                <div class="card">
                    <div class="card-header justify-between">
                        <h2 class="card-title">🔗 تنظیم Webhook <?= e($meta['name'] ?? '') ?></h2>
                        <span class="badge <?= (($conn['webhook_status'] ?? '') === 'active') ? 'badge-success' : 'badge-muted' ?>">
                            <?= (($conn['webhook_status'] ?? '') === 'active') ? 'فعال' : 'تنظیم‌نشده' ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <form method="post" action="<?= url('admin/messengers/' . $selected . '/webhook/set') ?>">
                            <?= csrf_field() ?>
                            <div class="form-group">
                                <label class="form-label">آدرس اختصاصی Webhook (HTTPS)</label>
                                <div style="display:flex;gap:6px">
                                    <input type="url" name="webhook_url" class="form-control ltr" value="<?= e((string) ($conn['webhook_url'] ?: ($webhookUrls[$selected] ?? ''))) ?>">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="RB.copy(this.previousElementSibling.value)">📋 کپی</button>
                                </div>
                                <div class="form-hint">
                                    <?php if ($selected === 'telegram'): ?>
                                        همراه با هدر امنیتی <code>X-Telegram-Bot-Api-Secret-Token</code> ثبت می‌شود.
                                    <?php elseif ($selected === 'bale'): ?>
                                        در API رسمی بله (<code>tapi.bale.ai/bot.../setWebhook</code>) با مسیر رمزنگاری‌شده یکتا ثبت می‌شود.
                                    <?php elseif ($selected === 'rubika'): ?>
                                        در API رسمی روبیکا (<code>botapi.rubika.ir/v3/.../updateBotEndpoints</code>) هر دو مسیر <code>ReceiveUpdate</code> و <code>ReceiveInlineMessage</code> به‌صورت خودکار ثبت می‌شوند.
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex gap-1" style="flex-wrap:wrap">
                                <button type="submit" class="btn btn-sm">⚡ ثبت خودکار Webhook</button>
                            </div>
                        </form>
                        <?php if (($conn['webhook_status'] ?? '') === 'active'): ?>
                            <form method="post" action="<?= url('admin/messengers/' . $selected . '/webhook/delete') ?>" class="mt-2">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-ghost btn-sm">🔌 حذف Webhook</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (MessengerCapabilities::supports($selected, 'miniapp')): ?>
                <div class="card">
                    <div class="card-header justify-between">
                        <h2 class="card-title">📱 آدرس Mini App در <?= e($meta['name'] ?? '') ?></h2>
                        <span class="badge <?= $hasHttps ? 'badge-success' : 'badge-warning' ?>"><?= $hasHttps ? 'HTTPS آماده' : 'نیازمند HTTPS' ?></span>
                    </div>
                    <div class="card-body">
                        <div class="form-group mb-0">
                            <label class="form-label">آدرس ثبت در پنل پیام‌رسان</label>
                            <div style="display:flex;gap:6px">
                                <input type="text" readonly class="form-control ltr" value="<?= e((string) ($conn['suggested_miniapp_url'] ?? '')) ?>">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="RB.copy(this.previousElementSibling.value)">📋 کپی</button>
                            </div>
                            <div class="form-hint">
                                <?php if ($selected === 'eitaa'): ?>
                                    این آدرس را در پنل <code>developer.eitaa.com</code> به‌عنوان URL برنامک ثبت کنید.
                                <?php elseif ($selected === 'bale'): ?>
                                    این آدرس را در بازوی <code>@botfather</code> بله ثبت کنید؛ بازو دکمه <code>web_app</code> را خودکار نمایش می‌دهد.
                                <?php else: ?>
                                    این آدرس را در <code>@BotFather</code> تلگرام تنظیم کنید یا از همگام‌سازی منو استفاده نمایید.
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php $selPerf = $perfByPlatform[$selected] ?? []; ?>
            <div class="card">
                <div class="card-header justify-between">
                    <h2 class="card-title">📊 عملکرد زنده <?= e(MessengerCapabilities::badge($selected)) ?></h2>
                    <a href="<?= url('admin/reports/messengers') ?>" class="btn btn-ghost btn-sm">گزارش کامل ←</a>
                </div>
                <div class="card-body text-sm" style="line-height:2">
                    <div>🎛 <strong>حالت رابط کاربری مؤثر:</strong> <span class="badge badge-brand no-dot"><?= e($uiLabels[$resolvedUi['effective']] ?? (string) $resolvedUi['effective']) ?></span></div>
                    <?php if (!empty($resolvedUi['reason'])): ?>
                        <div class="text-xs text-muted mb-1"><?= e((string) $resolvedUi['reason']) ?></div>
                    <?php endif; ?>
                    <div><?= e($meta['icon'] ?? '👥') ?> <strong><?= e(MessengerCapabilities::usersLabel($selected)) ?>:</strong> <?= fa_digits((int) ($selPerf['independent_users'] ?? $selPerf['registered_users'] ?? 0)) ?> نفر (امروز: +<?= fa_digits((int) ($selPerf['today_users'] ?? 0)) ?>)</div>
                    <div>🕸 <strong>دعوت مستقیم / غیرمستقیم:</strong> <?= fa_digits((int) ($selPerf['direct_referrals'] ?? 0)) ?> مستقیم · <?= fa_digits((int) ($selPerf['indirect_referrals'] ?? 0)) ?> غیرمستقیم</div>
                    <div>💰 <strong>پاداش پرداخت‌شده:</strong> <?= e(wtoman((int) ($selPerf['total_paid'] ?? 0))) ?></div>
                    <div>📢 <strong>عضویت کانال‌ها:</strong> <?= fa_digits((int) ($selPerf['channel_members_total'] ?? 0)) ?> نفر در <?= fa_digits((int) ($selPerf['active_channels'] ?? 0)) ?> کانال فعال</div>
                    <div>✉️ <strong>پیام‌های ارسالی / ناموفق:</strong> <?= fa_digits((int) ($selPerf['messages_sent'] ?? 0)) ?> ارسالی · <?= fa_digits((int) ($selPerf['messages_failed'] ?? 0)) ?> ناموفق</div>
                    <div>⚠️ <strong>خطاهای ارتباطی ثبت‌شده:</strong> <?= fa_digits((int) ($selPerf['error_count'] ?? 0)) ?> مورد
                        <?php if ((int) ($selPerf['error_count'] ?? 0) > 0): ?>
                            <a href="<?= url('admin/reports/errors?platform=' . $selected) ?>" class="text-xs">(مشاهده جزئیات)</a>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($conn['last_update_at'])): ?>
                        <div>⏱ <strong>آخرین رویداد:</strong> <?= e(fa_date($conn['last_update_at'])) ?></div>
                    <?php endif; ?>
                    <?php if ($conn['polling_enabled']): ?>
                        <div class="mt-2 text-xs text-muted">
                            آدرس Cron پولینگ پیام‌رسان‌ها (هر ۱ دقیقه):
                            <div class="ltr mono" style="word-break:break-all;margin-top:4px"><?= e($cronUrl) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($recentErrors)): ?>
                <div class="card">
                    <div class="card-header justify-between">
                        <h3 class="card-title">🩺 آخرین خطاهای <?= e(MessengerCapabilities::badge($selected)) ?></h3>
                        <a href="<?= url('admin/reports/errors?platform=' . $selected) ?>" class="btn btn-ghost btn-sm">گزارش کامل ←</a>
                    </div>
                    <div class="card-body text-xs" style="line-height:1.8">
                        <?php foreach (array_slice($recentErrors, 0, 5) as $errRow): ?>
                            <div style="padding:6px 0;border-bottom:1px solid var(--border)">
                                <span class="badge badge-danger no-dot"><?= e(\RB\Messengers\MessengerRegistry::errorCategoryLabel((string) ($errRow['category'] ?? 'api'))) ?></span>
                                <?php if (!empty($errRow['method_name'])): ?><span class="mono ltr text-muted"><?= e((string) $errRow['method_name']) ?></span><?php endif; ?>
                                <span class="text-muted">· <?= e(fa_date($errRow['created_at'] ?? null)) ?></span>
                                <div class="mt-1"><?= e((string) ($errRow['message'] ?? '')) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- جدول شفاف مقایسه قابلیت‌های رسمی ۴ پیام‌رسان -->
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">📋 ماتریس شفاف قابلیت‌های رسمی پیام‌رسان‌ها</h2>
            <span class="card-subtitle">بر اساس مستندات رسمی API هر پیام‌رسان — سامانه به‌صورت خودکار بهترین جایگزین را در هر پلتفرم فعال می‌کند</span>
        </div>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>قابلیت</th>
                    <th>✈️ تلگرام</th>
                    <th>🟢 بله</th>
                    <th>🟣 روبیکا</th>
                    <th>🟠 ایتا</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>ربات تعاملی دوطرفه (Webhook / Polling)</td>
                    <td><span class="badge badge-success no-dot">کامل</span></td>
                    <td><span class="badge badge-success no-dot">کامل</span></td>
                    <td><span class="badge badge-success no-dot">کامل (Bot API v3)</span></td>
                    <td><span class="badge badge-muted no-dot">ندارد (مبتنی بر برنامک)</span></td>
                </tr>
                <tr>
                    <td>مینی‌اپ داخلی با احراز هویت امضاشده (HMAC)</td>
                    <td><span class="badge badge-success no-dot">کامل</span></td>
                    <td><span class="badge badge-success no-dot">کامل (Bale.WebApp)</span></td>
                    <td><span class="badge badge-warning no-dot">جایگزین دکمه‌ای کامل</span></td>
                    <td><span class="badge badge-success no-dot">کامل (Eitaa.WebApp)</span></td>
                </tr>
                <tr>
                    <td>استودیوی دعوت، قالب پیام و بنر + لینک اختصاصی</td>
                    <td><span class="badge badge-success no-dot">ربات + مینی‌اپ</span></td>
                    <td><span class="badge badge-success no-dot">ربات + مینی‌اپ</span></td>
                    <td><span class="badge badge-success no-dot">داخل ربات + بنر</span></td>
                    <td><span class="badge badge-success no-dot">داخل برنامک</span></td>
                </tr>
                <tr>
                    <td>لینک دعوت عمیق با ثبت خودکار معرف</td>
                    <td class="ltr text-xs">t.me/bot?start=CODE</td>
                    <td class="ltr text-xs">ble.ir/bot?start=CODE</td>
                    <td class="ltr text-xs">rubika.ir/bot?st=CODE</td>
                    <td class="ltr text-xs">eitaa.com/bot/app?startapp=CODE</td>
                </tr>
                <tr>
                    <td>بررسی عضویت در کانال اجباری (مستقیم و میان‌پیام‌رسانی)</td>
                    <td><span class="badge badge-success no-dot">رسمی API (getChatMember) + شرط میان‌پیام‌رسانی</span></td>
                    <td><span class="badge badge-success no-dot">رسمی API (getChatMember) + شرط میان‌پیام‌رسانی</span></td>
                    <td><span class="badge badge-warning no-dot">فاقد API بررسی (صرفاً لینک عضویت؛ میان‌پیام‌رسانی غیرفعال)</span></td>
                    <td><span class="badge badge-warning no-dot">فاقد API بررسی (صرفاً لینک عضویت؛ میان‌پیام‌رسانی غیرفعال)</span></td>
                </tr>
                <tr>
                    <td>دریافت شماره موبایل کاربر</td>
                    <td><span class="badge badge-success no-dot">کیبورد + مینی‌اپ</span></td>
                    <td><span class="badge badge-success no-dot">کیبورد + مینی‌اپ</span></td>
                    <td><span class="badge badge-success no-dot">کیبورد ربات</span></td>
                    <td><span class="badge badge-success no-dot">requestContact برنامک</span></td>
                </tr>
                <tr>
                    <td>تغییر نام، بیو و تصویر پروفایل ربات از API</td>
                    <td><span class="badge badge-success no-dot">کامل از پنل</span></td>
                    <td><span class="badge badge-muted no-dot">از طریق @BotFather بله</span></td>
                    <td><span class="badge badge-muted no-dot">از طریق @BotFather روبیکا</span></td>
                    <td><span class="badge badge-muted no-dot">از developer.eitaa.com</span></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

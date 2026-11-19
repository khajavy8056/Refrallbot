<?php
declare(strict_types=1);

namespace RB\Controllers\Admin;

use RB\Http\AdminController;
use RB\Messengers\MessengerCapabilities;
use RB\Services\CommissionSettings;
use RB\Services\InviteContentService;
use RB\Services\SettingsService;
use RB\Support\Database;
use RB\Support\Request;

/**
 * مدیریت محتوای دعوت و بنرهای تبلیغاتی (استودیوی دعوت در پنل مدیریت)
 * پشتیبانی از چندین قالب پیام فعال، بنرهای آماده و آپلودی، پیش‌نمایش زنده و تضمین صددرصدی لینک دعوت.
 */
final class InviteContentController extends AdminController
{
    public function index(): void
    {
        $db = Database::instance();
        $svc = new InviteContentService($db);
        $svc->ensureSeeded();

        $messages = $svc->listMessages(false);
        $banners = array_map(static function (array $b) use ($svc): array {
            $b['public_url'] = $svc->bannerPublicUrl($b, false);
            return $b;
        }, $svc->listBanners(false));

        $editMsgId = (int) ($_GET['edit_message'] ?? 0);
        $editBannerId = (int) ($_GET['edit_banner'] ?? 0);
        $editMessage = $editMsgId > 0 ? $svc->findMessage($editMsgId, false) : null;
        $editBanner = $editBannerId > 0 ? $svc->findBanner($editBannerId, false) : null;
        if ($editBanner !== null) {
            $editBanner['public_url'] = $svc->bannerPublicUrl($editBanner, false);
        }

        // ساخت کاربر نمونه برای پیش‌نمایش زنده متغیرها
        $comSettings = CommissionSettings::current($db);
        $sampleUser = [
            'id' => 1,
            'first_name' => 'سارا',
            'last_name' => 'محمدی',
            'referral_code' => 'INV8X2K9',
        ];
        $sampleLink = rtrim(Request::origin(), '/') . url('r/INV8X2K9');
        foreach ($messages as &$m) {
            $m['preview'] = $svc->renderMessage($m, $sampleUser, $sampleLink, MessengerCapabilities::TELEGRAM, false, $comSettings);
        }
        unset($m);

        $this->render('admin/invite_content/index', [
            'title' => 'محتوای دعوت و بنرهای تبلیغاتی',
            'messages' => $messages,
            'banners' => $banners,
            'editMessage' => $editMessage,
            'editBanner' => $editBanner,
            'appName' => (string) SettingsService::get('app_name', 'رفرال‌بات'),
            'inviterRewardFa' => wtoman($comSettings->effectiveDirectReward()),
            'inviteeRewardFa' => wtoman($comSettings->effectiveInviteeReward()),
            'sampleLink' => $sampleLink,
        ]);
    }

    public function saveMessage(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $data = [
            'title' => Request::string('title', ''),
            'body' => Request::string('body', ''),
            'sort_order' => (int) ($_POST['sort_order'] ?? 10),
            'is_enabled' => !empty($_POST['is_enabled']),
            'is_default' => !empty($_POST['is_default']),
        ];

        $this->respond(function () use ($id, $data) {
            $svc = new InviteContentService();
            if ($id > 0) {
                $svc->updateMessage($id, $data, current_admin_id());
                return ['id' => $id];
            }
            return ['id' => $svc->createMessage($data, current_admin_id())];
        }, $id > 0 ? 'قالب پیام دعوت ویرایش شد.' : 'قالب پیام دعوت جدید ذخیره شد.', 'admin/invite-content#messages');
    }

    public function toggleMessage(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $this->respond(function () use ($id) {
            $enabled = (new InviteContentService())->toggleMessage($id, current_admin_id());
            return ['enabled' => $enabled];
        }, 'وضعیت قالب پیام به‌روزرسانی شد.', 'admin/invite-content#messages');
    }

    public function defaultMessage(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $this->respond(function () use ($id) {
            (new InviteContentService())->setDefaultMessage($id, current_admin_id());
        }, 'قالب پیام پیش‌فرض تنظیم شد.', 'admin/invite-content#messages');
    }

    public function deleteMessage(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $this->respond(function () use ($id) {
            (new InviteContentService())->deleteMessage($id, current_admin_id());
        }, 'قالب پیام حذف شد.', 'admin/invite-content#messages');
    }

    public function saveBanner(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $data = [
            'title' => Request::string('title', ''),
            'caption' => Request::string('caption', ''),
            'sort_order' => (int) ($_POST['sort_order'] ?? 10),
            'is_enabled' => !empty($_POST['is_enabled']),
            'is_default' => !empty($_POST['is_default']),
        ];
        $file = $_FILES['image'] ?? null;
        $hasUpload = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        $this->respond(function () use ($id, $data, $file, $hasUpload) {
            $svc = new InviteContentService();
            if ($id > 0) {
                $svc->updateBanner($id, $data, $hasUpload ? $file : null, current_admin_id());
                return ['id' => $id];
            }
            if (!$hasUpload) {
                throw new \InvalidArgumentException('تصویر بنر را انتخاب کنید (فرمت JPG، PNG یا WebP تا ۵ مگابایت).');
            }
            return ['id' => $svc->createBanner($data, $file, current_admin_id())];
        }, $id > 0 ? 'بنر تبلیغاتی ویرایش شد.' : 'بنر تبلیغاتی جدید آپلود و ذخیره شد.', 'admin/invite-content#banners');
    }

    public function toggleBanner(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $this->respond(function () use ($id) {
            $enabled = (new InviteContentService())->toggleBanner($id, current_admin_id());
            return ['enabled' => $enabled];
        }, 'وضعیت بنر تبلیغاتی به‌روزرسانی شد.', 'admin/invite-content#banners');
    }

    public function defaultBanner(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $this->respond(function () use ($id) {
            (new InviteContentService())->setDefaultBanner($id, current_admin_id());
        }, 'بنر پیش‌فرض تنظیم شد.', 'admin/invite-content#banners');
    }

    public function deleteBanner(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $this->respond(function () use ($id) {
            (new InviteContentService())->deleteBanner($id, current_admin_id());
        }, 'بنر تبلیغاتی حذف شد.', 'admin/invite-content#banners');
    }
}

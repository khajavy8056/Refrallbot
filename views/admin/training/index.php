<div class="card mb-3">
    <div class="card-header"><h1 class="card-title">آموزش پنل</h1></div>
    <div class="card-body">
        <?php if ($localVideo): ?>
            <p>ویدیو از فایل ذخیره‌شده در هاست شما پخش می‌شود.</p>
            <video class="training-player" controls preload="metadata" playsinline src="<?= url('admin/training/video') ?>">مرورگر شما پخش ویدیو را پشتیبانی نمی‌کند.</video>
            <p><a class="btn btn-primary" href="<?= url('admin/training/video?download=1') ?>">⬇ دانلود ویدیو از هاست</a></p>
        <?php else: ?>
            <p>ویدیو هنوز روی هاست قرار نگرفته است. پخش زیر از Google Drive انجام می‌شود و به دسترسی مرورگر شما به فایل وابسته است.</p>
            <iframe class="training-player" src="<?= e($previewUrl) ?>" title="ویدیوی آموزش پنل" allow="autoplay; fullscreen" allowfullscreen loading="lazy" referrerpolicy="no-referrer"></iframe>
            <p><a class="btn btn-primary" href="<?= e($downloadUrl) ?>" target="_blank" rel="noopener noreferrer">⬇ دانلود ویدیو از Google Drive</a>
                <a class="btn btn-secondary" href="<?= e($viewUrl) ?>" target="_blank" rel="noopener noreferrer">باز کردن فایل در Drive</a></p>
            <p class="form-hint">اگر Drive درخواست ورود یا دسترسی داد، از حساب مجاز استفاده کنید یا فایل را طبق راهنمای زیر روی هاست بگذارید. ممکن است Google پیش از دانلود تأیید بخواهد.</p>
        <?php endif; ?>
    </div>
</div>
<?php if ($canUpload): ?>
<div class="card mb-3">
    <div class="card-header"><h2 class="card-title">انتقال ویدیو به هاست</h2></div>
    <div class="card-body">
        <p>فایل اصلی حدود ۲۶۵ مگابایت است و همراه آپدیت دانلود یا منتقل نمی‌شود. ابتدا آن را از Drive دانلود کنید؛ سپس یکی از روش‌های زیر را انجام دهید. پس از قرارگیری فایل معتبر، پخش و دانلود به‌طور خودکار از هاست خواهد بود.</p>
        <form method="post" action="<?= url('admin/training/upload') ?>" enctype="multipart/form-data" id="training-upload" data-upload-max="<?= e($uploadMax) ?>" data-post-max="<?= e($postMax) ?>">
            <?= csrf_field() ?>
            <label for="training-file">بارگذاری فایل MP4 (حداکثر ۵۰۰ MiB)</label>
            <input id="training-file" type="file" name="video" accept="video/mp4,.mp4" required>
            <button class="btn btn-primary" type="submit">بارگذاری روی هاست</button>
        </form>
        <p class="form-hint">محدودیت فعلی PHP: upload_max_filesize = <span dir="ltr"><?= e($uploadMax) ?></span>، post_max_size = <span dir="ltr"><?= e($postMax) ?></span>. هر دو باید از حجم فایل بزرگ‌تر باشند؛ محدودیت وب‌سرور، زمان اجرا و فضای خالی هاست هم ممکن است مانع آپلود شود. اگر آپلود انجام نشد از روش دستی استفاده کنید.</p>
        <p>روش دستی: در File Manager یا SFTP فایل را در ریشه نصب برنامه، داخل <code dir="ltr">storage/uploads/</code> با نام دقیق <code dir="ltr">panel-training.mp4</code> قرار دهید؛ در صورت نبود پوشه آن را بسازید. پوشه storage نباید عمومی باشد و فایل باید برای PHP خواندنی باشد. پس از پایان انتقال این صفحه را تازه‌سازی کنید.</p>
        <p class="form-hint">آپلود مجدد، فایل قبلی را پس از انتقال موفق جایگزین می‌کند. فایل در storage نگهداری می‌شود و جزو بسته آپدیت نیست.</p>
    </div>
</div>
<?php endif; ?>

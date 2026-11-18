#!/usr/bin/env php
<?php
/**
 * نمایش دستورهای Cron لازم برای این نصب
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$php = PHP_BINARY !== '' ? PHP_BINARY : '/usr/local/bin/php';

echo "دستورهای Cron پیشنهادی (در cPanel > Cron Jobs یا crontab -e):\n\n";
echo "# ارسال پیام همگانی — هر ۲ دقیقه\n";
echo "*/2 * * * * {$php} {$root}/bin/cron.php broadcast --quiet >/dev/null 2>&1\n\n";
echo "# وظایف اصلی (جوایز، نگهداری، امنیت، پشتیبان) — هر ۱۰ دقیقه\n";
echo "0,10,20,30,40,50 * * * * {$php} {$root}/bin/cron.php all --quiet >/dev/null 2>&1\n\n";
echo "مسیر ریشه پروژه: {$root}\n";

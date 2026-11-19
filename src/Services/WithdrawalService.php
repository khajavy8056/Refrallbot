<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Crypto;
use RB\Support\Database;
use RB\Support\Money;

/**
 * درخواست‌های برداشت: اعتبارسنجی، رزرو، تأیید، پرداخت، رد
 *
 * چرخه وضعیت:
 * new → reviewing → approved → paying → paid
 *   ↘ rejected / cancelled
 *
 * مبلغ در زمان ثبت رزرو می‌شود و تا زمان پرداخت از موجودی قابل برداشت خارج است.
 */
final class WithdrawalService
{
    public const STATUSES = [
        'new' => 'جدید',
        'reviewing' => 'در انتظار بررسی',
        'approved' => 'تأییدشده',
        'paying' => 'در حال پرداخت',
        'paid' => 'پرداخت‌شده',
        'rejected' => 'ردشده',
        'cancelled' => 'لغوشده',
    ];

    public const OPEN_STATUSES = ['new', 'reviewing', 'approved', 'paying'];

    public function __construct(private ?Database $db = null)
    {
        $this->db ??= Database::instance();
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUSES[$status] ?? $status;
    }

    /**
     * پاک‌سازی و یکسان‌سازی ورودی شماره کارت (تبدیل ارقام فارسی/عربی/تمام‌عرض و حذف کاراکترهای نامرئی BiDi/RTL)
     */
    public static function cleanCardInput(string $card): string
    {
        // حذف کاراکترهای کنترلی نامرئی یونیکد (LRM, RLM, ALM, ZWNJ, ZWSP, LRI/RLI/PDI, BOM, Soft Hyphen)
        $clean = preg_replace(
            '~[\x{00AD}\x{061C}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]+~u',
            '',
            $card
        ) ?? $card;

        // یکسان‌سازی انواع فاصله‌های یونیکد (NBSP, Thin Space, NNBSP و ...)
        $clean = preg_replace('~[\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]+~u', ' ', $clean) ?? $clean;

        // تبدیل مستقل ارقام فارسی، عربی و تمام‌عرض به انگلیسی
        $clean = str_replace(
            [
                '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹',
                '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩',
                '０', '１', '２', '３', '４', '５', '６', '７', '８', '９',
            ],
            [
                '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
                '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
                '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
            ],
            $clean
        );

        return trim($clean);
    }

    /**
     * الگوریتم استاندارد Luhn (ISO/IEC 7812-1 / شاپرک — Mod 10 از راست به چپ)
     */
    public static function passesLuhn(string $digits): bool
    {
        $len = strlen($digits);
        if ($len !== 16 || !ctype_digit($digits)) {
            return false;
        }
        $sum = 0;
        $shouldDouble = false;
        for ($i = $len - 1; $i >= 0; $i--) {
            $digit = ord($digits[$i]) - 48;
            if ($shouldDouble) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $shouldDouble = !$shouldDouble;
        }
        return ($sum % 10) === 0;
    }

    /** Accept any non-empty payment details; an administrator reviews them before payment. */
    public static function validateCard(string $card): array
    {
        if (trim($card) === '') {
            return ['valid' => false, 'error' => 'لطفاً شماره کارت خود را وارد کنید.', 'normalized' => ''];
        }
        $key = self::cardKey($card);
        return ['valid' => true, 'error' => null, 'normalized' => $card,
            'formatted' => $card, 'bank_name' => self::detectBank($key)];
    }

    /** Normalize conventional number separators only for masking, bank hints and reuse checks.
     * This never changes what the administrator sees and never rejects an input. */
    private static function cardKey(string $card): string
    {
        $clean = self::cleanCardInput($card);
        if (preg_match('~^[0-9\s\-‐‑‒–—―−﹣－ـ._/,،٬٫·:]+$~u', $clean)) {
            return preg_replace('~[^0-9]~', '', $clean) ?? $clean;
        }
        return $clean;
    }

    private const LONG_CARD_MARKER = 'RB:LONG-CARD-IN-DETAILS';

    /** نمایش خوانا و استاندارد شماره کارت ۱۶ رقمی: 6037-9975-1234-5670 */
    public static function formatCard(string $digits): string
    {
        $d = preg_replace('~[^0-9]~', '', self::cleanCardInput($digits)) ?? '';
        if (strlen($d) !== 16) {
            return $d;
        }
        return substr($d, 0, 4) . '-' . substr($d, 4, 4) . '-' . substr($d, 8, 4) . '-' . substr($d, 12, 4);
    }

    /** نمایش گروهی شماره کارت: 6037-****-****-1234 */
    public static function maskCard(?string $card): string
    {
        if ($card === null || $card === '') return '—';
        $key = self::cardKey($card);
        if (!ctype_digit($key) || strlen($key) < 8) return '••••';
        return substr($key, 0, 4) . '-****-****-' . substr($key, -4);
    }

    /**
     * ثبت درخواست برداشت
     * @throws \RuntimeException در صورت عدم احراز شرایط
     */
    public function create(int $userId, int $amount, string $cardNumber, string $cardHolder, ?string $ip = null): array
    {
        if (!SettingsService::bool('withdrawal_enabled', true)) {
            throw new \RuntimeException('امکان برداشت در حال حاضر غیرفعال است.');
        }
        $min = SettingsService::int('min_withdraw_amount', 30000);
        if ($amount <= 0) {
            throw new \RuntimeException('مبلغ درخواست نامعتبر است.');
        }
        if ($amount < $min) {
            throw new \RuntimeException('حداقل مبلغ برداشت ' . wtoman($min) . ' است.');
        }

        $card = self::validateCard($cardNumber);
        if (!$card['valid']) {
            throw new \RuntimeException((string) $card['error']);
        }
        $holder = trim($cardHolder);
        if (mb_strlen($holder) < 3) {
            throw new \RuntimeException('نام صاحب کارت نامعتبر است.');
        }

        $rawCard = $card['normalized'];
        $cardKey = self::cardKey($rawCard);
        $cardHash = hash('sha256', $cardKey);
        $encryptedCard = Crypto::encrypt($rawCard);
        // The original column is VARCHAR(255); long encrypted input is stored in a separate protected table.
        $longCard = strlen((string) $encryptedCard) > 255;
        $last4 = ctype_digit($cardKey) && strlen($cardKey) >= 4 ? substr($cardKey, -4) : null;
        return $this->db->transaction(function () use ($userId, $amount, $cardKey, $cardHash, $encryptedCard, $longCard, $last4, $holder, $ip, $min) {
            // قفل کاربر برای جلوگیری از درخواست هم‌زمان
            $user = $this->db->lockRow('users', $userId);
            if ($user === null) {
                throw new \RuntimeException('کاربر یافت نشد.');
            }
            if ($user['status'] === 'blocked') {
                throw new \RuntimeException('حساب کاربری شما مسدود است.');
            }

            $wallet = $this->db->first('SELECT * FROM wallets WHERE user_id = :u', ['u' => $userId]);
            if ($wallet === null || (int) $wallet['balance'] < $amount) {
                throw new \RuntimeException('موجودی قابل برداشت کافی نیست.');
            }

            // محدودیت درخواست باز هم‌زمان
            $openCount = (int) $this->db->value(
                "SELECT COUNT(*) FROM withdrawal_requests WHERE user_id = :u AND status IN ('new','reviewing','approved','paying')",
                ['u' => $userId]
            );
            $maxOpen = SettingsService::int('max_open_withdrawals', 1);
            if ($maxOpen > 0 && $openCount >= $maxOpen) {
                throw new \RuntimeException('شما یک درخواست برداشت باز دارید. تا تعیین وضعیت آن، امکان ثبت درخواست جدید نیست.');
            }

            // بررسی سقف روزانه
            $dailyLimit = SettingsService::int('max_daily_withdrawal', 0);
            if ($dailyLimit > 0) {
                $todaySum = (int) $this->db->value(
                    'SELECT COALESCE(SUM(amount), 0) FROM withdrawal_requests WHERE user_id = :u AND created_at >= :t AND status NOT IN (:r1, :r2)',
                    ['u' => $userId, 't' => today_start(), 'r1' => 'rejected', 'r2' => 'cancelled']
                );
                if ($todaySum + $amount > $dailyLimit) {
                    throw new \RuntimeException('سقف برداشت روزانه شما ' . wtoman($dailyLimit) . ' است.');
                }
            }

            $requestCode = 'WD-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $id = $this->db->insert('withdrawal_requests', [
                'request_code' => $requestCode,
                'user_id' => $userId,
                'amount' => $amount,
                'status' => 'new',
                'card_number_enc' => $longCard ? Crypto::encrypt(self::LONG_CARD_MARKER) : $encryptedCard,
                'card_last4' => $last4,
                'card_holder' => $holder,
                'bank_name' => self::detectBank($cardKey),
                'reserve_key' => 'withdrawal_reserve:' . $requestCode,
                'client_ip' => $ip,
                'meta' => json_encode([
                    'card_hash' => $cardHash,
                    'min_at_request' => $min,
                    'settings_version' => CommissionSettings::current($this->db)->version,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($longCard) {
                $this->db->insert('withdrawal_card_details', [
                    'withdrawal_id' => $id,
                    'card_enc' => $encryptedCard,
                ]);
            }

            // رزرو مبلغ (تراکنش مالی — اگر شکست بخورد همه چیز Rollback می‌شود)
            (new WalletService($this->db))->reserve($userId, $amount, 'withdrawal_reserve:' . $requestCode, [
                'source_type' => 'withdrawal',
                'source_id' => $id,
                'description' => 'رزرو مبلغ درخواست برداشت ' . $requestCode,
                'meta' => ['request_code' => $requestCode],
            ]);

            $this->db->run(
                'UPDATE users SET withdrawal_count = withdrawal_count + 1, updated_at = :now WHERE id = :id',
                ['now' => now(), 'id' => $userId]
            );

            $this->db->update('withdrawal_requests', [
                'status' => 'reviewing',
                'updated_at' => now(),
            ], 'id = :id', ['id' => $id]);

            SecurityService::checkCardReuse($cardHash, $userId);

            try {
                AuditService::log('withdrawal.created', 'withdrawal', $id, ['amount' => $amount, 'user_id' => $userId]);
            } catch (\Throwable) {
            }

            return $this->find($id) ?? [];
        });
    }

    public function find(int $id): ?array
    {
        return $this->db->first(
            'SELECT w.*, u.first_name, u.last_name, u.username, u.telegram_id, u.wallet_balance, u.pending_balance
             FROM withdrawal_requests w JOIN users u ON u.id = w.user_id WHERE w.id = :id',
            ['id' => $id]
        );
    }

    /** شماره کارت کامل (فقط برای مدیر مجاز) */
    public static function decryptedCard(?array $request): ?string
    {
        if ($request === null) {
            return null;
        }
        $value = Crypto::decrypt($request['card_number_enc'] ?? null);
        if ($value === self::LONG_CARD_MARKER && !empty($request['id'])) {
            $extra = Database::instance()->first('SELECT card_enc FROM withdrawal_card_details WHERE withdrawal_id = :id', ['id' => (int) $request['id']]);
            if ($extra !== null) return Crypto::decrypt((string) $extra['card_enc']);
        }
        return $value;
    }

    /** کاربر درخواست‌های خودش را می‌بیند — بدون شماره کارت کامل */
    public function forUser(int $userId, int $limit = 50): array
    {
        return $this->db->all(
            'SELECT id, request_code, amount, status, card_last4, reject_reason, tracking_code, created_at, reviewed_at, paid_at
             FROM withdrawal_requests WHERE user_id = :u ORDER BY id DESC LIMIT ' . (int) $limit,
            ['u' => $userId]
        );
    }

    public function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'open') {
                $where[] = "w.status IN ('new','reviewing','approved','paying')";
            } else {
                $where[] = 'w.status = :status';
                $params['status'] = $filters['status'];
            }
        }
        if (!empty($filters['q'])) {
            $q = '%' . trim((string) $filters['q']) . '%';
            $where[] = '(u.first_name LIKE :q1 OR u.last_name LIKE :q2 OR u.username LIKE :q3 OR w.request_code LIKE :q4 OR CAST(u.telegram_id AS CHAR) LIKE :q5 OR w.card_last4 LIKE :q6)';
            $params += ['q1' => $q, 'q2' => $q, 'q3' => $q, 'q4' => $q, 'q5' => $q, 'q6' => $q];
        }
        if (!empty($filters['from'])) {
            $where[] = 'w.created_at >= :from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'w.created_at <= :to';
            $params['to'] = $filters['to'] . ' 23:59:59';
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $this->db->value("SELECT COUNT(*) FROM withdrawal_requests w JOIN users u ON u.id = w.user_id {$whereSql}", $params);
        $sum = (int) $this->db->value("SELECT COALESCE(SUM(w.amount),0) FROM withdrawal_requests w JOIN users u ON u.id = w.user_id {$whereSql}", $params);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->all(
            "SELECT w.*, u.first_name, u.last_name, u.username, u.telegram_id, u.wallet_balance
             FROM withdrawal_requests w JOIN users u ON u.id = w.user_id
             {$whereSql} ORDER BY w.id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );
        return ['rows' => $rows, 'total' => $total, 'sum' => $sum, 'page' => $page, 'per_page' => $perPage];
    }

    public function approve(int $id, int $adminId): array
    {
        $result = $this->db->transaction(function () use ($id, $adminId) {
            $request = $this->db->lockRow('withdrawal_requests', $id);
            if ($request === null) {
                throw new \RuntimeException('درخواست یافت نشد.');
            }
            if (!in_array($request['status'], ['new', 'reviewing'], true)) {
                throw new \RuntimeException('فقط درخواست‌های جدید یا در انتظار بررسی قابل تأیید هستند.');
            }
            $this->db->update('withdrawal_requests', [
                'status' => 'approved',
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ], 'id = :id', ['id' => $id]);
            AuditService::log('withdrawal.approved', 'withdrawal', $id, ['amount' => (int) $request['amount']], $adminId);
            // اعلان داخل تراکنش ثبت می‌شود، ارسال تلگرام بعد از COMMIT
            Notifier::defer((int) $request['user_id'], '✅ درخواست برداشت تأیید شد',
                'درخواست برداشت ' . $request['request_code'] . ' به مبلغ ' . wtoman((int) $request['amount']) . " تأیید شد و در صف پرداخت قرار گرفت.", 'withdrawal');
            return $this->find($id) ?? [];
        });
        Notifier::flushDeferred();
        return $result;
    }

    public function reject(int $id, int $adminId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new \RuntimeException('دلیل رد باید حداقل ۳ کاراکتر باشد.');
        }
        $result = $this->db->transaction(function () use ($id, $adminId, $reason) {
            $request = $this->db->lockRow('withdrawal_requests', $id);
            if ($request === null) {
                throw new \RuntimeException('درخواست یافت نشد.');
            }
            if (!in_array($request['status'], self::OPEN_STATUSES, true)) {
                throw new \RuntimeException('این درخواست قابل رد کردن نیست.');
            }

            $reserveTx = (int) $this->db->value(
                'SELECT id FROM wallet_transactions WHERE idempotency_key = :k',
                ['k' => (string) ($request['reserve_key'] ?? ('withdrawal_reserve:' . $request['request_code']))]
            );
            if ($reserveTx > 0) {
                (new WalletService($this->db))->releaseReserved(
                    (int) $request['user_id'],
                    (int) $request['amount'],
                    $reserveTx,
                    'withdrawal_release:' . $request['request_code'],
                    $id,
                    $reason,
                    $adminId
                );
            }

            $this->db->update('withdrawal_requests', [
                'status' => 'rejected',
                'reject_reason' => mb_substr($reason, 0, 250),
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ], 'id = :id', ['id' => $id]);

            AuditService::log('withdrawal.rejected', 'withdrawal', $id, [
                'amount' => (int) $request['amount'],
                'reason' => $reason,
            ], $adminId);

            Notifier::defer((int) $request['user_id'], '❌ درخواست برداشت رد شد',
                'درخواست برداشت ' . $request['request_code'] . ' رد شد.' . "\n" . 'دلیل: ' . $reason . "\n" . 'مبلغ ' . wtoman((int) $request['amount']) . ' به موجودی شما بازگشت.', 'withdrawal');

            return $this->find($id) ?? [];
        });
        Notifier::flushDeferred();
        return $result;
    }

    public function markPaid(int $id, int $adminId, ?string $trackingCode = null, ?string $note = null): array
    {
        $result = $this->db->transaction(function () use ($id, $adminId, $trackingCode, $note) {
            $request = $this->db->lockRow('withdrawal_requests', $id);
            if ($request === null) {
                throw new \RuntimeException('درخواست یافت نشد.');
            }
            if (!in_array($request['status'], ['approved', 'paying'], true)) {
                throw new \RuntimeException('فقط درخواست تأییدشده قابل ثبت پرداخت است.');
            }

            $reserveTx = (int) $this->db->value(
                'SELECT id FROM wallet_transactions WHERE idempotency_key = :k',
                ['k' => (string) ($request['reserve_key'] ?? ('withdrawal_reserve:' . $request['request_code']))]
            );
            if ($reserveTx <= 0) {
                throw new \RuntimeException('تراکنش رزرو برای این درخواست یافت نشد.');
            }

            $settleTx = (new WalletService($this->db))->settleReserved(
                (int) $request['user_id'],
                (int) $request['amount'],
                $reserveTx,
                'withdrawal_paid:' . $request['request_code'],
                $id,
                $adminId
            );

            $this->db->insert('withdrawal_payments', [
                'withdrawal_id' => $id,
                'amount' => (int) $request['amount'],
                'method' => 'manual',
                'reference' => $trackingCode ? mb_substr($trackingCode, 0, 110) : null,
                'admin_id' => $adminId,
                'note' => $note ? mb_substr($note, 0, 240) : null,
                'paid_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->db->update('withdrawal_requests', [
                'status' => 'paid',
                'tracking_code' => $trackingCode ? mb_substr($trackingCode, 0, 70) : null,
                'paid_by' => $adminId,
                'paid_at' => now(),
                'updated_at' => now(),
            ], 'id = :id', ['id' => $id]);

            $this->db->update('wallet_transactions', ['source_id' => $id], 'id = :id2', ['id2' => $settleTx]);

            AuditService::log('withdrawal.paid', 'withdrawal', $id, [
                'amount' => (int) $request['amount'],
                'tracking_code' => $trackingCode,
            ], $adminId);

            // رسید پرداخت برای کاربر (خواستهٔ کارفرما): مبلغ، کد پیگیری، روش پرداخت
            $receiptBody = 'درخواست برداشت ' . $request['request_code'] . ' تسویه شد.' . "\n"
                . 'مبلغ: ' . wtoman((int) $request['amount']) . "\n"
                . 'کارت مقصد: ' . (!empty($request['card_last4']) ? '****' . $request['card_last4'] : '—') . "\n"
                . ($trackingCode ? 'کد پیگیری: ' . $trackingCode . "\n" : '')
                . ($note ? 'توضیح: ' . $note . "\n" : '')
                . 'موجودی کیف پول شما به‌روزرسانی شد.';

            Notifier::defer((int) $request['user_id'], '💸 برداشت پرداخت شد', $receiptBody, 'withdrawal');

            return $this->find($id) ?? [];
        });
        Notifier::flushDeferred();
        return $result;
    }

    public function setStatus(int $id, string $status, int $adminId): void
    {
        if (!in_array($status, ['reviewing', 'paying'], true)) {
            throw new \RuntimeException('وضعیت نامعتبر است.');
        }
        $this->db->update('withdrawal_requests', ['status' => $status, 'updated_at' => now()], 'id = :id', ['id' => $id]);
        AuditService::log('withdrawal.status_changed', 'withdrawal', $id, ['status' => $status], $adminId);
    }

    /** لغو توسط کاربر */
    public function cancelByUser(int $id, int $userId): array
    {
        return $this->db->transaction(function () use ($id, $userId) {
            $request = $this->db->lockRow('withdrawal_requests', $id);
            if ($request === null || (int) $request['user_id'] !== $userId) {
                throw new \RuntimeException('درخواست یافت نشد.');
            }
            if (!in_array($request['status'], ['new', 'reviewing'], true)) {
                throw new \RuntimeException('این درخواست قابل لغو نیست.');
            }
            $reserveTx = (int) $this->db->value(
                'SELECT id FROM wallet_transactions WHERE idempotency_key = :k',
                ['k' => (string) ($request['reserve_key'] ?? ('withdrawal_reserve:' . $request['request_code']))]
            );
            if ($reserveTx > 0) {
                (new WalletService($this->db))->releaseReserved(
                    $userId,
                    (int) $request['amount'],
                    $reserveTx,
                    'withdrawal_release:' . $request['request_code'],
                    $id,
                    'لغو توسط کاربر'
                );
            }
            $this->db->update('withdrawal_requests', [
                'status' => 'cancelled',
                'reject_reason' => 'لغو توسط کاربر',
                'updated_at' => now(),
            ], 'id = :id', ['id' => $id]);
            AuditService::log('withdrawal.cancelled', 'withdrawal', $id, ['by' => 'user']);
            return $this->find($id) ?? [];
        });
    }

    public function stats(): array
    {
        $rows = $this->db->all('SELECT status, COUNT(*) AS c, COALESCE(SUM(amount),0) AS s FROM withdrawal_requests GROUP BY status');
        $out = ['counts' => [], 'sums' => [], 'pending_amount' => 0, 'paid_amount' => 0, 'paid_count' => 0];
        foreach ($rows as $row) {
            $out['counts'][$row['status']] = (int) $row['c'];
            $out['sums'][$row['status']] = (int) $row['s'];
            if (in_array($row['status'], self::OPEN_STATUSES, true)) {
                $out['pending_amount'] += (int) $row['s'];
            }
            if ($row['status'] === 'paid') {
                $out['paid_amount'] = (int) $row['s'];
                $out['paid_count'] = (int) $row['c'];
            }
        }
        return $out;
    }

    /** تشخیص نام بانک صادرکننده بر اساس پیش‌شماره ۶ و ۸ رقمی شبکه شتاب (صرفاً جهت نمایش) */
    public static function detectBank(string $card): string
    {
        if (strlen($card) < 6) {
            return 'بانک نامشخص';
        }
        $prefix8 = substr($card, 0, 8);
        if ($prefix8 === '62198619') {
            return 'بلوبانک (بانک سامان)';
        }
        $prefix = substr($card, 0, 6);
        $banks = [
            '603799' => 'بانک ملی ایران',
            '589210' => 'بانک سپه',
            '627648' => 'بانک توسعه صادرات',
            '207177' => 'بانک توسعه صادرات',
            '627961' => 'بانک صنعت و معدن',
            '603770' => 'بانک کشاورزی',
            '639217' => 'بانک کشاورزی',
            '628023' => 'بانک مسکن',
            '627760' => 'پست بانک ایران',
            '502908' => 'بانک توسعه تعاون',
            '627412' => 'بانک اقتصاد نوین',
            '622106' => 'بانک پارسیان',
            '627884' => 'بانک پارسیان',
            '639194' => 'بانک پارسیان',
            '502229' => 'بانک پاسارگاد',
            '639347' => 'بانک پاسارگاد',
            '627488' => 'بانک کارآفرین',
            '502910' => 'بانک کارآفرین',
            '621986' => 'بانک سامان',
            '639346' => 'بانک سینا',
            '639607' => 'بانک سرمایه',
            '502806' => 'بانک شهر',
            '504706' => 'بانک شهر',
            '502938' => 'بانک دی',
            '603769' => 'بانک صادرات ایران',
            '610433' => 'بانک ملت',
            '991975' => 'بانک ملت',
            '627353' => 'بانک تجارت',
            '585983' => 'بانک تجارت',
            '589463' => 'بانک رفاه کارگران',
            '627381' => 'بانک انصار (سپه)',
            '639370' => 'بانک مهر اقتصاد (سپه)',
            '639599' => 'بانک قوامین (سپه)',
            '636949' => 'بانک حکمت ایرانیان (سپه)',
            '505801' => 'موسسه کوثر (سپه)',
            '505785' => 'بانک ایران‌زمین',
            '505416' => 'بانک گردشگری',
            '636214' => 'بانک آینده',
            '504172' => 'بانک قرض‌الحسنه رسالت',
            '606373' => 'بانک قرض‌الحسنه مهر ایران',
            '585947' => 'بانک خاورمیانه',
            '505809' => 'بانک خاورمیانه',
            '585848' => 'بانک مشترک ایران و ونزوئلا',
            '636795' => 'بانک مرکزی',
            '606256' => 'موسسه اعتباری ملل',
            '507677' => 'موسسه اعتباری نور',
            '628157' => 'موسسه اعتباری توسعه',
        ];
        return $banks[$prefix] ?? 'بانک نامشخص';
    }
}

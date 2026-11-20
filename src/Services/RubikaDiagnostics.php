<?php
declare(strict_types=1);

namespace RB\Services;

use RB\Support\Database;

/** Privacy-preserving, best-effort operational trace; never stores payloads or identifiers. */
final class RubikaDiagnostics
{
    private static ?array $current = null;
    private const SOURCES = ['webhook', 'polling', 'poll_check', 'api_test', 'endpoint_setup'];
    private const EVENTS = ['unknown', 'NewMessage', 'StartedBot', 'StoppedBot', 'InlineMessage', 'ReceiveUpdate', 'ReceiveInlineMessage', 'Other', 'contact', 'message', 'callback', 'started', 'stopped'];
    private const REASONS = ['user_unknown', 'blocked', 'group', 'rate_limit', 'unknown_callback', 'missing_identity', 'no_response', 'identity_lookup_failed', 'database_error', 'processing_error'];

    public static function begin(Database $db, string $source, string $event = 'unknown'): void
    {
        $source = in_array($source, self::SOURCES, true) ? $source : 'webhook';
        $event = in_array($event, self::EVENTS, true) ? $event : 'Other';
        self::$current = ['db'=>$db, 'id'=>0, 'started'=>microtime(true), 'sends'=>0, 'ok'=>0, 'fail'=>0, 'unconfirmed'=>0, 'http'=>0, 'api'=>'', 'detail'=>'', 'api_code'=>0, 'reason'=>'', 'event'=>$event, 'target'=>null];
        try {
            if (!$db->tableExists('rubika_diagnostics')) return;
            $id = $db->insert('rubika_diagnostics', ['source'=>$source, 'event_type'=>$event, 'result'=>'received', 'created_at'=>now()]);
            self::$current['id'] = $id;
            // Bounded to roughly 500 rows without a potentially expensive delete on every request.
            if ($id % 50 === 0) {
                $oldest = $db->value('SELECT id FROM rubika_diagnostics ORDER BY id DESC LIMIT 1 OFFSET 499');
                if ($oldest) $db->run('DELETE FROM rubika_diagnostics WHERE id < :id', ['id'=>(int)$oldest]);
            }
        } catch (\Throwable) { /* diagnostics must never break the bot */ }
    }

    public static function event(string $event): void
    {
        if (self::$current === null) return;
        $event = in_array($event, self::EVENTS, true) ? $event : 'Other';
        self::$current['event'] = $event;
        self::update(['event_type'=>$event]);
    }

    public static function reason(string $reason): void
    {
        if (self::$current !== null && in_array($reason, self::REASONS, true)) self::$current['reason'] = $reason;
    }

    /** Keep the target in memory only, so unrelated deferred notifications are not counted as replies. */
    public static function target(string $chatId): void
    {
        if (self::$current !== null) self::$current['target'] = $chatId;
    }

    /** Safe status codes only; neither raw response nor error message is persisted. */
    public static function apiResult(array $res): void
    {
        if (self::$current === null) return;
        $raw = is_array($res['raw'] ?? null) ? $res['raw'] : [];
        $code = (int)($raw['_http_code'] ?? 0);
        self::$current['http'] = $code >= 100 && $code <= 599 ? $code : 0;
        $status = strtoupper((string)($raw['status'] ?? ''));
        self::$current['api'] = in_array($status, ['OK','ERROR','INVALID_AUTH','INVALID_INPUT','TOO_REQUESTS','TIMEOUT'], true) ? $status : '';
        $detail = strtoupper((string)($raw['status_det'] ?? ''));
        $validDetails = ['INVALID_AUTH','INVALID_INPUT','INVALID_TOKEN','ACCESS_DENIED','TOO_REQUESTS','TIMEOUT','INTERNAL_ERROR'];
        self::$current['detail'] = in_array($detail, $validDetails, true) ? $detail : '';
        $transport = (string)($raw['_transport_kind'] ?? '');
        if (in_array($transport, ['timeout','dns','tls','connect','network','non_json'], true)) self::$current['detail'] = strtoupper($transport);
        $apiCode = $raw['error_code'] ?? 0;
        self::$current['api_code'] = is_numeric($apiCode) && (int)$apiCode > 0 && (int)$apiCode < 100000 ? (int)$apiCode : 0;
    }

    public static function sent(array $res, string $chatId): void
    {
        if (self::$current === null || self::$current['target'] === null || self::$current['target'] !== $chatId) return;
        self::apiResult($res);
        self::$current['sends']++;
        if (empty($res['ok'])) self::$current['fail']++;
        elseif (empty($res['data']['message_id'])) self::$current['unconfirmed']++;
        else self::$current['ok']++;
    }

    public static function finish(string $result): void
    {
        if (self::$current === null) return;
        $allowed = ['received','processed','duplicate','ignored','disabled','invalid_json','empty','too_large','handler_error','ok','error'];
        $result = in_array($result, $allowed, true) ? $result : 'error';
        $c = self::$current;
        $send = $c['sends'] === 0 ? 'none' : ($c['fail'] && ($c['ok'] || $c['unconfirmed']) ? 'mixed' : ($c['fail'] ? 'error' : ($c['unconfirmed'] ? 'unconfirmed' : 'ok')));
        $reason = $c['reason'];
        if ($result === 'processed' && $send === 'none' && $reason === '' && !in_array($c['event'], ['StoppedBot','stopped'], true)) $reason = 'no_response';
        self::update(['result'=>$result, 'reason'=>$reason, 'send_result'=>$send, 'send_count'=>$c['sends'], 'http_code'=>$c['http'], 'api_status'=>$c['api'], 'api_detail'=>$c['detail'], 'api_code'=>$c['api_code'], 'duration_ms'=>min(999999, (int)round((microtime(true)-$c['started'])*1000))]);
        self::$current = null;
    }

    private static function update(array $values): void
    {
        try {
            if (self::$current !== null && self::$current['id'] > 0) {
                self::$current['db']->update('rubika_diagnostics', $values, 'id = :id', ['id'=>(int)self::$current['id']]);
            }
        } catch (\Throwable) { }
    }

    public static function recent(Database $db, int $limit = 40): array
    {
        try {
            if (!$db->tableExists('rubika_diagnostics')) return [];
            return $db->all('SELECT * FROM rubika_diagnostics ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
        } catch (\Throwable) { return []; }
    }
}

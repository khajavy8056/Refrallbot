<?php
declare(strict_types=1);

use RB\Support\Database;
use RB\Support\SchemaBuilder;

/** Preserve existing pending referrals while disabling manual approval for new ones. */
return static function (SchemaBuilder $schema): void {
    $schema->addColumn('users', 'verification_required', 'TINYINT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
    $schema->after(static function (Database $db): void {
    $db->transaction(static function () use ($db): void {
    $row = $db->first('SELECT * FROM commission_setting_versions ORDER BY version DESC, id DESC LIMIT 1');
    if (!$row) return;
    $conditions = json_decode((string) ($row['qualify_conditions'] ?? '[]'), true);
    if (!is_array($conditions)) return;
    if (!in_array('approved', $conditions, true) && !in_array('manual_approval', $conditions, true)) return;
    // An already-paid invite is never put back into a payment queue.
    $db->run("UPDATE users SET verification_required = 1 WHERE referred_by IS NOT NULL AND qualified_at IS NULL AND verify_status IN ('pending','rejected')");
    // Add a version rather than modifying historical settings snapshots.
    unset($row['id']);
    $row['version'] = (int)$row['version'] + 1;
    $filtered = array_values(array_filter($conditions, static fn($c) => $c !== 'approved' && $c !== 'manual_approval'));
    $row['qualify_conditions'] = json_encode($filtered ?: ['joined'], JSON_UNESCAPED_UNICODE);
    $row['created_by'] = null;
    $row['effective_from'] = now(); $row['created_at'] = now(); $row['updated_at'] = now();
    $db->insert('commission_setting_versions', $row);
    \RB\Services\CommissionSettings::forgetCache();
    });
    });
};

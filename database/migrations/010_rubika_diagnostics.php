<?php
declare(strict_types=1);

use RB\Support\SchemaBuilder;

// Only operational metadata: no message text, user/chat ID, token or URL.
return static function (SchemaBuilder $schema): void {
    $schema->table('rubika_diagnostics', static function ($t): void {
        $t->id();
        $t->string('source', 24);
        $t->string('event_type', 32, false, "'unknown'");
        $t->string('result', 32, false, "'received'");
        $t->string('reason', 32, false, "''");
        $t->string('send_result', 24, false, "'none'");
        $t->int('send_count', false, '0');
        $t->int('http_code', false, '0');
        $t->string('api_status', 32, false, "''");
        $t->string('api_detail', 32, false, "''");
        $t->int('api_code', false, '0');
        $t->int('duration_ms', false, '0');
        $t->datetime('created_at', false, 'CURRENT_TIMESTAMP');
        $t->index('created_at');
        $t->index('source');
    });
};

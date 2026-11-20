<?php
declare(strict_types=1);
use RB\Support\SchemaBuilder;

// Preserve the event subject; store the inviter held responsible separately.
return static function (SchemaBuilder $schema): void {
    $schema->addColumn('security_events', 'responsible_user_id', 'BIGINT NULL DEFAULT NULL', 'INTEGER NULL DEFAULT NULL');
};

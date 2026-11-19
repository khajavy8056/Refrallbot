<?php
declare(strict_types=1);

use RB\Support\SchemaBuilder;

return static function (SchemaBuilder $schema): void {
    $schema->table('user_level_config', function ($t) {
        $t->id();
        $t->bool('enabled');
        $t->bigint('budget', false, '0');
        $t->bigint('spent', false, '0');
        $t->timestamps();
    });
    $schema->table('user_levels', function ($t) {
        $t->id();
        $t->string('name', 100);
        $t->string('icon', 32, false, "'🏅'");
        $t->string('badge', 100, false, "''");
        $t->string('color', 7, false, "'#6366f1'");
        $t->int('min_invites');
        $t->bigint('invite_reward', false, '0');
        $t->bigint('promotion_reward', false, '0');
        $t->bigint('user_cap', false, '0');
        $t->text('benefits', true);
        $t->bool('enabled', true);
        $t->bool('deleted');
        $t->timestamps();
        $t->index(['enabled', 'deleted', 'min_invites']);
    });
    $schema->table('user_level_payments', function ($t) {
        $t->id();
        $t->bigint('level_id');
        $t->bigint('user_id');
        $t->bigint('event_id');
        $t->string('kind', 20);
        $t->bigint('amount');
        $t->datetime('created_at', false, 'CURRENT_TIMESTAMP');
        $t->unique(['level_id', 'user_id', 'event_id', 'kind']);
        $t->index(['level_id', 'user_id']);
    });
    $schema->table('user_level_promotions', function ($t) {
        $t->id();
        $t->bigint('level_id');
        $t->bigint('user_id');
        $t->bigint('event_id');
        $t->bigint('amount', false, '0');
        $t->string('level_name', 100);
        $t->datetime('created_at', false, 'CURRENT_TIMESTAMP');
        $t->unique(['level_id', 'user_id']);
    });
};

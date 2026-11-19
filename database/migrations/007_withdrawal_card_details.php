<?php
declare(strict_types=1);

use RB\Support\SchemaBuilder;

/** Extra capacity for encrypted free-form card details; existing records remain unchanged. */
return static function (SchemaBuilder $schema): void {
    $schema->table('withdrawal_card_details', static function ($t): void {
        $t->id();
        $t->bigint('withdrawal_id')->foreign('withdrawal_id', 'withdrawal_requests', 'CASCADE');
        $t->longtext('card_enc');
        $t->unique('withdrawal_id');
    });
};

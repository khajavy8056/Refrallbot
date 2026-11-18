<?php
declare(strict_types=1);

/**
 * شمای کامل پایگاه داده (Migration شماره ۱)
 * برای افزودن ستون جدید، فایل migration جدید با شماره بالاتر بسازید.
 */

use RB\Support\SchemaBuilder;

return static function (SchemaBuilder $schema): void {

    // ---------------------------------------------------------------- مدیران
    $schema->table('admin_roles', function ($t) {
        $t->id();
        $t->string('name', 80);
        $t->string('slug', 60);
        $t->text('permissions');           // JSON آرایه‌ای از کلیدهای مجاز
        $t->string('description', 255, true);
        $t->timestamps();
        $t->unique('slug');
    });

    $schema->table('admins', function ($t) {
        $t->id();
        $t->string('name', 120);
        $t->string('username', 80);
        $t->string('password_hash', 255);
        $t->bigint('role_id')->foreign('role_id', 'admin_roles', 'SET NULL');
        $t->bigint('telegram_user_id', true);
        $t->bool('is_active', true);
        $t->bool('must_change_password', false);
        $t->int('failed_attempts', false, '0');
        $t->datetime('locked_until', true);
        $t->datetime('last_login_at', true);
        $t->string('last_login_ip', 45, true);
        $t->datetime('totp_enabled_at', true);
        $t->string('totp_secret', 255, true);   // رمزنگاری‌شده
        $t->text('totp_recovery_codes');        // رمزنگاری‌شده
        $t->timestamps();
        $t->unique('username');
        $t->index('telegram_user_id');
    });

    $schema->table('admin_activity_logs', function ($t) {
        $t->id();
        $t->bigint('admin_id', true)->foreign('admin_id', 'admins', 'SET NULL');
        $t->string('admin_name', 120, true);
        $t->string('action', 120);
        $t->string('entity_type', 80, true);
        $t->bigint('entity_id', true);
        $t->text('details');           // JSON
        $t->string('ip', 45, true);
        $t->string('user_agent', 255, true);
        $t->datetime('created_at', false, 'CURRENT_TIMESTAMP');
        $t->index('admin_id');
        $t->index('action');
        $t->index('created_at');
    });

    $schema->table('login_attempts', function ($t) {
        $t->id();
        $t->string('username', 80);
        $t->string('ip', 45);
        $t->int('failed_count', false, '0');
        $t->datetime('last_attempt_at', true);
        $t->datetime('locked_until', true);
        $t->unique(['username', 'ip']);
        $t->index('ip');
    });

    // ---------------------------------------------------------------- کاربران
    $schema->table('users', function ($t) {
        $t->id();
        $t->bigint('telegram_id');                  // شناسه یکتای اصلی
        $t->string('username', 64, true);
        $t->string('first_name', 128, true);
        $t->string('last_name', 128, true);
        $t->string('phone', 32, true);
        $t->string('photo_url', 512, true);
        $t->string('language', 10, false, "'fa'");
        $t->string('referral_code', 20);            // کد اختصاصی دعوت
        $t->bigint('referred_by', true);            // معرف مستقیم (users.id)
        $t->datetime('referred_at', true);
        $t->string('status', 20, false, "'active'"); // active|blocked|suspicious
        $t->string('verify_status', 20, false, "'pending'"); // pending|verified|rejected
        $t->bool('is_admin', false);
        $t->bool('contact_verified', false);
        $t->bool('channels_verified', false);
        $t->datetime('joined_at', false);
        $t->datetime('last_activity_at', true);
        $t->datetime('qualified_at', true);
        $t->int('direct_ref_count', false, '0');
        $t->int('total_ref_count', false, '0');
        $t->bigint('wallet_balance', false, '0');    // مانده قابل برداشت (کش همگام با ledger)
        $t->bigint('pending_balance', false, '0');   // مبلغ رزرو‌شده
        $t->bigint('total_earned', false, '0');
        $t->bigint('total_withdrawn', false, '0');
        $t->bigint('direct_earned', false, '0');
        $t->bigint('layer_earned', false, '0');
        $t->bigint('campaign_earned', false, '0');
        $t->int('withdrawal_count', false, '0');
        $t->int('risk_score', false, '0');
        $t->string('signup_ip', 45, true);
        $t->string('last_ip', 45, true);
        $t->text('meta');                            // JSON
        $t->timestamps();
        $t->unique('telegram_id');
        $t->unique('referral_code');
        $t->index('referred_by');
        $t->index('status');
        $t->index('joined_at');
        $t->index('total_earned');
    });

    $schema->table('referral_relations', function ($t) {
        $t->id();
        $t->bigint('ancestor_id')->foreign('ancestor_id', 'users', 'CASCADE');
        $t->bigint('descendant_id')->foreign('descendant_id', 'users', 'CASCADE');
        $t->int('depth');                             // ۱ تا ۸
        $t->datetime('created_at', false, 'CURRENT_TIMESTAMP');
        $t->unique(['ancestor_id', 'descendant_id']);
        $t->index('descendant_id');
        $t->index(['ancestor_id', 'depth']);
    });

    $schema->table('referral_events', function ($t) {
        $t->id();
        $t->string('event_key', 191);                 // کلید یکتا برای ضد تکرار
        $t->string('type', 40);                        // user_joined|referral_qualified|manual_adjustment|campaign_reward
        $t->bigint('user_id')->foreign('user_id', 'users', 'CASCADE');       // ذی‌نفع اصلی/کاربر معرفی‌شده
        $t->bigint('actor_id', true)->foreign('actor_id', 'users', 'SET NULL');
        $t->bigint('source_user_id', true);
        $t->bigint('base_amount', false, '0');         // مبلغ پایه رویداد (تومان)
        $t->string('status', 20, false, "'processed'");// processed|reverted|pending
        $t->text('payload');                            // JSON
        $t->datetime('created_at', false, 'CURRENT_TIMESTAMP');
        $t->unique('event_key');
        $t->index('type');
        $t->index('user_id');
        $t->index('created_at');
    });

    $schema->table('commission_settings', function ($t) {
        $t->id();
        $t->string('key', 100);
        $t->text('value');
        $t->timestamps();
        $t->unique('key');
    });

    $schema->table('commission_setting_versions', function ($t) {
        $t->id();
        $t->int('version', false, '1');
        $t->bigint('direct_reward', false, '0');
        $t->bool('direct_enabled', true);
        $t->bool('multi_level_enabled', false);
        $t->int('max_levels', false, '3');
        $t->text('levels');                          // JSON: [{level:1,percent:20,enabled:true}]
        $t->string('percent_base', 30, false, "'direct_reward'"); // direct_reward|fixed_base|event_amount
        $t->bigint('fixed_base_amount', false, '0');
        $t->bigint('max_payout_per_event', false, '0'); // ۰ = بدون سقف
        $t->bigint('daily_payout_cap', false, '0');
        $t->text('qualify_conditions');              // JSON
        $t->bool('blocked_users_no_commission', true);
        $t->bigint('created_by', true);
        $t->datetime('effective_from', false);
        $t->timestamps();
        $t->index('version');
    });

    $schema->table('referral_commissions', function ($t) {
        $t->id();
        $t->bigint('event_id')->foreign('event_id', 'referral_events', 'CASCADE');
        $t->bigint('beneficiary_id')->foreign('beneficiary_id', 'users', 'CASCADE');
        $t->bigint('source_user_id', true);
        $t->int('level', false, '0');                 // ۰ = پاداش مستقیم
        $t->string('type', 30);                        // direct|level|campaign|manual
        $t->bigint('base_amount', false, '0');
        $t->string('percent', 20, false, "'0'");
        $t->bigint('amount', false, '0');
        $t->bigint('settings_version_id', true);
        $t->string('status', 20, false, "'approved'"); // approved|reverted|pending
        $t->text('meta');
        $t->datetime('created_at', false, 'CURRENT_TIMESTAMP');
        $t->unique(['event_id', 'beneficiary_id', 'level', 'type']);
        $t->index('beneficiary_id');
        $t->index('source_user_id');
        $t->index('created_at');
    });

    // ---------------------------------------------------------------- کیف پول
    $schema->table('wallets', function ($t) {
        $t->id();
        $t->bigint('user_id')->foreign('user_id', 'users', 'CASCADE');
        $t->bigint('balance', false, '0');
        $t->bigint('pending', false, '0');
        $t->bigint('total_in', false, '0');
        $t->bigint('total_out', false, '0');
        $t->string('currency', 8, false, "'IRT'");
        $t->timestamps();
        $t->unique('user_id');
    });

    $schema->table('wallet_transactions', function ($t) {
        $t->id();
        $t->string('tx_code', 32);                     // شناسه قابل نمایش تراکنش
        $t->bigint('user_id')->foreign('user_id', 'users', 'CASCADE');
        $t->string('type', 40);                        // commission_direct|commission_level|campaign|withdrawal_reserve|withdrawal_release|withdrawal_paid|adjustment|reversal|refund
        $t->bigint('amount', false, '0');             // همیشه مثبت؛ جهت از direction
        $t->string('direction', 8, false, "'credit'"); // credit|debit
        $t->bigint('balance_after', false, '0');
        $t->string('status', 20, false, "'completed'"); // completed|pending|reverted
        $t->string('source_type', 40, true);
        $t->bigint('source_id', true);
        $t->bigint('related_user_id', true);
        $t->int('level', true);
        $t->bigint('event_id', true);
        $t->string('idempotency_key', 191, true);
        $t->string('description', 255, true);
        $t->text('meta');                               // JSON تنظیمات معتبر زمان تراکنش
        $t->bigint('created_by_admin_id', true);
        $t->bigint('reversal_of_id', true);
        $t->datetime('created_at', false, 'CURRENT_TIMESTAMP');
        $t->unique('tx_code');
        $t->unique('idempotency_key');
        $t->index('user_id');
        $t->index('type');
        $t->index('created_at');
        $t->index('status');
    });

    $schema->table('withdrawal_requests', function ($t) {
        $t->id();
        $t->string('request_code', 32);
        $t->bigint('user_id')->foreign('user_id', 'users', 'CASCADE');
        $t->bigint('amount', false, '0');
        $t->string('status', 24, false, "'new'");
        // new|reviewing|approved|paying|paid|rejected|cancelled
        $t->string('card_number_enc', 255);            // رمزنگاری‌شده
        $t->string('card_last4', 4, true);
        $t->string('card_holder', 128, true);
        $t->string('iban', 40, true);
        $t->string('bank_name', 80, true);
        $t->string('reject_reason', 255, true);
        $t->string('tracking_code', 80, true);
        $t->bigint('reviewed_by', true);
        $t->datetime('reviewed_at', true);
        $t->bigint('paid_by', true);
        $t->datetime('paid_at', true);
        $t->string('reserve_key', 191, true);          // کلید یکتا برای قفل مبلغ
        $t->string('client_ip', 45, true);
        $t->text('meta');
        $t->timestamps();
        $t->unique('request_code');
        $t->index('user_id');
        $t->index('status');
        $t->index('created_at');
    });

    $schema->table('withdrawal_payments', function ($t) {
        $t->id();
        $t->bigint('withdrawal_id')->foreign('withdrawal_id', 'withdrawal_requests', 'CASCADE');
        $t->bigint('amount', false, '0');
        $t->string('method', 40, false, "'manual'");
        $t->string('reference', 120, true);
        $t->bigint('admin_id', true);
        $t->string('note', 255, true);
        $t->datetime('paid_at', false);
        $t->timestamps();
        $t->index('withdrawal_id');
    });

    // ---------------------------------------------------------------- تلگرام
    $schema->table('telegram_settings', function ($t) {
        $t->id();
        $t->string('bot_token_enc', 512, true);
        $t->bigint('bot_id', true);
        $t->string('bot_username', 64, true);
        $t->string('bot_name', 128, true);
        $t->string('connection_status', 20, false, "'disconnected'"); // connected|error|disconnected
        $t->text('last_error');
        $t->datetime('last_test_at', true);
        $t->string('webhook_url', 512, true);
        $t->string('webhook_secret', 128, true);
        $t->bool('webhook_active', false);
        $t->text('webhook_info');
        $t->datetime('webhook_set_at', true);
        $t->datetime('last_update_at', true);
        $t->bigint('updates_count', false, '0');
        $t->bigint('messages_sent', false, '0');
        $t->bigint('messages_failed', false, '0');
        $t->bool('menu_synced', false);
        $t->datetime('menu_synced_at', true);
        $t->timestamps();
    });

    $schema->table('telegram_channels', function ($t) {
        $t->id();
        $t->string('title', 160);
        $t->string('chat_id', 120);                    // @username یا -100...
        $t->string('invite_link', 255, true);
        $t->bool('is_active', true);
        $t->int('sort_order', false, '0');
        $t->datetime('created_at', false, 'CURRENT_TIMESTAMP');
        $t->index('is_active');
    });

    $schema->table('telegram_messages', function ($t) {
        $t->id();
        $t->bigint('user_id', true)->foreign('user_id', 'users', 'SET NULL');
        $t->bigint('telegram_id', true);
        $t->string('direction', 8, false, "'in'");     // in|out
        $t->string('kind', 40, true);                   // message|callback|command|menu
        $t->text('payload');
        $t->string('chat_id', 64, true);
        $t->string('message_id', 64, true);
        $t->string('status', 20, true);
        $t->datetime('created_at', false, 'CURRENT_TIMESTAMP');
        $t->index('user_id');
        $t->index('created_at');
    });

    $schema->table('bot_menus', function ($t) {
        $t->id();
        $t->string('title', 80);
        $t->string('action', 60);                       // کلید عملیات
        $t->string('type', 20, false, "'reply'");       // reply|inline
        $t->bool('is_active', true);
        $t->int('sort_order', false, '0');
        $t->json('meta', true);
        $t->timestamps();
    });

    $schema->table('broadcast_campaigns', function ($t) {
        $t->id();
        $t->string('title', 160);
        $t->text('message_text');
        $t->string('media_type', 20, true);             // photo|video|document
        $t->string('media_path', 255, true);
        $t->string('media_file_id', 191, true);
        $t->json('buttons');
        $t->bigint('segment_user_ids', true);           // اگر ارسال گروهی دستی باشد در جدول قالب ذخیره می‌شود
        $t->string('segment', 40, false, "'all'");      // all|active|with_phone|with_refs|level|user|campaign
        $t->json('segment_params');
        $t->string('status', 24, false, "'draft'");     // draft|queued|sending|sent|failed|cancelled
        $t->datetime('scheduled_at', true);
        $t->bigint('total_targets', false, '0');
        $t->bigint('sent_count', false, '0');
        $t->bigint('failed_count', false, '0');
        $t->bigint('blocked_count', false, '0');
        $t->bigint('offset_count', false, '0');
        $t->datetime('started_at', true);
        $t->datetime('finished_at', true);
        $t->text('last_error');
        $t->bigint('created_by', true);
        $t->timestamps();
        $t->index('status');
    });

    $schema->table('broadcast_targets', function ($t) {
        $t->id();
        $t->bigint('campaign_id')->foreign('campaign_id', 'broadcast_campaigns', 'CASCADE');
        $t->bigint('user_id');
        $t->bigint('telegram_id');
        $t->string('status', 20, false, "'pending'");   // pending|sent|failed|blocked
        $t->text('error');
        $t->datetime('sent_at', true);
        $t->index('campaign_id');
        $t->index(['campaign_id', 'status']);
        $t->unique(['campaign_id', 'user_id']);
    });

    // ---------------------------------------------------------------- کمپین‌ها
    $schema->table('campaigns', function ($t) {
        $t->id();
        $t->string('title', 160);
        $t->text('description');
        $t->string('banner_path', 255, true);
        $t->string('type', 30, false, "'invite_count'"); // invite_count|commission_boost|top_inviters
        $t->datetime('starts_at', true);
        $t->datetime('ends_at', true);
        $t->bool('is_active', true);
        $t->json('conditions');
        $t->int('required_invites', false, '0');
        $t->bigint('fixed_reward', false, '0');
        $t->string('percent_bonus', 20, false, "'0'");
        $t->int('max_winners', false, '0');
        $t->json('prize_tiers');
        $t->bigint('min_activity', false, '0');
        $t->string('audience', 40, false, "'all'");
        $t->json('audience_params', true);
        $t->bigint('created_by', true);
        $t->timestamps();
        $t->index('is_active');
        $t->index(['starts_at', 'ends_at']);
    });

    $schema->table('campaign_participants', function ($t) {
        $t->id();
        $t->bigint('campaign_id')->foreign('campaign_id', 'campaigns', 'CASCADE');
        $t->bigint('user_id')->foreign('user_id', 'users', 'CASCADE');
        $t->int('progress', false, '0');
        $t->int('rank', true);
        $t->string('status', 20, false, "'active'");
        $t->bigint('rewarded_amount', false, '0');
        $t->timestamps();
        $t->unique(['campaign_id', 'user_id']);
        $t->index('user_id');
    });

    $schema->table('campaign_rewards', function ($t) {
        $t->id();
        $t->bigint('campaign_id')->foreign('campaign_id', 'campaigns', 'CASCADE');
        $t->bigint('user_id')->foreign('user_id', 'users', 'CASCADE');
        $t->bigint('amount', false, '0');
        $t->string('type', 30, false, "'fixed'");
        $t->string('note', 255, true);
        $t->bigint('transaction_id', true);
        $t->bigint('admin_id', true);
        $t->datetime('granted_at', false);
        $t->timestamps();
        $t->unique(['campaign_id', 'user_id', 'type']);
        $t->index('user_id');
    });

    // ---------------------------------------------------------------- ارتباطات
    $schema->table('notifications', function ($t) {
        $t->id();
        $t->bigint('user_id', true)->foreign('user_id', 'users', 'SET NULL');
        $t->string('audience', 20, false, "'user'");   // user|all|admins
        $t->string('type', 30, false, "'info'");
        $t->string('title', 160);
        $t->text('body');
        $t->string('link', 255, true);
        $t->datetime('read_at', true);
        $t->datetime('created_at', false, 'CURRENT_TIMESTAMP');
        $t->index('user_id');
        $t->index('created_at');
    });

    $schema->table('support_messages', function ($t) {
        $t->id();
        $t->bigint('user_id')->foreign('user_id', 'users', 'CASCADE');
        $t->bigint('admin_id', true);
        $t->string('direction', 8, false, "'in'");     // in = از کاربر، out = پاسخ مدیر
        $t->string('subject', 160, true);
        $t->text('message');
        $t->string('status', 20, false, "'open'");     // open|answered|closed
        $t->datetime('read_at', true);
        $t->timestamps();
        $t->index('user_id');
        $t->index('status');
    });

    $schema->table('security_events', function ($t) {
        $t->id();
        $t->bigint('user_id', true)->foreign('user_id', 'users', 'SET NULL');
        $t->string('type', 60);
        $t->string('severity', 20, false, "'low'");    // low|medium|high
        $t->text('details');
        $t->string('ip', 45, true);
        $t->string('user_agent', 255, true);
        $t->string('status', 20, false, "'open'");     // open|reviewed|dismissed|confirmed
        $t->bigint('reviewed_by', true);
        $t->datetime('reviewed_at', true);
        $t->datetime('created_at', false, 'CURRENT_TIMESTAMP');
        $t->index('user_id');
        $t->index('type');
        $t->index('status');
    });

    $schema->table('system_settings', function ($t) {
        $t->id();
        $t->string('key', 100);
        $t->text('value');
        $t->string('group_name', 60, false, "'general'");
        $t->timestamps();
        $t->unique('key');
        $t->index('group_name');
    });

    $schema->table('bot_sessions', function ($t) {
        $t->id();
        $t->bigint('telegram_id');
        $t->bigint('user_id', true);
        $t->string('step', 60, true);
        $t->text('payload');
        $t->datetime('expires_at', true);
        $t->timestamps();
        $t->unique('telegram_id');
    });

    $schema->table('migrations', function ($t) {
        $t->id();
        $t->string('migration', 191);
        $t->datetime('applied_at', false);
        $t->unique('migration');
    });
};

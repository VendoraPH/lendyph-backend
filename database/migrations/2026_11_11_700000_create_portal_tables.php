<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Member Portal v1 (Phase 3, Wave 3; design notes/phase3-run/portal-v1-design.md
 * §2): the eleven portal tables. Every portal migration is in slice P1, so the
 * read and request slices add none.
 *
 * - Status and type columns are varchar, checked against the models'
 *   constants, never ENUMs.
 * - Money is `decimal(15,2)` pesos (a request's amount); the portal stores no
 *   balances.
 * - `portal_users` holds sign-in credentials only, one row per member, and is
 *   never deleted (disabled instead), so its foreign keys restrict deletes.
 * - Codes, OTPs and step tokens are stored as hashes only.
 * - `live_key` columns are unique and nullable: set while a row is open, so a
 *   member has at most one open code, and one open request per key.
 *
 * Seeds the single settings row with the portal switched off. Only new tables;
 * no existing row changes. Each create is skipped when its table exists, so
 * running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->create('portal_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('portal_name', 100)->default('Member Portal');
            $table->text('welcome_message')->nullable();
            $table->string('support_contact', 255)->nullable();
            $table->text('payment_instructions')->nullable();
            $table->unsignedSmallInteger('session_minutes')->default(60);
            $table->unsignedSmallInteger('idle_minutes')->default(15);
            $table->unsignedTinyInteger('max_login_attempts')->default(5);
            $table->unsignedSmallInteger('lockout_minutes')->default(15);
            $table->unsignedTinyInteger('hard_lock_after')->default(3);
            $table->unsignedTinyInteger('password_min_length')->default(10);
            $table->unsignedSmallInteger('activation_code_hours')->default(72);
            $table->unsignedSmallInteger('reset_code_hours')->default(24);
            $table->boolean('otp_required')->default(false);
            $table->json('features')->nullable();
            $table->json('dashboard_cards')->nullable();
            $table->json('withdrawal_methods')->nullable();
            $table->unsignedSmallInteger('withdrawal_hold_days')->default(7);
            $table->string('auth_dummy_hash')->nullable();
            $table->unsignedSmallInteger('request_expiry_days')->default(30);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        $this->create('portal_terms_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('version', 20)->unique();
            $table->string('title', 150);
            $table->longText('terms_body');
            $table->longText('privacy_body');
            $table->text('consent_body')->nullable();
            $table->boolean('requires_reacceptance')->default(false);
            $table->boolean('is_draft')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['is_draft', 'published_at']);
        });

        $this->create('portal_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('borrower_id')->unique()->constrained('borrowers')->restrictOnDelete();
            $table->string('password')->nullable();
            $table->string('status', 12)->default('invited');
            $table->string('disabled_reason', 30)->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('lockouts_cleared_at')->nullable();
            $table->timestamp('hard_locked_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('terms_version', 20)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('ecomm_consent_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        $this->create('portal_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portal_user_id')->constrained('portal_users')->restrictOnDelete();
            $table->string('kind', 12);
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('status', 12)->default('open');
            $table->unsignedBigInteger('live_key')->nullable()->unique();
            $table->char('step_hash', 64)->nullable()->unique();
            $table->timestamp('step_expires_at')->nullable();
            $table->string('identity_checked_as', 64)->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['portal_user_id', 'status']);
            $table->index(['issued_by', 'created_at']);
        });

        $this->create('portal_otp_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portal_user_id')->constrained('portal_users')->restrictOnDelete();
            $table->foreignId('portal_code_id')->nullable()->constrained('portal_codes')->restrictOnDelete();
            $table->string('purpose', 20);
            $table->string('code_hash');
            $table->char('step_hash', 64)->nullable();
            $table->string('masked_recipient', 64)->nullable();
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['portal_user_id', 'created_at']);
        });

        $this->create('portal_login_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portal_user_id')->nullable()->constrained('portal_users')->restrictOnDelete();
            $table->char('login_hash', 64);
            $table->string('outcome', 20);
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('device', 120)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['portal_user_id', 'created_at']);
            $table->index(['login_hash', 'created_at']);
            $table->index('token_id');
        });

        $this->create('portal_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('portal_user_id')->constrained('portal_users')->restrictOnDelete();
            $table->foreignId('borrower_id')->constrained('borrowers')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('status', 20)->default('submitted');
            $table->json('details')->nullable();
            $table->json('current_values')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->foreignId('savings_account_id')->nullable()->constrained('savings_accounts')->restrictOnDelete();
            $table->foreignId('savings_product_id')->nullable()->constrained('savings_products')->restrictOnDelete();
            $table->foreignId('savings_hold_id')->nullable()->constrained('savings_holds')->restrictOnDelete();
            $table->foreignId('savings_approval_id')->nullable()->constrained('savings_approvals')->restrictOnDelete();
            $table->foreignId('savings_transaction_id')->nullable()->constrained('savings_transactions')->restrictOnDelete();
            $table->foreignId('opened_account_id')->nullable()->constrained('savings_accounts')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('live_key', 60)->nullable()->unique();
            $table->timestamps();
            $table->index(['branch_id', 'status', 'created_at']);
            $table->index(['borrower_id', 'status']);
        });

        $this->create('portal_request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('portal_requests')->restrictOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('actor_type', 10);
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('message')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['request_id', 'id']);
        });

        $this->create('portal_announcements', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 150);
            $table->text('body');
            $table->string('audience', 15)->default('all');
            $table->json('branch_ids')->nullable();
            $table->json('member_types')->nullable();
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('pinned')->default(false);
            $table->string('status', 12)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'publish_at']);
        });

        $this->create('portal_announcement_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_id')->constrained('portal_announcements')->cascadeOnDelete();
            $table->foreignId('portal_user_id')->constrained('portal_users')->restrictOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->unique(['announcement_id', 'portal_user_id']);
        });

        $this->create('portal_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portal_user_id')->constrained('portal_users')->restrictOnDelete();
            $table->char('key', 36);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['portal_user_id', 'key']);
        });

        if (! DB::table('portal_settings')->exists()) {
            DB::table('portal_settings')->insert([
                'enabled' => false,
                'portal_name' => 'Member Portal',
                'features' => json_encode(['loans' => true, 'share_capital' => true, 'savings' => true, 'time_deposits' => true, 'statements' => true, 'documents' => true, 'announcements' => true, 'account_requests' => true, 'profile_requests' => true, 'withdrawal_requests' => true]),
                'dashboard_cards' => json_encode(['share_capital', 'savings', 'time_deposits', 'loans', 'next_payment', 'announcements', 'recent_activity']),
                'withdrawal_methods' => json_encode(['cash_pickup', 'bank_transfer', 'gcash']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Only safe on a deployment where nobody was ever invited: a portal user
     * and its history are records of who could see a member's data.
     */
    public function down(): void
    {
        if (Schema::hasTable('portal_users') && DB::table('portal_users')->exists()) {
            return;
        }

        Schema::disableForeignKeyConstraints();

        foreach ([
            'portal_idempotency_keys', 'portal_announcement_reads', 'portal_announcements', 'portal_request_events',
            'portal_requests', 'portal_login_events', 'portal_otp_challenges', 'portal_codes', 'portal_users',
            'portal_terms_versions', 'portal_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }

    private function create(string $name, Closure $columns): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, $columns);
        }
    }
};

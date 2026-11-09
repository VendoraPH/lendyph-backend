<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment reminders (Phase 2, task 86d4ayucn). One cooperative per deployment,
 * so no tenant column. New tables only; nothing existing changes.
 *
 * - reminder_settings: the one settings row (seeded switched off).
 * - reminder_branch_settings: a branch's own sender, phone, email and
 *   payment instructions, or the company default.
 * - reminder_rules and reminder_templates: when to remind and what to say.
 * - reminder_queue_items: one row per reminder, keyed on loan, period number,
 *   due date, rule and channel, so planning twice plans it once.
 * - reminder_messages: what was handed to a driver, exactly, one per queue
 *   item; at most one per loan, channel and day.
 * - reminder_events: the append-only story of each reminder.
 * - reminder_pauses: global, member and loan pauses.
 * - borrower_notification_preferences: a member's switches and the contact
 *   values staff verified or a send proved bad.
 * - reminder_staff_notices: the once-only markers for staff notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reminder_settings')) {
            Schema::create('reminder_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('enabled')->default(false);
                $table->string('timezone', 64)->default('Asia/Manila');
                $table->time('default_send_time')->default('09:00:00');
                $table->boolean('sms_enabled')->default(true);
                $table->boolean('email_enabled')->default(true);
                $table->time('contact_hours_start')->default('08:00:00');
                $table->time('contact_hours_end')->default('18:00:00');
                $table->unsignedTinyInteger('max_attempts')->default(3);
                $table->unsignedSmallInteger('retry_delay_minutes')->default(15);
                $table->string('sms_provider', 32)->nullable();
                $table->string('sms_sender_id', 32)->nullable();
                $table->text('sms_api_key')->nullable();
                $table->text('sms_api_secret')->nullable();
                $table->decimal('sms_cost_per_part', 10, 4)->nullable();
                $table->string('email_provider', 32)->nullable();
                $table->string('email_sender_name', 120)->nullable();
                $table->string('email_sender_email', 191)->nullable();
                $table->string('email_reply_to', 191)->nullable();
                $table->text('email_api_key')->nullable();
                $table->text('payment_instructions')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('reminder_branch_settings')) {
            Schema::create('reminder_branch_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();
                $table->boolean('use_company_default')->default(true);
                $table->string('sender_name', 120)->nullable();
                $table->string('contact_phone', 50)->nullable();
                $table->string('contact_email', 191)->nullable();
                $table->text('payment_instructions')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('reminder_rules')) {
            Schema::create('reminder_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->enum('trigger', ['before_due', 'on_due', 'after_due']);
                $table->unsignedSmallInteger('days')->default(0);
                $table->time('send_time');
                $table->json('channels');
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('loan_product_id')->nullable()->constrained()->nullOnDelete();
                $table->enum('borrower_type', ['all', 'member', 'non_member'])->default('all');
                $table->string('template_type', 32);
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index('is_active');
            });
        }

        if (! Schema::hasTable('reminder_templates')) {
            Schema::create('reminder_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('type', 32);
                $table->enum('channel', ['sms', 'email']);
                $table->string('language', 8)->default('en');
                $table->string('subject', 191)->nullable();
                $table->text('body');
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['type', 'channel', 'language']);
            });
        }

        if (! Schema::hasTable('reminder_queue_items')) {
            Schema::create('reminder_queue_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
                $table->foreignId('borrower_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('amortization_schedule_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedInteger('period_number');
                $table->date('due_date');
                $table->foreignId('rule_id')->nullable()->constrained('reminder_rules')->nullOnDelete();
                $table->enum('channel', ['sms', 'email']);
                $table->string('template_type', 32);
                $table->decimal('amount_due', 12, 2)->nullable();
                $table->date('fire_date');
                $table->dateTime('scheduled_at');
                $table->enum('status', ['scheduled', 'queued', 'processing', 'sent', 'delivered', 'failed', 'cancelled', 'skipped'])->default('scheduled');
                $table->string('reason_code', 64)->nullable();
                $table->string('reason', 255)->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->dateTime('next_attempt_at')->nullable();
                $table->char('claim_token', 36)->nullable();
                $table->dateTime('claimed_at')->nullable();
                $table->dateTime('sending_started_at')->nullable();
                $table->string('recipient_masked', 191)->nullable();
                $table->enum('source', ['system', 'manual'])->default('system');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['loan_id', 'period_number', 'due_date', 'rule_id', 'channel'], 'reminder_queue_items_dedupe_unique');
                $table->index(['status', 'scheduled_at'], 'reminder_queue_items_status_scheduled_index');
                $table->index(['loan_id', 'channel', 'fire_date'], 'reminder_queue_items_loan_day_index');
                $table->index('claim_token');
            });
        }

        if (! Schema::hasTable('reminder_messages')) {
            Schema::create('reminder_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('queue_item_id')->unique()->constrained('reminder_queue_items')->cascadeOnDelete();
                $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
                $table->foreignId('borrower_id')->constrained()->cascadeOnDelete();
                $table->foreignId('rule_id')->nullable()->constrained('reminder_rules')->nullOnDelete();
                $table->unsignedInteger('period_number')->nullable();
                $table->enum('channel', ['sms', 'email']);
                $table->string('template_type', 32)->nullable();
                $table->string('language', 8)->default('en');
                $table->string('subject', 255)->nullable();
                $table->text('body')->nullable();
                $table->string('recipient', 191)->nullable();
                $table->string('recipient_masked', 191);
                $table->string('provider', 32);
                $table->string('provider_reference', 191)->nullable();
                $table->enum('status', ['pending', 'sent', 'delivered', 'failed', 'rejected', 'bounced', 'opened'])->default('pending');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->string('failure_reason', 255)->nullable();
                $table->unsignedSmallInteger('sms_parts')->nullable();
                $table->decimal('cost', 12, 4)->nullable();
                $table->boolean('test_mode')->default(true);
                $table->date('send_date')->nullable();
                $table->dateTime('scheduled_at')->nullable();
                $table->dateTime('sent_at')->nullable();
                $table->dateTime('delivered_at')->nullable();
                $table->dateTime('redacted_at')->nullable();
                $table->enum('source', ['system', 'manual'])->default('system');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                // At most one message per loan, channel and day.
                $table->unique(['loan_id', 'channel', 'send_date'], 'reminder_messages_daily_unique');
                $table->index(['status', 'sent_at']);
                $table->index('redacted_at');
            });
        }

        if (! Schema::hasTable('reminder_events')) {
            Schema::create('reminder_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('queue_item_id')->nullable()->constrained('reminder_queue_items')->nullOnDelete();
                $table->foreignId('message_id')->nullable()->constrained('reminder_messages')->nullOnDelete();
                $table->foreignId('loan_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('borrower_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('pause_id')->nullable();
                $table->string('kind', 32);
                $table->enum('channel', ['sms', 'email'])->nullable();
                $table->unsignedInteger('period_number')->nullable();
                $table->string('reason_code', 64)->nullable();
                $table->string('detail', 255)->nullable();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('created_at');

                $table->index(['loan_id', 'created_at']);
                $table->index(['borrower_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('reminder_pauses')) {
            Schema::create('reminder_pauses', function (Blueprint $table) {
                $table->id();
                $table->enum('scope', ['global', 'borrower', 'loan']);
                $table->foreignId('borrower_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('loan_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('reason', 255);
                $table->dateTime('paused_at');
                $table->dateTime('until')->nullable();
                $table->foreignId('paused_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('resumed_at')->nullable();
                $table->foreignId('resumed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['scope', 'resumed_at']);
            });
        }

        if (! Schema::hasTable('borrower_notification_preferences')) {
            Schema::create('borrower_notification_preferences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('borrower_id')->unique()->constrained()->cascadeOnDelete();
                $table->boolean('sms_enabled')->default(true);
                $table->boolean('email_enabled')->default(true);
                $table->enum('preferred_channel', ['sms', 'email', 'both'])->default('both');
                $table->string('language', 8)->default('en');
                $table->string('mobile_verified_value', 20)->nullable();
                $table->dateTime('mobile_verified_at')->nullable();
                $table->foreignId('mobile_verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('mobile_invalid_value', 20)->nullable();
                $table->string('mobile_invalid_reason', 255)->nullable();
                $table->dateTime('mobile_invalid_at')->nullable();
                $table->foreignId('mobile_invalid_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('email_verified_value', 191)->nullable();
                $table->dateTime('email_verified_at')->nullable();
                $table->foreignId('email_verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('email_invalid_value', 191)->nullable();
                $table->string('email_invalid_reason', 255)->nullable();
                $table->dateTime('email_invalid_at')->nullable();
                $table->foreignId('email_invalid_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('reminder_staff_notices')) {
            Schema::create('reminder_staff_notices', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 40);
                $table->string('subject', 191);
                $table->dateTime('created_at');
                $table->unique(['kind', 'subject']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_staff_notices');
        Schema::dropIfExists('borrower_notification_preferences');
        Schema::dropIfExists('reminder_pauses');
        Schema::dropIfExists('reminder_events');
        Schema::dropIfExists('reminder_messages');
        Schema::dropIfExists('reminder_queue_items');
        Schema::dropIfExists('reminder_templates');
        Schema::dropIfExists('reminder_rules');
        Schema::dropIfExists('reminder_branch_settings');
        Schema::dropIfExists('reminder_settings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loan origination's public intake (Phase 6, slice LO-B2; origination
 * decisions 6–8): the applicant's one-time codes and sessions.
 *
 * - A code is 6 digits, valid 5 minutes, 5 tries, and kept only as a hash.
 *   The mobile is kept only as its keyed hash (OriginationApplication::
 *   mobileHash()), never in plain text. `test_mode` marks a code shown on
 *   screen by the staging test-code mode instead of being sent.
 * - A session is a random token kept only as its SHA-256, tied to one link
 *   and one application. It ends 30 minutes after its last use, 4 hours
 *   after it began, or when a newer code is verified for the application.
 *
 * New tables only; no existing row changes. Each create is skipped when its
 * table exists, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('origination_otp_challenges')) {
            Schema::create('origination_otp_challenges', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('link_id')->constrained('origination_links')->cascadeOnDelete();
                $table->enum('purpose', ['apply', 'resume', 'track']);
                $table->enum('channel', ['sms']);
                $table->char('mobile_hash', 64);
                $table->string('reference_number', 30)->nullable();
                $table->string('code_hash', 255);
                $table->boolean('test_mode')->default(false);
                $table->boolean('delivered')->default(false);
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('expires_at');
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['link_id', 'purpose', 'mobile_hash', 'id'], 'origination_otp_challenges_lookup_index');
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('origination_sessions')) {
            Schema::create('origination_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('application_id')->constrained('origination_applications')->cascadeOnDelete();
                $table->foreignId('link_id')->constrained('origination_links')->cascadeOnDelete();
                $table->enum('purpose', ['apply', 'resume', 'track']);
                $table->char('token_hash', 64)->unique();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('last_used_at');
                $table->timestamp('expires_at');
                $table->timestamp('ended_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['application_id', 'ended_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('origination_sessions');
        Schema::dropIfExists('origination_otp_challenges');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loan origination staff processing (Phase 6, slice LO-B3): the log of
 * messages meant for an applicant (information requests, document requests,
 * decisions, status changes and resume reminders).
 *
 * Nothing can reach an applicant yet (origination §6: SMS and email wait for
 * the owner), so every row is `logged` and nothing is ever sent. `sent` is
 * kept in the ENUM for the day a real driver arrives. The body is stored with
 * Laravel's `encrypted` cast (TEXT), as the draft is. Rows are append-only
 * and go only with their application, through the retention erasure.
 *
 * New table only; running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('origination_applicant_messages')) {
            return;
        }

        Schema::create('origination_applicant_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('origination_applications')->cascadeOnDelete();
            $table->enum('kind', ['information_request', 'document_request', 'status_change', 'decision', 'resume_reminder']);
            $table->enum('channel', ['sms', 'email'])->nullable();
            $table->text('body');
            $table->json('document_types')->nullable();
            $table->enum('delivery', ['logged', 'sent'])->default('logged');
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['application_id', 'kind', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('origination_applicant_messages');
    }
};

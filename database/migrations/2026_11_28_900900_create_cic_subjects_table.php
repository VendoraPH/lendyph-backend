<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CIC subject register: each member, or co-maker, the lender has reported
 * (or captured for reporting), with the Provider Subject No it goes by. Once a
 * row exists its `subject_no` never changes and the row is never deleted, so
 * CIC always reads the same person under the same number; a member with a row
 * can't be purged (BorrowerPurgeService). Exactly one of `borrower_id` and
 * `co_maker_id` is set, matching `subject_type`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cic_subjects', function (Blueprint $table) {
            $table->id();
            $table->enum('subject_type', ['member', 'co_maker']);
            $table->foreignId('borrower_id')->nullable()->unique()->constrained('borrowers')->restrictOnDelete();
            $table->foreignId('co_maker_id')->nullable()->unique()->constrained('co_makers')->restrictOnDelete();
            $table->string('subject_no', 38)->unique();
            $table->dateTime('first_captured_at')->nullable();
            $table->foreignId('first_reported_submission_id')->nullable()->constrained('cic_submissions')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cic_subjects');
    }
};

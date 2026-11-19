<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 MB-BE1 (multi-branch decision 3): a request to close a branch,
     * made by one user and approved, rejected or cancelled by another (the
     * requester may only cancel). `pending_key` is the branch id while the
     * request waits and null after, so its unique index lets one request wait
     * per branch. Listed in TimezoneShift::EXCLUDED_COLUMNS (added after the
     * one-shot shift); `effective_on` is a DATE.
     */
    public function up(): void
    {
        Schema::create('branch_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->date('effective_on');
            $table->string('reason', 500);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->unsignedBigInteger('pending_key')->nullable()->storedAs("if(`status` = 'pending', `branch_id`, null)");
            $table->timestamps();

            $table->unique('pending_key', 'branch_closures_one_pending_unique');
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_closures');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per loan currently in a past-due episode that `notifications:past-due`
 * has already announced (decision N, Arvin, 2026-11-02). The command notifies a
 * loan only when it has no row here, and deletes the row once the loan is no
 * longer past due, so the next time it falls behind is a new episode and
 * notifies again. No backfill: the first run announces every loan past due
 * that day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_past_due_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('notified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_past_due_notices');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The label a loan shows while it waits at a step ("For Approval - <label>"),
 * snapshotted from the approval workflow setting's `steps.*.status` when the
 * chain is seeded. Kept apart from `status`, which is the step's progress enum
 * (waiting, pending, approved, sent_back).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_approval_steps', function (Blueprint $table) {
            $table->string('status_label', 100)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('loan_approval_steps', function (Blueprint $table) {
            $table->dropColumn('status_label');
        });
    }
};

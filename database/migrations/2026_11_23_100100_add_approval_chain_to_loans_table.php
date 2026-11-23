<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 MB-BE6 (multi-branch decision 10): the approval chain a loan was
     * seeded from, every step with its minimum principal, copied at submit.
     *
     * A step whose minimum is above the principal has no row in
     * `loan_approval_steps`, so this copy is what lets a re-submit seed the
     * chain again from the steps the loan started with, and lets a sign-off
     * notice a principal raised past a left-out step. Null for loans submitted
     * before this change, which keep their rows as they are.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->json('approval_chain')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('approval_chain');
        });
    }
};

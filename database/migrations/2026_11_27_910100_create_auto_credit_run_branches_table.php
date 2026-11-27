<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The branches a share capital auto-credit run covered (round 9): one row per
 * branch in its caller's BranchAccess scope at run time (every branch for
 * `branches:view_all`), written in the run's own transaction, so the status
 * can tell a branch's cycle from another branch's.
 *
 * New runs only. Runs made before have no rows and count only for callers with
 * `branches:view_all`, as they did; nothing is backfilled.
 *
 * Only adds, skipped when already there, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auto_credit_run_branches')) {
            return;
        }

        Schema::create('auto_credit_run_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('auto_credit_run_id')->constrained('auto_credit_runs')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->unique(['auto_credit_run_id', 'branch_id']);
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_credit_run_branches');
    }
};

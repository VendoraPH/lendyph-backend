<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The named user a step was forwarded to (LoanApprovalChainService). Null when
 * the step is for its role's holders, as every step was before. A deleted user
 * leaves the step unassigned, so it falls back to the role.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('loan_approval_steps', 'assigned_to')) {
            return;
        }

        Schema::table('loan_approval_steps', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->after('role')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('loan_approval_steps', 'assigned_to')) {
            return;
        }

        Schema::table('loan_approval_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to');
        });
    }
};

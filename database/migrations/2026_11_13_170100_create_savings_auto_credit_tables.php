<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-credit into savings (gap round S3, new records only):
 *
 * - `savings_auto_credit_instructions`: a member's standing instruction to
 *   credit an amount to one savings account on the 15th, the 30th (a
 *   month's last day) or both (`schedule` `15`, `30` or `15/30`, as share
 *   capital pledges store it), with who authorised it and when. At most one
 *   active instruction per account per slot, checked under the account lock.
 * - `savings_auto_credit_runs`: one row per processed run (its cycle, the
 *   date it posted, the settlement method and the figures), the record its
 *   audit row names.
 * - `savings_auto_credit_postings`: one row per instruction per cycle,
 *   linked to its deposit. `unique(instruction_id, cycle_date)` makes a
 *   re-run of the same cycle post nothing.
 *
 * Money is `decimal(12,2)` pesos (run totals `decimal(15,2)`), as in the
 * other savings tables. Each create is skipped when its table exists, so
 * running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('savings_auto_credit_instructions')) {
            Schema::create('savings_auto_credit_instructions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('account_id')->constrained('savings_accounts')->restrictOnDelete();
                $table->decimal('amount', 12, 2);
                $table->string('schedule', 8);
                $table->boolean('active')->default(true);
                $table->date('authorised_on');
                $table->string('authorised_by', 120);
                $table->string('remarks', 500)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->index(['account_id', 'active']);
                $table->index(['active', 'schedule']);
            });
        }

        if (! Schema::hasTable('savings_auto_credit_runs')) {
            Schema::create('savings_auto_credit_runs', function (Blueprint $table): void {
                $table->id();
                $table->date('cycle_date');
                $table->date('posted_on');
                $table->string('method', 24);
                $table->string('reference_number', 100)->nullable();
                $table->unsignedInteger('posted_count')->default(0);
                $table->decimal('posted_total', 15, 2)->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('created_at')->nullable();
                $table->index('cycle_date');
            });
        }

        if (! Schema::hasTable('savings_auto_credit_postings')) {
            Schema::create('savings_auto_credit_postings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('instruction_id')->constrained('savings_auto_credit_instructions')->restrictOnDelete();
                $table->foreignId('run_id')->constrained('savings_auto_credit_runs')->restrictOnDelete();
                $table->date('cycle_date');
                $table->foreignId('transaction_id')->unique()->constrained('savings_transactions')->restrictOnDelete();
                $table->decimal('amount', 12, 2);
                $table->timestamp('created_at')->nullable();
                $table->unique(['instruction_id', 'cycle_date'], 'savings_auto_credit_postings_cycle_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_auto_credit_postings');
        Schema::dropIfExists('savings_auto_credit_runs');
        Schema::dropIfExists('savings_auto_credit_instructions');
    }
};

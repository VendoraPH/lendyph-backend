<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Savings & Deposits v1 (Phase 3, approved 2026-11-10): the twelve tables of
 * the savings design, §1.
 *
 * - Money is `decimal(12,2)` pesos, like the lending tables (run totals
 *   `decimal(15,2)`); rates are `decimal(7,4)` percent; `balance_days` is a
 *   bigint of centavo-days.
 * - Status and type columns are `varchar(24)` checked against the models'
 *   constants, never ENUMs, so v2 can add values without an ALTER.
 * - Foreign keys restrict deletes, except a product's rate history, which
 *   goes with a product that never held an account.
 * - An account and an interest run always have a branch: books are kept per
 *   branch, and a row with none would post to no branch's books.
 * - `savings_transactions` is append-only (the model refuses updates and
 *   deletes); `unique(reverses_transaction_id)` lets a row be reversed once,
 *   and `unique(counterpart_transaction_id)` pairs a transfer leg once.
 *
 * Seeds the single settings row (tiers ₱10,000.00 and ₱50,000.00, two
 * checkers above, dormancy at 24 months, an alert at ₱500,000.00) and the
 * two tax rules (members 0%, walk-ins 20%), dated 2000-01-01 so a rule is in
 * force on every date. Only new tables; no existing row changes. Each create
 * is skipped when its table exists, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->create('savings_settings', function (Blueprint $table): void {
            $table->id();
            $table->decimal('tier1_limit', 12, 2)->default(10000);
            $table->decimal('tier2_limit', 12, 2)->default(50000);
            $table->unsignedTinyInteger('tier3_checkers')->default(2);
            $table->unsignedSmallInteger('dormancy_months')->default(24);
            $table->decimal('large_transaction_alert', 12, 2)->default(500000);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        $this->create('savings_products', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->string('kind', 24);
            $table->string('interest_crediting', 24)->default('quarterly');
            $table->decimal('minimum_opening_balance', 12, 2)->default(0);
            $table->decimal('maintaining_balance', 12, 2)->default(0);
            $table->decimal('minimum_adb_for_interest', 12, 2)->default(0);
            $table->json('term_options_days')->nullable();
            $table->foreignId('pretermination_product_id')->nullable()->constrained('savings_products')->restrictOnDelete();
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        $this->create('savings_product_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('savings_products')->cascadeOnDelete();
            $table->unsignedSmallInteger('term_days')->nullable();
            $table->decimal('annual_rate', 7, 4);
            $table->date('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['product_id', 'term_days', 'effective_from', 'id'], 'savings_product_rates_lookup_index');
        });

        $this->create('savings_tax_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('holder_type', 24);
            $table->decimal('rate', 7, 4);
            $table->date('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['holder_type', 'effective_from', 'id'], 'savings_tax_rules_lookup_index');
        });

        $this->create('savings_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('account_number', 20)->unique();
            $table->foreignId('borrower_id')->constrained('borrowers')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('savings_products')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('status', 24)->default('active');
            $table->decimal('ledger_balance', 12, 2)->default(0);
            $table->date('opened_on');
            $table->date('last_member_activity_on')->nullable();
            $table->date('dormant_since')->nullable();
            $table->date('interest_credited_through')->nullable();
            $table->date('closed_on')->nullable();
            $table->string('close_reason', 500)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('open_key', 64)->nullable()->unique();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
            $table->index(['product_id', 'status']);
            $table->index(['status', 'last_member_activity_on']);
        });

        $this->create('savings_approvals', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 24);
            $table->foreignId('account_id')->nullable()->constrained('savings_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('time_deposit_id')->nullable();
            $table->unsignedBigInteger('target_transaction_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->json('payload')->nullable();
            $table->unsignedTinyInteger('required_checkers')->default(1);
            $table->string('status', 24)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('first_checker_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('live_key', 64)->nullable()->unique();
            $table->unsignedBigInteger('result_transaction_id')->nullable();
            $table->timestamps();
            $table->index(['status', 'kind']);
            $table->index(['account_id', 'status']);
        });

        $this->create('time_deposits', function (Blueprint $table): void {
            $table->id();
            $table->string('certificate_number', 20)->unique();
            $table->foreignId('borrower_id')->constrained('borrowers')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('savings_products')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->decimal('principal', 12, 2);
            $table->decimal('annual_rate', 7, 4);
            $table->decimal('pretermination_rate', 7, 4)->nullable();
            $table->decimal('withholding_tax_rate', 7, 4)->default(0);
            $table->unsignedSmallInteger('term_days');
            $table->date('placement_date');
            $table->date('maturity_date');
            $table->decimal('gross_interest', 12, 2)->default(0);
            $table->decimal('withholding_tax', 12, 2)->default(0);
            $table->decimal('net_interest', 12, 2)->default(0);
            $table->decimal('maturity_amount', 12, 2)->default(0);
            $table->string('maturity_instruction', 24)->default('payout');
            $table->string('funding_method', 24);
            $table->unsignedBigInteger('funding_transaction_id')->nullable();
            $table->foreignId('payout_account_id')->nullable()->constrained('savings_accounts')->restrictOnDelete();
            $table->string('status', 24)->default('active');
            $table->date('settled_on')->nullable();
            $table->decimal('settled_interest', 12, 2)->nullable();
            $table->decimal('settled_tax', 12, 2)->nullable();
            $table->decimal('settled_amount', 12, 2)->nullable();
            $table->string('payout_method', 24)->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('rolled_over_from_id')->nullable()->unique()->constrained('time_deposits')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
            $table->index(['status', 'maturity_date']);
        });

        $this->create('savings_interest_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('savings_products')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 24)->default('draft');
            $table->string('live_key', 64)->nullable()->unique();
            $table->unsignedInteger('account_count')->default(0);
            $table->decimal('total_gross', 15, 2)->default(0);
            $table->decimal('total_tax', 15, 2)->default(0);
            $table->decimal('total_net', 15, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('checked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['product_id', 'branch_id', 'status', 'period_end'], 'savings_interest_runs_latest_index');
        });

        $this->create('savings_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('savings_accounts')->restrictOnDelete();
            $table->string('reference', 32)->unique();
            $table->string('type', 24);
            $table->date('date');
            $table->date('value_date');
            $table->decimal('debit', 12, 2)->default(0);
            $table->decimal('credit', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 2);
            $table->string('method', 24)->nullable();
            $table->string('external_reference', 100)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('counterpart_transaction_id')->nullable()->unique()->constrained('savings_transactions')->restrictOnDelete();
            $table->foreignId('reverses_transaction_id')->nullable()->unique()->constrained('savings_transactions')->restrictOnDelete();
            $table->foreignId('time_deposit_id')->nullable()->constrained('time_deposits')->restrictOnDelete();
            $table->foreignId('interest_run_id')->nullable()->constrained('savings_interest_runs')->restrictOnDelete();
            $table->foreignId('approval_id')->nullable()->constrained('savings_approvals')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['account_id', 'date', 'id']);
            $table->index(['account_id', 'value_date']);
            $table->index(['date', 'type']);
        });

        $this->create('savings_interest_run_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('savings_interest_runs')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('savings_accounts')->restrictOnDelete();
            $table->unsignedSmallInteger('days');
            $table->unsignedBigInteger('balance_days');
            $table->decimal('average_daily_balance', 12, 2);
            $table->decimal('rate', 7, 4);
            $table->json('rate_segments')->nullable();
            $table->decimal('gross_interest', 12, 2)->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('withholding_tax', 12, 2)->default(0);
            $table->decimal('net_interest', 12, 2)->default(0);
            $table->string('skip_reason', 64)->nullable();
            $table->foreignId('interest_transaction_id')->nullable()->constrained('savings_transactions')->restrictOnDelete();
            $table->foreignId('tax_transaction_id')->nullable()->constrained('savings_transactions')->restrictOnDelete();
            $table->unique(['run_id', 'account_id']);
        });

        $this->create('savings_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('savings_accounts')->restrictOnDelete();
            $table->string('type', 24);
            $table->decimal('amount', 12, 2);
            $table->string('reason', 500);
            $table->date('placed_on');
            $table->date('expires_on')->nullable();
            $table->string('status', 24)->default('active');
            $table->date('released_on')->nullable();
            $table->string('release_reason', 500)->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('source_type', 191)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['account_id', 'status']);
            $table->index(['status', 'expires_on']);
        });

        $this->create('savings_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('key', 36);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'key']);
        });

        $this->addForeignKeys();
        $this->seedDefaults();
    }

    /**
     * Nothing to undo once a deployment holds savings: dropping the tables
     * would destroy members' money records. The only safe undo is on a
     * database with no savings rows at all, which is what this checks.
     */
    public function down(): void
    {
        if (Schema::hasTable('savings_transactions') && DB::table('savings_transactions')->exists()) {
            return;
        }

        Schema::disableForeignKeyConstraints();

        foreach ([
            'savings_idempotency_keys', 'savings_holds', 'savings_interest_run_lines', 'savings_transactions',
            'savings_interest_runs', 'time_deposits', 'savings_approvals', 'savings_accounts',
            'savings_tax_rules', 'savings_product_rates', 'savings_products', 'savings_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }

    private function create(string $name, Closure $columns): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, $columns);
        }
    }

    /** The keys between tables created in either order. */
    private function addForeignKeys(): void
    {
        $keys = [
            ['savings_approvals', 'time_deposit_id', 'time_deposits'],
            ['savings_approvals', 'target_transaction_id', 'savings_transactions'],
            ['savings_approvals', 'result_transaction_id', 'savings_transactions'],
            ['time_deposits', 'funding_transaction_id', 'savings_transactions'],
        ];

        foreach ($keys as [$table, $column, $references]) {
            $name = "{$table}_{$column}_foreign";
            $exists = DB::selectOne(
                'select 1 as found from information_schema.TABLE_CONSTRAINTS where TABLE_SCHEMA = database() and TABLE_NAME = ? and CONSTRAINT_NAME = ?',
                [$table, $name],
            );

            if ($exists === null) {
                Schema::table($table, function (Blueprint $blueprint) use ($column, $references): void {
                    $blueprint->foreign($column)->references('id')->on($references)->restrictOnDelete();
                });
            }
        }
    }

    private function seedDefaults(): void
    {
        $now = now();

        if (! DB::table('savings_settings')->exists()) {
            DB::table('savings_settings')->insert([
                'tier1_limit' => '10000.00',
                'tier2_limit' => '50000.00',
                'tier3_checkers' => 2,
                'dormancy_months' => 24,
                'large_transaction_alert' => '500000.00',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! DB::table('savings_tax_rules')->exists()) {
            DB::table('savings_tax_rules')->insert([
                ['holder_type' => 'member', 'rate' => '0.0000', 'effective_from' => '2000-01-01', 'created_by' => null, 'created_at' => $now],
                ['holder_type' => 'non_member', 'rate' => '20.0000', 'effective_from' => '2000-01-01', 'created_by' => null, 'created_at' => $now],
            ]);
        }
    }
};

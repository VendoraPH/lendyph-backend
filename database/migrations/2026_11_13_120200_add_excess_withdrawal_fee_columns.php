<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The excess-withdrawal fee (gap round S1, new records only). A withdrawal's
 * fee is decided once, when it is requested, and the withdrawal counts
 * against the free withdrawals of the month it was requested in:
 *
 * - `savings_approvals` and `portal_requests`: `fee_amount` (the fee decided
 *   at request, reserved beside the amount; ₱0.00 on every existing
 *   request), `counted_on` (the date it counts in), `requested_at` (when it
 *   was asked for, which orders the count at payout) and the product's rule
 *   then, `rule_free_withdrawals` and `rule_excess_fee`, so a changed rule
 *   can be told apart at payout;
 * - `savings_transactions.counted_on` and `requested_at`: on a withdrawal,
 *   the date its request counts in and when it was asked for (null on
 *   existing rows, which count by `date` and `created_at`);
 * - `savings_transactions.fee_for_transaction_id`: a `fee` row names the
 *   withdrawal it was charged on, once (unique).
 *
 * Each column is added only when it is missing, on its own, so a database
 * that ran an earlier version of this migration gets the rest, and running
 * it again changes nothing.
 */
return new class extends Migration
{
    /**
     * table => column => how to add it.
     *
     * @return array<string, array<string, Closure(Blueprint): void>>
     */
    private function columns(): array
    {
        $request = [
            'fee_amount' => fn (Blueprint $table) => $table->decimal('fee_amount', 15, 2)->default(0)->after('amount'),
            'counted_on' => fn (Blueprint $table) => $table->date('counted_on')->nullable(),
            'requested_at' => fn (Blueprint $table) => $table->timestamp('requested_at')->nullable(),
            'rule_free_withdrawals' => fn (Blueprint $table) => $table->unsignedSmallInteger('rule_free_withdrawals')->nullable(),
            'rule_excess_fee' => fn (Blueprint $table) => $table->decimal('rule_excess_fee', 12, 2)->nullable(),
        ];

        return [
            'savings_approvals' => $request,
            'portal_requests' => $request,
            'savings_transactions' => [
                'counted_on' => fn (Blueprint $table) => $table->date('counted_on')->nullable(),
                'requested_at' => fn (Blueprint $table) => $table->timestamp('requested_at')->nullable(),
                'fee_for_transaction_id' => fn (Blueprint $table) => $table->foreignId('fee_for_transaction_id')->nullable()->unique()->constrained('savings_transactions')->restrictOnDelete(),
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->columns() as $name => $columns) {
            foreach ($columns as $column => $add) {
                if (! Schema::hasColumn($name, $column)) {
                    Schema::table($name, $add);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->columns(), true) as $name => $columns) {
            foreach (array_keys($columns) as $column) {
                if (! Schema::hasColumn($name, $column)) {
                    continue;
                }

                Schema::table($name, function (Blueprint $table) use ($column): void {
                    if ($column === 'fee_for_transaction_id') {
                        $table->dropConstrainedForeignId($column);
                    } else {
                        $table->dropColumn($column);
                    }
                });
            }
        }
    }
};

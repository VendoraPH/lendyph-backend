<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finish run round 6: an approved adjustment that isn't applied yet can be
 * cancelled with a reason (LoanAdjustmentService::cancelAdjustment()).
 *
 * `loan_adjustments.status` is a MySQL ENUM, so `cancelled` takes a raw
 * MODIFY that lists every value. As the `loan_approval_steps` migration does,
 * the list is read from the live column, so every value a deployment holds is
 * kept in its order with `cancelled` added at the end, and the column keeps
 * its `pending` default. A column that already has `cancelled` is left alone.
 *
 * `cancelled_by`, `cancelled_at` and `cancellation_reason` record who
 * cancelled it, when and why; all nullable, so no row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $values = $this->statusValues();

        if ($values !== [] && ! in_array('cancelled', $values, true)) {
            $this->modifyStatus([...$values, 'cancelled']);
        }

        Schema::table('loan_adjustments', function (Blueprint $table) {
            $table->foreignId('cancelled_by')->nullable()->after('applied_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_at');
        });
    }

    /**
     * Drops the three columns and narrows the enum back. Refused while any
     * adjustment is cancelled: narrowing would fail on it, and turning it into
     * another status would rewrite its history.
     */
    public function down(): void
    {
        if (DB::table('loan_adjustments')->where('status', 'cancelled')->exists()) {
            throw new RuntimeException('Some loan adjustments are cancelled; this migration cannot be rolled back without rewriting them.');
        }

        Schema::table('loan_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });

        $values = $this->statusValues();

        if (in_array('cancelled', $values, true)) {
            $this->modifyStatus(array_values(array_diff($values, ['cancelled'])));
        }
    }

    /** @return list<string> */
    private function statusValues(): array
    {
        $column = DB::selectOne(
            'select COLUMN_TYPE from information_schema.COLUMNS
             where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
            ['loan_adjustments', 'status'],
        );

        if ($column === null) {
            return [];
        }

        preg_match_all("/'((?:[^']|'')*)'/", (string) $column->COLUMN_TYPE, $matches);

        return array_map(static fn (string $value): string => str_replace("''", "'", $value), $matches[1]);
    }

    /** @param  list<string>  $values */
    private function modifyStatus(array $values): void
    {
        $list = implode(',', array_map(static fn (string $value): string => DB::getPdo()->quote($value), $values));

        DB::statement("ALTER TABLE loan_adjustments MODIFY status ENUM({$list}) NOT NULL DEFAULT 'pending'");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finish run round 2, decisions 5 and 11.
 *
 * - `cash_reference_sequences`: one row per numbering series, the last number
 *   given in it. A series is a prefix (`CSH` for cash share capital entries,
 *   `TD` for cash time deposit placements), the primary super admin's user id
 *   and a year (App\Services\CashReferences). The unique index is what keeps
 *   two first saves of a series from both starting it. No timestamps: the
 *   numbered record keeps its own.
 * - `time_deposits.reference`: the payment reference of a placement, which
 *   PlaceTimeDepositRequest took and nothing saved. A cash placement's is
 *   its `TD-` number.
 * - Two indexes for the duplicate reference check (App\Services\Savings\
 *   DuplicateReference): a reference is looked up by its method on savings
 *   transactions and on placements.
 *
 * Adds a table, a column and indexes only: no row is written or changed, so
 * there is nothing to preview or audit. The savings transactions index is
 * built online on MySQL (ALGORITHM=INPLACE, LOCK=NONE). Each step runs only
 * when missing, so running it again changes nothing.
 */
return new class extends Migration
{
    private const SERIES_INDEX = 'cash_reference_sequences_series_unique';

    private const PLACEMENT_INDEX = 'time_deposits_funding_method_reference_index';

    private const SAVINGS_INDEX = 'savings_transactions_method_external_reference_index';

    public function up(): void
    {
        if (! Schema::hasTable('cash_reference_sequences')) {
            Schema::create('cash_reference_sequences', function (Blueprint $table): void {
                $table->id();
                $table->string('prefix', 8);
                $table->unsignedBigInteger('owner_id');
                $table->unsignedSmallInteger('year');
                $table->unsignedInteger('last_number')->default(0);
                $table->unique(['prefix', 'owner_id', 'year'], self::SERIES_INDEX);
            });
        }

        if (! Schema::hasColumn('time_deposits', 'reference')) {
            Schema::table('time_deposits', fn (Blueprint $table) => $table->string('reference', 100)->nullable()->after('funding_method'));
        }

        if (! Schema::hasIndex('time_deposits', self::PLACEMENT_INDEX)) {
            Schema::table('time_deposits', fn (Blueprint $table) => $table->index(['funding_method', 'reference'], self::PLACEMENT_INDEX));
        }

        if (! Schema::hasIndex('savings_transactions', self::SAVINGS_INDEX)) {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE `savings_transactions` ADD INDEX `'.self::SAVINGS_INDEX.'` (`method`, `external_reference`), ALGORITHM=INPLACE, LOCK=NONE');
            } else {
                Schema::table('savings_transactions', fn (Blueprint $table) => $table->index(['method', 'external_reference'], self::SAVINGS_INDEX));
            }
        }
    }

    /**
     * Nothing to undo. Dropping the series would let numbers already given be
     * given again, and dropping the column would erase the references saved
     * on placements since.
     */
    public function down(): void {}
};

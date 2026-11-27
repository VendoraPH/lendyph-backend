<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An index on `gcash_transactions.paid_at` for the GCash income report, which
 * counts a Cash In's charge on the day it was paid (Finish run round 2,
 * decision 2: GCashService::incomeReport() filters a `paid_at` range).
 *
 * A bare `paid_at`, not one led by `branch_id`: the report's branch scope is
 * an OR (the row's own branch, or a member row's member branch), which a
 * `branch_id`-led index can't serve, and `paid_at` is set only on paid Cash
 * Ins, so the range alone finds exactly the rows the report counts.
 *
 * Built online on MySQL (ALGORITHM=INPLACE, LOCK=NONE): reads and writes go on
 * while a populated table is indexed, and MySQL refuses the statement rather
 * than lock the table if it ever couldn't. Guarded on Schema::hasIndex() both
 * ways, so a re-run or a drifted box changes nothing.
 */
return new class extends Migration
{
    private const TABLE = 'gcash_transactions';

    private const INDEX = 'gcash_transactions_paid_at_index';

    public function up(): void
    {
        if (Schema::hasIndex(self::TABLE, self::INDEX)) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `'.self::TABLE.'` ADD INDEX `'.self::INDEX.'` (`paid_at`), ALGORITHM=INPLACE, LOCK=NONE');

            return;
        }

        Schema::table(self::TABLE, fn (Blueprint $table) => $table->index('paid_at', self::INDEX));
    }

    public function down(): void
    {
        if (! Schema::hasIndex(self::TABLE, self::INDEX)) {
            return;
        }

        Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex(self::INDEX));
    }
};

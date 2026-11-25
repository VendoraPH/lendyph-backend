<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finish run round 5: the reference of a time deposit's payout.
 *
 * - `time_deposits.payout_reference`: the reference of the money paid out at
 *   maturity or on pre-termination by bank transfer, GCash or Maya (required
 *   for those methods, App\Http\Requests\Savings\SavingsReference), beside
 *   the placement's `payout_method`. Null for cash given none, a payout into
 *   savings, a full rollover and every placement settled before this.
 * - An index for the duplicate reference check (App\Services\Savings\
 *   DuplicateReference), which looks a reference up by its method on
 *   payouts too.
 *
 * Adds a column and an index only: no row is written or changed, so there is
 * nothing to preview or audit. Each step runs only when missing, so running
 * it again changes nothing.
 */
return new class extends Migration
{
    private const PAYOUT_INDEX = 'time_deposits_payout_method_reference_index';

    public function up(): void
    {
        if (! Schema::hasColumn('time_deposits', 'payout_reference')) {
            Schema::table('time_deposits', fn (Blueprint $table) => $table->string('payout_reference', 100)->nullable()->after('payout_method'));
        }

        if (! Schema::hasIndex('time_deposits', self::PAYOUT_INDEX)) {
            Schema::table('time_deposits', fn (Blueprint $table) => $table->index(['payout_method', 'payout_reference'], self::PAYOUT_INDEX));
        }
    }

    /**
     * Nothing to undo: dropping the column would erase the payout references
     * saved since.
     */
    public function down(): void {}
};

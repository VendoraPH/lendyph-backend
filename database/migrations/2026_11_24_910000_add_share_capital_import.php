<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finish run round 2, decision 13: Share Capital, the third Data Import file.
 *
 * - `csv_import_files.kind` takes `share_capital`, and `csv_import_runs.phase`
 *   takes `importing_share_capital`, a run's step after its loans.
 * - `share_capital_ledger.is_imported`: an entry the import brought over from
 *   the cooperative's earlier books. Kept on the entry itself, so it outlives
 *   the import's own records, as an imported loan's markers do.
 * - `share_capital_ledger.reference_number` is indexed: the import looks each
 *   row's reference up on the ledger to refuse a duplicate.
 * - `csv_import_rows.share_capital_ledger_id`: the entry a staged row wrote,
 *   as `borrower_id` and `loan_id` keep the member and loan theirs did.
 *   Emptied, never cascaded, if the entry is deleted with its member.
 *
 * Widens two value lists and adds columns and an index: no row is written or
 * changed, so there is nothing to preview or audit. Each step runs only when
 * it is missing, so running it again changes nothing.
 */
return new class extends Migration
{
    private const KINDS = ['customers', 'loans'];

    private const PHASES = [
        'uploading',
        'assembled',
        'staging',
        'awaiting_mapping',
        'importing_customers',
        'importing_loans',
        'completed',
        'failed',
        'cancelled',
    ];

    private const REFERENCE_INDEX = 'share_capital_ledger_reference_number_index';

    public function up(): void
    {
        Schema::table('csv_import_files', fn (Blueprint $table) => $table->enum('kind', [...self::KINDS, 'share_capital'])->change());

        Schema::table('csv_import_runs', fn (Blueprint $table) => $table->enum('phase', $this->phasesWithShareCapital())->default('uploading')->change());

        if (! Schema::hasColumn('share_capital_ledger', 'is_imported')) {
            Schema::table('share_capital_ledger', fn (Blueprint $table) => $table->boolean('is_imported')->default(false)->after('created_by'));
        }

        if (! Schema::hasIndex('share_capital_ledger', self::REFERENCE_INDEX)) {
            Schema::table('share_capital_ledger', fn (Blueprint $table) => $table->index('reference_number', self::REFERENCE_INDEX));
        }

        if (! Schema::hasColumn('csv_import_rows', 'share_capital_ledger_id')) {
            Schema::table('csv_import_rows', fn (Blueprint $table) => $table->foreignId('share_capital_ledger_id')
                ->nullable()
                ->after('loan_id')
                ->constrained('share_capital_ledger')
                ->nullOnDelete());
        }
    }

    /**
     * The value lists narrow back only while nothing holds what they gained:
     * a row naming `share_capital` would otherwise be refused by the change.
     */
    public function down(): void
    {
        if (Schema::hasColumn('csv_import_rows', 'share_capital_ledger_id')) {
            Schema::table('csv_import_rows', fn (Blueprint $table) => $table->dropConstrainedForeignId('share_capital_ledger_id'));
        }

        if (Schema::hasIndex('share_capital_ledger', self::REFERENCE_INDEX)) {
            Schema::table('share_capital_ledger', fn (Blueprint $table) => $table->dropIndex(self::REFERENCE_INDEX));
        }

        if (Schema::hasColumn('share_capital_ledger', 'is_imported')) {
            Schema::table('share_capital_ledger', fn (Blueprint $table) => $table->dropColumn('is_imported'));
        }

        if (! DB::table('csv_import_files')->where('kind', 'share_capital')->exists()) {
            Schema::table('csv_import_files', fn (Blueprint $table) => $table->enum('kind', self::KINDS)->change());
        }

        if (! DB::table('csv_import_runs')->where('phase', 'importing_share_capital')->exists()) {
            Schema::table('csv_import_runs', fn (Blueprint $table) => $table->enum('phase', self::PHASES)->default('uploading')->change());
        }
    }

    /**
     * Today's phases with `importing_share_capital` after `importing_loans`,
     * the order a run moves through them.
     *
     * @return list<string>
     */
    private function phasesWithShareCapital(): array
    {
        $phases = self::PHASES;
        array_splice($phases, array_search('importing_loans', $phases, true) + 1, 0, ['importing_share_capital']);

        return $phases;
    }
};

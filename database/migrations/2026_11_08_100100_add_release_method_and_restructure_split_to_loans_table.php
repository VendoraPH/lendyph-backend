<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The columns Phase 2's release and restructure postings read, all
     * nullable, so every existing loan keeps exactly the figures it had:
     *
     * - `release_method` and `release_reference`: how a release paid out (or,
     *   on a restructure, collected the deductions), in the Record Payment
     *   vocabulary. Null on loans released before it was recorded, which read
     *   "Not recorded".
     * - `restructure_carried_principal`, `restructure_capitalised_interest`,
     *   `restructure_capitalised_penalty` and
     *   `restructure_written_off_principal`: a restructure's split of the new
     *   loan's principal, stored at release. Null on every other loan and on
     *   restructures released before this, which post no deferral.
     *
     * Adds columns only: no row is written or changed, so there is nothing to
     * audit or preview. Each column is added only when missing, so running it
     * again changes nothing.
     */
    public function up(): void
    {
        $columns = [
            'release_method' => fn (Blueprint $table) => $table->string('release_method', 30)->nullable()->after('released_at'),
            'release_reference' => fn (Blueprint $table) => $table->string('release_reference', 100)->nullable()->after('release_method'),
            'restructure_carried_principal' => fn (Blueprint $table) => $table->decimal('restructure_carried_principal', 15, 2)->nullable()->after('restructure_shortfall'),
            'restructure_capitalised_interest' => fn (Blueprint $table) => $table->decimal('restructure_capitalised_interest', 15, 2)->nullable()->after('restructure_carried_principal'),
            'restructure_capitalised_penalty' => fn (Blueprint $table) => $table->decimal('restructure_capitalised_penalty', 15, 2)->nullable()->after('restructure_capitalised_interest'),
            'restructure_written_off_principal' => fn (Blueprint $table) => $table->decimal('restructure_written_off_principal', 15, 2)->nullable()->after('restructure_capitalised_penalty'),
        ];

        foreach ($columns as $column => $add) {
            if (! Schema::hasColumn('loans', $column)) {
                Schema::table('loans', $add);
            }
        }
    }

    /**
     * Nothing to undo. Dropping the columns would erase the release methods
     * and restructure splits recorded since, which the books were posted from.
     */
    public function down(): void {}
};

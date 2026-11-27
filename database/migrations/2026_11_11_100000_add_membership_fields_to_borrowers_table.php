<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CDA Reports v1 (Phase 3, design §1): a member's membership date, type
 * (regular or associate), membership number and how their membership ended,
 * and the `withdrawn` and `deceased` statuses.
 *
 * Only adds: every existing row keeps its values (`member_type` reads
 * `regular`, the rest null). Filling the dates and numbers of existing
 * members is the `members:backfill-membership-fields` data fix, run by hand
 * after its dry-run. Each column is added only when missing, and the status
 * list is rewritten to the same list, so running it again changes nothing.
 */
return new class extends Migration
{
    private const STATUSES = ['active', 'inactive', 'blacklisted', 'pending', 'rejected', 'withdrawn', 'deceased'];

    private const PREVIOUS_STATUSES = ['active', 'inactive', 'blacklisted', 'pending', 'rejected'];

    public function up(): void
    {
        Schema::table('borrowers', function (Blueprint $table): void {
            if (! Schema::hasColumn('borrowers', 'membership_date')) {
                $table->date('membership_date')->nullable()->after('approved_by');
                $table->index('membership_date');
            }

            if (! Schema::hasColumn('borrowers', 'member_type')) {
                $table->string('member_type', 12)->default('regular')->after('membership_date');
            }

            if (! Schema::hasColumn('borrowers', 'membership_number')) {
                $table->string('membership_number', 30)->nullable()->unique()->after('member_type');
            }

            if (! Schema::hasColumn('borrowers', 'membership_ended_on')) {
                $table->date('membership_ended_on')->nullable()->after('membership_number');
            }

            if (! Schema::hasColumn('borrowers', 'membership_end_reason')) {
                $table->string('membership_end_reason', 255)->nullable()->after('membership_ended_on');
            }
        });

        $this->statuses(self::STATUSES);
    }

    /**
     * Refused while any member is withdrawn or deceased: dropping the values
     * would make MySQL blank those rows' status.
     */
    public function down(): void
    {
        if (DB::table('borrowers')->whereIn('status', ['withdrawn', 'deceased'])->exists()) {
            throw new RuntimeException('Some members are withdrawn or deceased; their status would be lost. Reactivate them first.');
        }

        $this->statuses(self::PREVIOUS_STATUSES);

        Schema::table('borrowers', function (Blueprint $table): void {
            foreach (['membership_end_reason', 'membership_ended_on', 'member_type'] as $column) {
                if (Schema::hasColumn('borrowers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('borrowers', 'membership_number')) {
            Schema::table('borrowers', function (Blueprint $table): void {
                $table->dropUnique(['membership_number']);
                $table->dropColumn('membership_number');
            });
        }

        if (Schema::hasColumn('borrowers', 'membership_date')) {
            Schema::table('borrowers', function (Blueprint $table): void {
                $table->dropIndex(['membership_date']);
                $table->dropColumn('membership_date');
            });
        }
    }

    /** @param  list<string>  $statuses */
    private function statuses(array $statuses): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $list = implode(', ', array_map(fn (string $status) => "'{$status}'", $statuses));

        DB::statement("ALTER TABLE borrowers MODIFY status ENUM({$list}) NOT NULL DEFAULT 'active'");
    }
};

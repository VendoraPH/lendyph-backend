<?php

use App\Services\TimezoneShift;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Move pre-cutover instants from UTC wall clocks into Asia/Manila wall clocks.
 *
 * This ships in the same release as the `Asia/Manila` config change on purpose.
 * The moment the application starts rendering stored wall clocks as local time,
 * every row written by the old UTC application reads eight hours early; if the
 * two landed separately there would be a window where the app reads Manila and
 * the data has not moved.
 *
 * It is deliberately the last migration in the branch so it runs after the
 * schema changes it depends on, and it filters on a cutover instant so a request
 * that sneaks in between the deploy and the migration is not shifted along with
 * the history — see {@see TimezoneShift::cutover()}.
 *
 * Everything about which columns move, which do not, and why lives on
 * {@see TimezoneShift}.
 *
 * ## Why the marker is claimed before any data moves
 *
 * MySQL has no transactional DDL, so Laravel does not wrap `up()` in a
 * transaction, and `Migrator::runUp()` only records the migration once `up()`
 * returns. A run killed partway — deploy timeout, lock wait, OOM, dropped
 * connection — therefore leaves some tables shifted, nothing in the `migrations`
 * table, and nothing to stop an operator re-running `migrate`. That second pass
 * would put the already-shifted tables at +16h, and a uniform `down()` cannot
 * repair a mixed state.
 *
 * So the marker row is written FIRST, in an `in_progress` state, and flipped to
 * `completed` only after every table has moved. A re-run that finds
 * `in_progress` aborts loudly and demands a human, instead of silently
 * double-shifting the audit trail.
 *
 * The marker also carries the per-table counts, so what was done to production
 * data stays reconstructable long after the log has rotated.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ensureMarkerTable();

        $marker = DB::table('timezone_shifts')->first();

        if ($marker !== null && $marker->status === 'completed') {
            return;
        }

        if ($marker !== null) {
            throw new RuntimeException(
                "A previous timestamp shift started at {$marker->started_at} and never completed. "
                .'The database is in a mixed state: some tables were moved by '
                ."{$marker->offset_minutes} minutes and some were not. Re-running would double-shift "
                .'the tables that already moved, including the audit trail. Inspect `timezone_shifts` '
                .'and reconcile by hand before continuing.'
            );
        }

        $shift = new TimezoneShift(DB::connection());
        $cutover = $shift->cutover();

        if (array_sum($shift->impact($cutover)) === 0) {
            // Nothing predates the cutover: a fresh install or a test database.
            return;
        }

        $shift->assertDatabaseClockIsUtc();

        $markerId = DB::table('timezone_shifts')->insertGetId([
            'offset_minutes' => TimezoneShift::OFFSET_MINUTES,
            'cutover_at' => $cutover->format('Y-m-d H:i:s'),
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        $updated = array_filter($shift->forward($cutover));
        $total = array_sum($updated);

        DB::table('timezone_shifts')->where('id', $markerId)->update([
            'status' => 'completed',
            'cells_updated' => $total,
            'details' => json_encode($updated),
            'completed_at' => now(),
        ]);

        Log::info('Shifted historical timestamps into the application timezone.', [
            'cutover_utc' => $cutover->format('Y-m-d H:i:s'),
            'offset_minutes' => TimezoneShift::OFFSET_MINUTES,
            'cells_updated_per_table' => $updated,
            'cells_updated_total' => $total,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('timezone_shifts')) {
            return;
        }

        $marker = DB::table('timezone_shifts')->first();

        if ($marker !== null && $marker->status !== 'completed') {
            throw new RuntimeException(
                'The timestamp shift never completed, so the database is in a mixed state and a '
                .'uniform reversal would corrupt it. Reconcile `timezone_shifts` by hand.'
            );
        }

        if ($marker !== null) {
            // Reversing is only correct if the application is going back to UTC
            // too. down() cannot revert config, so it refuses rather than leave
            // every timestamp reading eight hours early. Deploy pipelines pass
            // --force, which bypasses Laravel's interactive confirmation.
            if (config('app.timezone') !== 'UTC') {
                throw new RuntimeException(
                    'Refusing to reverse the timestamp shift while the application timezone is '
                    .config('app.timezone').'. Reverting the data without reverting the config would '
                    .'make every record read '.$marker->offset_minutes.' minutes early. '
                    .'Set APP_TIMEZONE=UTC first, then roll back.'
                );
            }

            $shift = new TimezoneShift(DB::connection());
            $shift->assertDatabaseClockIsUtc();

            $reverted = array_filter($shift->backward((int) $marker->offset_minutes));

            Log::info('Reverted historical timestamps to UTC.', [
                'offset_minutes' => -((int) $marker->offset_minutes),
                'cells_updated_per_table' => $reverted,
                'cells_updated_total' => array_sum($reverted),
            ]);
        }

        Schema::dropIfExists('timezone_shifts');
    }

    /**
     * The applied-shift marker: what stops a second pass, and the durable record
     * of what the first one did.
     */
    private function ensureMarkerTable(): void
    {
        if (Schema::hasTable('timezone_shifts')) {
            return;
        }

        Schema::create('timezone_shifts', function (Blueprint $table) {
            $table->id();
            $table->integer('offset_minutes');
            $table->dateTime('cutover_at')->comment('UTC instant separating pre- and post-cutover rows');
            $table->string('status', 20)->comment('in_progress until every table has moved');
            $table->unsignedBigInteger('cells_updated')->nullable();
            $table->json('details')->nullable()->comment('cells updated per table');
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
        });
    }
};

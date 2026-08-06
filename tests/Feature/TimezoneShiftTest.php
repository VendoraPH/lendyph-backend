<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Services\TimezoneShift;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The UTC → Asia/Manila historical correction.
 *
 * Two things have to hold and neither is negotiable: timezone-derived instants
 * move by exactly eight hours, and calendar DATE columns do not move at all.
 * Shifting a `payment_date` or a `due_date` would silently reschedule live
 * amortisation and move real payments to the wrong day.
 */
class TimezoneShiftTest extends TestCase
{
    use SetupLendyPH;

    /**
     * A UTC wall clock from well before the cutover, and the Manila wall clock
     * for the same instant.
     */
    private const PRE_CUTOVER_UTC = '2026-01-15 00:30:00';

    private const PRE_CUTOVER_MANILA = '2026-01-15 08:30:00';

    private const CALENDAR_DATE = '2026-01-15';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * The column list must be the real schema, not a guess that rotted.
     *
     * Every DATETIME/TIMESTAMP column in the database has to be either shifted
     * or explicitly excluded. A new one that is neither fails here rather than
     * being silently skipped.
     */
    public function test_the_column_map_matches_the_live_schema(): void
    {
        $actual = collect(DB::select(
            'select table_name as `table`, column_name as `column`
             from information_schema.columns
             where table_schema = database() and data_type in (?, ?)',
            ['datetime', 'timestamp'],
        ))->map(fn ($row) => "{$row->table}.{$row->column}")->sort()->values()->all();

        $accountedFor = collect(TimezoneShift::COLUMNS)
            ->merge(TimezoneShift::EXCLUDED_COLUMNS)
            ->flatMap(fn (array $columns, string $table) => array_map(
                fn (string $column) => "{$table}.{$column}",
                $columns,
            ))
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            $actual,
            $accountedFor,
            'Every datetime/timestamp column must be listed in TimezoneShift::COLUMNS or EXCLUDED_COLUMNS.',
        );
    }

    /**
     * No DATE column may ever appear in the shift map.
     */
    public function test_no_date_column_is_ever_shifted(): void
    {
        $dateColumns = collect(DB::select(
            'select table_name as `table`, column_name as `column`
             from information_schema.columns
             where table_schema = database() and data_type = ?',
            ['date'],
        ));

        $this->assertNotEmpty($dateColumns, 'Sanity: this schema does have DATE columns.');

        foreach ($dateColumns as $row) {
            $this->assertNotContains(
                $row->column,
                TimezoneShift::COLUMNS[$row->table] ?? [],
                "{$row->table}.{$row->column} is a calendar date and must never be shifted.",
            );
        }
    }

    /**
     * The headline case: a pre-cutover instant moves exactly eight hours, and
     * the calendar date sitting next to it in the same row does not move.
     */
    public function test_migration_moves_instants_eight_hours_and_leaves_dates_alone(): void
    {
        $repaymentId = $this->seedPreCutoverRepayment();

        $this->runMigrationUp();

        $row = DB::table('repayments')->where('id', $repaymentId)->first();

        $this->assertSame(
            self::PRE_CUTOVER_MANILA,
            $this->format($row->created_at),
            'A timezone-derived instant must move forward by exactly eight hours.',
        );

        $this->assertSame(
            self::CALENDAR_DATE,
            $this->format($row->payment_date, 'Y-m-d'),
            'payment_date is a calendar date the user chose. It must not move.',
        );
    }

    /**
     * The same guarantee across the columns that carry the loan lifecycle, plus
     * the audit trail that justifies a write-off.
     */
    public function test_it_shifts_loan_lifecycle_and_audit_columns(): void
    {
        $loan = $this->createReleasedLoan();

        DB::table('loans')->where('id', $loan->id)->update([
            'approved_at' => self::PRE_CUTOVER_UTC,
            'released_at' => self::PRE_CUTOVER_UTC,
            'created_at' => self::PRE_CUTOVER_UTC,
            'start_date' => self::CALENDAR_DATE,
            'maturity_date' => self::CALENDAR_DATE,
        ]);

        $auditId = DB::table('audit_logs')->insertGetId([
            'action' => 'restructure_closed',
            'auditable_type' => Loan::class,
            'auditable_id' => $loan->id,
            'created_at' => self::PRE_CUTOVER_UTC,
        ]);

        $this->runMigrationUp();

        $shifted = DB::table('loans')->where('id', $loan->id)->first();

        foreach (['approved_at', 'released_at', 'created_at'] as $column) {
            $this->assertSame(
                self::PRE_CUTOVER_MANILA,
                $this->format($shifted->{$column}),
                "loans.{$column} must move into the app timezone.",
            );
        }

        foreach (['start_date', 'maturity_date'] as $column) {
            $this->assertSame(
                self::CALENDAR_DATE,
                $this->format($shifted->{$column}, 'Y-m-d'),
                "loans.{$column} is a calendar date and must not move.",
            );
        }

        $this->assertSame(
            self::PRE_CUTOVER_MANILA,
            $this->format(DB::table('audit_logs')->where('id', $auditId)->value('created_at')),
            'The write-off justification record must move with everything else.',
        );
    }

    /**
     * Rows the new application already wrote in Manila time sit ahead of the
     * cutover and must be left exactly where they are.
     */
    public function test_it_skips_rows_written_after_the_cutover(): void
    {
        // A pre-cutover row so the migration actually does work — otherwise this
        // test would pass vacuously on the "nothing to do" early return.
        $repaymentId = $this->seedPreCutoverRepayment();

        // Seeded data was written by Eloquent moments ago, so it already carries
        // a Manila wall clock — eight hours ahead of the UTC cutover boundary.
        $before = DB::table('users')->orderBy('id')->pluck('created_at', 'id');
        $this->assertNotEmpty($before, 'Sanity: there are post-cutover rows to protect.');

        $this->runMigrationUp();

        $after = DB::table('users')->orderBy('id')->pluck('created_at', 'id');

        $this->assertEquals($before->all(), $after->all(), 'Post-cutover rows must not be touched.');

        $this->assertSame(
            self::PRE_CUTOVER_MANILA,
            $this->format(DB::table('repayments')->where('id', $repaymentId)->value('created_at')),
            'Sanity: the migration really did run and move the pre-cutover row.',
        );
    }

    /**
     * Running the shift a second time must not move anything again.
     *
     * The cutover boundary cannot provide this on its own — a January row moved
     * to 08:30 is still long before "now" and would match again — so the marker
     * is what actually stops it.
     */
    public function test_it_is_not_double_appliable(): void
    {
        $repaymentId = $this->seedPreCutoverRepayment();

        $this->runMigrationUp();
        $this->runMigrationUp();
        $this->runMigrationUp();

        $this->assertSame(
            self::PRE_CUTOVER_MANILA,
            $this->format(DB::table('repayments')->where('id', $repaymentId)->value('created_at')),
            'Repeated passes must be no-ops, not a sixteen- or twenty-four-hour shift.',
        );

        $this->assertSame(1, DB::table('timezone_shifts')->count(), 'Exactly one shift is recorded.');
    }

    /**
     * The marker records what was done, since the rows no longer show it.
     */
    public function test_it_records_what_it_did(): void
    {
        $this->seedPreCutoverRepayment();

        $this->runMigrationUp();

        $marker = DB::table('timezone_shifts')->sole();

        $this->assertSame(TimezoneShift::OFFSET_MINUTES, (int) $marker->offset_minutes);
        $this->assertSame('completed', $marker->status);
        $this->assertGreaterThan(0, (int) $marker->cells_updated);
        $this->assertNotNull($marker->cutover_at);
        $this->assertNotNull($marker->completed_at);

        $details = json_decode($marker->details, true);
        $this->assertIsArray($details);
        $this->assertArrayHasKey('repayments', $details, 'Per-table counts survive log rotation.');
    }

    /**
     * The failure mode that matters most.
     *
     * MySQL gives no transactional DDL, so a run killed partway leaves some
     * tables shifted, no row in the `migrations` table, and nothing stopping an
     * operator from re-running `migrate`. That second pass would put the
     * already-moved tables at +16h — and `down()` subtracts a uniform offset, so
     * it cannot repair a mixed state. The migration must refuse instead.
     */
    public function test_it_aborts_when_a_previous_run_did_not_complete(): void
    {
        $repaymentId = $this->seedPreCutoverRepayment();

        // Exactly what a killed run leaves behind: the marker claimed, never
        // flipped to completed.
        DB::table('timezone_shifts')->insert([
            'offset_minutes' => TimezoneShift::OFFSET_MINUTES,
            'cutover_at' => now('UTC')->format('Y-m-d H:i:s'),
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        try {
            $this->runMigrationUp();
            $this->fail('A partially applied shift must abort, not silently shift again.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('never completed', $e->getMessage());
        }

        $this->assertSame(
            self::PRE_CUTOVER_UTC,
            $this->format(DB::table('repayments')->where('id', $repaymentId)->value('created_at')),
            'Nothing may move while the database is in a mixed state.',
        );
    }

    /**
     * Rolling the data back without rolling the config back would leave every
     * record reading eight hours early. Deploy pipelines pass --force, so
     * Laravel's interactive confirmation is no protection.
     */
    public function test_down_refuses_while_the_app_is_still_on_the_new_timezone(): void
    {
        $repaymentId = $this->seedPreCutoverRepayment();
        $this->runMigrationUp();

        $this->assertSame('Asia/Manila', config('app.timezone'), 'Precondition for this test.');

        try {
            $this->migration()->down();
            $this->fail('down() must refuse while the app is still on Asia/Manila.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('APP_TIMEZONE=UTC', $e->getMessage());
        }

        $this->assertSame(
            self::PRE_CUTOVER_MANILA,
            $this->format(DB::table('repayments')->where('id', $repaymentId)->value('created_at')),
            'A refused rollback must not have moved anything.',
        );
        $this->assertTrue(Schema::hasTable('timezone_shifts'), 'And must not have dropped the marker.');
    }

    /**
     * A table whose migration sorts after this one does not exist yet during a
     * from-scratch rebuild. The schema-drift test forces new datetime columns
     * into the map, so this will happen; it must not break `migrate:fresh`.
     */
    public function test_it_tolerates_a_mapped_table_that_does_not_exist_yet(): void
    {
        $this->seedPreCutoverRepayment();

        Schema::drop('gcash_transactions');
        $this->assertArrayHasKey('gcash_transactions', TimezoneShift::COLUMNS);

        $shift = new TimezoneShift(DB::connection());
        $this->assertArrayNotHasKey('gcash_transactions', $shift->impact($shift->cutover()));

        $this->runMigrationUp();

        $this->assertSame('completed', DB::table('timezone_shifts')->sole()->status);
    }

    /**
     * down() puts it back, and a down/up cycle nets to a single shift.
     */
    public function test_down_reverses_the_shift_and_a_cycle_does_not_double_apply(): void
    {
        $repaymentId = $this->seedPreCutoverRepayment();
        $storedCreatedAt = fn () => $this->format(
            DB::table('repayments')->where('id', $repaymentId)->value('created_at')
        );

        $this->runMigrationUp();
        $this->assertSame(self::PRE_CUTOVER_MANILA, $storedCreatedAt());

        // A real rollback reverts APP_TIMEZONE first; down() refuses otherwise.
        config(['app.timezone' => 'UTC']);
        $this->migration()->down();
        config(['app.timezone' => 'Asia/Manila']);

        $this->assertSame(self::PRE_CUTOVER_UTC, $storedCreatedAt(), 'down() must subtract the same eight hours.');
        $this->assertFalse(Schema::hasTable('timezone_shifts'), 'Rollback leaves no residue.');

        $this->runMigrationUp();
        $this->assertSame(self::PRE_CUTOVER_MANILA, $storedCreatedAt(), 'A down/up cycle nets to one shift.');
    }

    /**
     * Credential expiry is excluded on purpose: shifting it forward would extend
     * the life of live tokens by eight hours.
     */
    public function test_it_leaves_credential_expiry_alone(): void
    {
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => 'App\Models\User',
            'tokenable_id' => $this->admin->id,
            'name' => 'probe',
            'token' => str_repeat('a', 64),
            'abilities' => '["*"]',
            'expires_at' => self::PRE_CUTOVER_UTC,
            'created_at' => self::PRE_CUTOVER_UTC,
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => 'probe@example.test',
            'token' => str_repeat('b', 60),
            'created_at' => self::PRE_CUTOVER_UTC,
        ]);

        $this->runMigrationUp();

        $this->assertSame(
            self::PRE_CUTOVER_UTC,
            $this->format(DB::table('personal_access_tokens')->where('name', 'probe')->value('expires_at')),
            'Shifting token expiry forward would widen the window live credentials stay valid.',
        );

        $this->assertSame(
            self::PRE_CUTOVER_UTC,
            $this->format(DB::table('password_reset_tokens')->where('email', 'probe@example.test')->value('created_at')),
            'Shifting a reset token forward would extend how long it can be redeemed.',
        );
    }

    /**
     * The dry run reports the blast radius and writes nothing.
     */
    public function test_the_impact_report_counts_rows_without_writing(): void
    {
        $repaymentId = $this->seedPreCutoverRepayment();

        $shift = new TimezoneShift(DB::connection());
        $impact = $shift->impact($shift->cutover());

        $this->assertSame(1, $impact['repayments'], 'Exactly the one pre-cutover row is in scope.');
        $this->assertSame(0, $impact['users'], 'Post-cutover rows are not counted.');

        $this->assertSame(
            self::PRE_CUTOVER_UTC,
            $this->format(DB::table('repayments')->where('id', $repaymentId)->value('created_at')),
            'The report is read-only.',
        );
    }

    /**
     * Refuse to touch anything if the database is not keeping UTC wall clocks,
     * because then the premise of the correction is false.
     */
    public function test_it_refuses_to_run_when_the_database_clock_is_not_utc(): void
    {
        $shift = new TimezoneShift(DB::connection());

        // Sanity: the test database is UTC, so this passes.
        $shift->assertDatabaseClockIsUtc();

        DB::statement("set time_zone = '+05:30'");

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('database session clock');
            $shift->assertDatabaseClockIsUtc();
        } finally {
            DB::statement("set time_zone = 'SYSTEM'");
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * A repayment row rewritten to look like the old UTC application wrote it:
     * a pre-cutover instant next to the calendar date the user actually picked.
     */
    private function seedPreCutoverRepayment(): int
    {
        $loan = $this->createReleasedLoan();

        // Post through the real endpoint so the row is shaped exactly like
        // production data, then re-date it to look like the old UTC app wrote it.
        $id = (int) $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => self::CALENDAR_DATE,
            'amount_paid' => 1000,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');

        DB::table('repayments')->where('id', $id)->update([
            'payment_date' => self::CALENDAR_DATE,
            'created_at' => self::PRE_CUTOVER_UTC,
            'updated_at' => self::PRE_CUTOVER_UTC,
        ]);

        return $id;
    }

    /**
     * Load a fresh instance of the real migration under test.
     */
    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_08_06_172018_shift_historical_timestamps_to_app_timezone.php'
        );
    }

    private function runMigrationUp(): void
    {
        $this->migration()->up();
    }

    /**
     * Normalise whatever the driver hands back for a date/datetime column.
     */
    private function format(mixed $value, string $format = 'Y-m-d H:i:s'): string
    {
        return CarbonImmutable::parse($value)->format($format);
    }
}

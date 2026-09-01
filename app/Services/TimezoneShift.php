<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * One-shot correction of historical timestamps for the UTC → Asia/Manila cutover.
 *
 * Until this branch the application ran in UTC, so every timezone-derived
 * instant in the database holds a UTC wall clock. The application now runs in
 * Asia/Manila and renders those same stored wall clocks as local time, which
 * makes every pre-cutover record read eight hours earlier than it happened.
 * This moves the historical rows forward so they read as the local time they
 * actually occurred at.
 *
 * ## What moves
 *
 * Only DATETIME/TIMESTAMP columns — instants the application derived from its
 * own clock. See {@see self::COLUMNS}.
 *
 * ## What deliberately does NOT move
 *
 * - **Every DATE column.** `payment_date`, `due_date`, `start_date`,
 *   `maturity_date`, `birthdate`, `date_hired`, `entry_date` and
 *   `share_capital_ledger.date` are user-supplied calendar dates, not instants.
 *   They were never timezone-derived. Shifting them would move a payment to the
 *   wrong day and reschedule live amortisation schedules — real data corruption.
 * - **Credential and token expiry** (`personal_access_tokens`,
 *   `password_reset_tokens`, `borrower_submission_tokens`). Moving an
 *   `expires_at` forward extends the life of live credentials by eight hours.
 *   Leaving them alone makes them expire up to eight hours early instead, so the
 *   failure mode is a re-login or a re-issued link rather than a widened
 *   security window. These fail closed on purpose.
 * - **`failed_jobs.failed_at`** — an operational log, not business data.
 * - **`sessions`, `jobs`, `job_batches`, `cache`, `cache_locks`.** These store
 *   epoch integers, not wall clocks, so they are timezone-independent and need
 *   no correction at all. In particular nobody gets logged out by this change.
 *
 * ## Why a fixed offset
 *
 * `CONVERT_TZ()` silently returns NULL when the MySQL timezone tables are not
 * loaded, which is the default on a stock install — that would blank the audit
 * trail. Philippine Standard Time is UTC+8 year-round with no DST, so a literal
 * interval is both safer and exactly equivalent.
 *
 * The arithmetic is also independent of the database session timezone: MySQL
 * stores TIMESTAMP as an epoch and converts on read and write, so adding eight
 * hours moves the instant by eight hours either way, and DATETIME is stored
 * literally and moves by the same eight hours.
 */
class TimezoneShift
{
    /**
     * Asia/Manila is UTC+8 with no daylight saving, so this is constant.
     */
    public const OFFSET_MINUTES = 480;

    /**
     * Rows touched per statement, so a large table never locks for long.
     */
    public const CHUNK_SIZE = 2000;

    /**
     * Every timezone-derived column in the schema, enumerated from
     * `information_schema` rather than guessed.
     *
     * `TimezoneShiftTest::test_the_column_map_matches_the_live_schema` fails if
     * this ever drifts from the real tables.
     *
     * @var array<string, list<string>>
     */
    public const COLUMNS = [
        'amortization_schedules' => ['created_at', 'updated_at'],
        'approval_workflow_settings' => ['created_at', 'updated_at'],
        'audit_logs' => ['created_at'],
        'auto_credit_runs' => ['created_at', 'processed_at', 'updated_at'],
        'borrowers' => ['approved_at', 'created_at', 'rejected_at', 'updated_at'],
        'branches' => ['created_at', 'updated_at'],
        'branding_settings' => ['created_at', 'updated_at'],
        'co_maker_loan' => ['created_at', 'updated_at'],
        'co_makers' => ['created_at', 'updated_at'],
        'collateral_types' => ['created_at', 'updated_at'],
        'collaterals' => ['created_at', 'updated_at'],
        'documents' => ['created_at', 'updated_at'],
        'fees' => ['created_at', 'updated_at'],
        'gcash_tiers' => ['created_at', 'updated_at'],
        // transaction_date is a DATETIME, but GCashService stamps it from now()
        // and never accepts it from the request, so it is a real instant.
        'gcash_transactions' => ['created_at', 'paid_at', 'transaction_date', 'updated_at'],
        'loan_adjustments' => ['applied_at', 'approved_at', 'created_at', 'updated_at'],
        'loan_collaterals' => ['attached_at', 'created_at', 'updated_at'],
        'loan_ledger_entries' => ['created_at', 'updated_at'],
        'loan_products' => ['created_at', 'updated_at'],
        'loans' => [
            'approved_at',
            'auto_pay_enabled_at',
            'created_at',
            'rejected_at',
            'released_at',
            'restructured_at',
            'updated_at',
        ],
        'permissions' => ['created_at', 'updated_at'],
        'repayments' => ['created_at', 'updated_at', 'voided_at'],
        'roles' => ['created_at', 'updated_at'],
        'share_capital_ledger' => ['created_at', 'updated_at'],
        'share_capital_pledges' => ['created_at', 'updated_at'],
        'users' => ['created_at', 'email_verified_at', 'last_login_at', 'updated_at'],
    ];

    /**
     * Timezone-derived columns that exist but are intentionally left alone.
     *
     * Kept explicit so the schema-drift test can tell "excluded on purpose" from
     * "forgotten", and so a reviewer can see the whole decision in one place.
     *
     * @var array<string, list<string>>
     */
    public const EXCLUDED_COLUMNS = [
        'borrower_submission_tokens' => ['created_at', 'expires_at', 'updated_at'],
        // The CSV migration importer's own bookkeeping. These tables were created
        // on 2026-08-29, three weeks after the cutover completed (2026-08-06 on
        // every deployment), and the shift is one-shot — the `timezone_shifts`
        // marker stops it running twice. So they cannot hold a row written by the
        // old UTC application, and shifting them would move correct Manila
        // timestamps 8h into the past. Same reasoning as `timezone_shifts` below.
        'csv_import_file_chunks' => ['created_at', 'updated_at'],
        'csv_import_files' => ['created_at', 'updated_at'],
        'csv_import_rows' => ['created_at', 'redacted_at', 'updated_at'],
        'csv_import_runs' => ['created_at', 'finished_at', 'rows_redacted_at', 'started_at', 'updated_at'],
        'failed_jobs' => ['failed_at'],
        'password_reset_tokens' => ['created_at'],
        'personal_access_tokens' => ['created_at', 'expires_at', 'last_used_at', 'updated_at'],
        // The shift's own bookkeeping, written after the cutover by definition.
        'timezone_shifts' => ['completed_at', 'cutover_at', 'started_at'],
    ];

    public function __construct(private ConnectionInterface $connection) {}

    /**
     * The boundary between "written under UTC" and "written under Asia/Manila".
     *
     * Everything already in the database when this runs was written by the old
     * UTC application, so its wall clock is at or before the current UTC instant.
     * Anything written by the new application after the config flip carries a
     * Manila wall clock, eight hours ahead of that instant. Filtering on this
     * boundary is what stops the migration touching post-cutover rows if a
     * request sneaks in between the deploy and the migration.
     *
     * Note this boundary is NOT an idempotency guard on its own: a row from
     * months ago that has already been shifted still sits far behind the current
     * instant and would match again. Not running twice is enforced by the
     * `timezone_shifts` marker the migration writes.
     */
    public function cutover(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    /**
     * Row counts per table that a forward shift would touch. Read-only.
     *
     * Counts rows, not cells: a row is included when any of its shiftable
     * columns holds a pre-cutover value.
     *
     * @return array<string, int>
     */
    public function impact(CarbonImmutable $cutover): array
    {
        $counts = [];

        foreach ($this->presentTables() as $table => $columns) {
            $predicates = array_map(
                static fn (string $column) => "(`{$column}` is not null and `{$column}` <= ?)",
                $columns,
            );

            $bindings = array_fill(0, count($columns), $cutover->format('Y-m-d H:i:s'));

            $counts[$table] = (int) $this->connection->scalar(
                'select count(*) from `'.$table.'` where '.implode(' or ', $predicates),
                $bindings,
            );
        }

        return $counts;
    }

    /**
     * Move historical instants forward into the application timezone.
     *
     * @return array<string, int> cells updated per table
     */
    public function forward(CarbonImmutable $cutover): array
    {
        return $this->apply($cutover, self::OFFSET_MINUTES);
    }

    /**
     * Undo a forward shift, returning every instant to a UTC wall clock.
     *
     * This is deliberately unfiltered. Rolling this back means reverting the
     * application to UTC as well, at which point every stored instant — the ones
     * up() moved and the ones written in Manila time since — has to come back by
     * the same amount.
     *
     * The offset is a parameter rather than the class constant so the caller can
     * reverse by the value actually recorded when the shift ran, which is the
     * only figure guaranteed to match what was applied.
     *
     * @return array<string, int> cells updated per table
     */
    public function backward(int $appliedOffsetMinutes): array
    {
        return $this->apply(null, -abs($appliedOffsetMinutes));
    }

    /**
     * The subset of {@see self::COLUMNS} whose tables actually exist.
     *
     * A table created by a migration that sorts AFTER this one does not exist
     * yet during a from-scratch `migrate:fresh`, so querying it blindly would
     * break CI, staging rebuilds and disaster-recovery restores the first time
     * anyone adds a new timestamped table. Such a table has no pre-cutover
     * history to correct anyway.
     *
     * @return array<string, list<string>>
     */
    private function presentTables(): array
    {
        $schema = $this->connection->getSchemaBuilder();

        return array_filter(
            self::COLUMNS,
            static fn (array $columns, string $table) => $schema->hasTable($table),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Refuse to run unless the database session is keeping UTC wall clocks.
     *
     * This is a pre-flight check, not a proof: it confirms the session timezone
     * is UTC today, which is the premise the correction depends on. It cannot
     * confirm that the values already stored are UTC wall clocks — verify that
     * once by hand against an event whose local time you can corroborate.
     */
    public function assertDatabaseClockIsUtc(): void
    {
        $row = $this->connection->selectOne(
            'select now() as session_now, utc_timestamp() as utc_now'
        );

        $skewMinutes = abs(
            CarbonImmutable::parse($row->session_now)
                ->diffInMinutes(CarbonImmutable::parse($row->utc_now))
        );

        if ($skewMinutes > 1) {
            throw new RuntimeException(
                'Refusing to shift historical timestamps: the database session clock is '
                ."{$skewMinutes} minutes away from UTC ({$row->session_now} vs {$row->utc_now}). "
                .'This correction assumes stored values are UTC wall clocks.'
            );
        }
    }

    /**
     * Walk each table in primary-key windows, shifting one column at a time.
     *
     * Chunking by id keeps every statement's lock small and bounded on a large
     * table, and makes the loop terminate by construction rather than by relying
     * on rows falling out of their own WHERE clause.
     *
     * @param  CarbonImmutable|null  $cutover  when set, only rows at or before it move
     * @return array<string, int>
     */
    private function apply(?CarbonImmutable $cutover, int $minutes): array
    {
        if ($minutes === 0 || abs($minutes) > 1440) {
            throw new RuntimeException("Refusing to shift by an implausible offset of {$minutes} minutes.");
        }

        $boundary = $cutover?->format('Y-m-d H:i:s');
        $updated = [];

        foreach ($this->presentTables() as $table => $columns) {
            $updated[$table] = 0;
            self::assertSafeIdentifier($table);

            $bounds = $this->connection->selectOne(
                "select min(`id`) as `min_id`, max(`id`) as `max_id` from `{$table}`"
            );

            if ($bounds === null || $bounds->min_id === null) {
                continue;
            }

            foreach ($columns as $column) {
                self::assertSafeIdentifier($column);

                // $minutes is validated above and every identifier comes from a
                // class constant, so nothing interpolated here is caller-controlled.
                $statement = "update `{$table}` set `{$column}` = date_add(`{$column}`, interval "
                    .(int) $minutes.' minute) '
                    ."where `id` >= ? and `id` < ? and `{$column}` is not null"
                    .($boundary === null ? '' : " and `{$column}` <= ?");

                for ($start = (int) $bounds->min_id; $start <= (int) $bounds->max_id; $start += self::CHUNK_SIZE) {
                    $bindings = [$start, $start + self::CHUNK_SIZE];

                    if ($boundary !== null) {
                        $bindings[] = $boundary;
                    }

                    $updated[$table] += $this->connection->update($statement, $bindings);
                }
            }
        }

        return $updated;
    }

    /**
     * Identifiers are interpolated into SQL rather than bound, because MySQL
     * cannot parameterise them. They come from a class constant today, so this
     * is defence in depth against a later edit that sources them from config or
     * `information_schema`.
     */
    private static function assertSafeIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $identifier) !== 1) {
            throw new RuntimeException("Refusing to interpolate unsafe SQL identifier: {$identifier}");
        }
    }
}

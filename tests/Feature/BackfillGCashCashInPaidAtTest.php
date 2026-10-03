<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\GCashTransaction;
use App\Services\GCashCashInPaidAtBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The data fix for Cash In transactions saved as paid without `paid_at`
 * before 2026-10-03: the artisan command, and the migration that runs the
 * same backfill on deploy.
 */
class BackfillGCashCashInPaidAtTest extends TestCase
{
    use SetupLendyPH;

    private const MIGRATION = 'migrations/2026_10_03_120000_backfill_gcash_cash_in_paid_at.php';

    /** @var array<string, GCashTransaction> */
    private array $tx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00'));

        $this->tx = [
            'first' => $this->transaction(['created_at' => '2026-09-01 09:15:00']),
            'second' => $this->transaction(['created_at' => '2026-09-20 16:40:12']),
            'already_stamped' => $this->transaction(['created_at' => '2026-09-02 10:00:00', 'paid_at' => '2026-09-02 10:00:05']),
            'pending' => $this->transaction(['status' => 'pending', 'created_at' => '2026-09-03 10:00:00']),
            'cash_out' => $this->transaction(['type' => 'cash_out', 'status' => 'completed', 'created_at' => '2026-09-04 10:00:00']),
            'marked_by_someone' => $this->transaction(['created_at' => '2026-09-05 10:00:00', 'paid_by_user_id' => $this->admin->id]),
            'no_created_at' => $this->transaction(['created_at' => null]),
        ];
    }

    public function test_a_dry_run_lists_the_rows_and_writes_nothing(): void
    {
        $before = $this->snapshot();

        $this->artisan('gcash:backfill-cash-in-paid-at', ['--dry-run' => true])
            ->expectsOutputToContain("{$this->tx['first']->reference_no} (id {$this->tx['first']->id}): paid_at 2026-09-01 09:15:00")
            ->expectsOutputToContain("{$this->tx['no_created_at']->reference_no} (id {$this->tx['no_created_at']->id}) left as it is: created_at is unknown")
            ->expectsOutputToContain(sprintf(
                'Would set paid_at on 2 Cash In transaction(s) (ids %d, %d); 2 skipped (ids %d, %d).',
                $this->tx['first']->id,
                $this->tx['second']->id,
                $this->tx['marked_by_someone']->id,
                $this->tx['no_created_at']->id,
            ))
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_it_sets_paid_at_to_created_at_and_records_each_change(): void
    {
        $before = $this->snapshot();

        $this->artisan('gcash:backfill-cash-in-paid-at')
            ->expectsOutputToContain('Set paid_at on 2 Cash In transaction(s)')
            ->assertSuccessful();

        $this->assertSame('2026-09-01 09:15:00', $this->paidAt('first'));
        $this->assertSame('2026-09-20 16:40:12', $this->paidAt('second'));

        // Every other row is exactly as it was.
        $after = $this->snapshot();
        foreach (['already_stamped', 'pending', 'cash_out', 'marked_by_someone', 'no_created_at'] as $key) {
            $this->assertSame($before['rows'][$this->tx[$key]->id], $after['rows'][$this->tx[$key]->id], $key);
        }

        $entries = AuditLog::where('action', GCashCashInPaidAtBackfill::AUDIT_ACTION)->orderBy('auditable_id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame([$this->tx['first']->id, $this->tx['second']->id], $entries->pluck('auditable_id')->all());

        $first = $entries->first();
        $this->assertNull($first->user_id);
        $this->assertSame(GCashTransaction::class, $first->auditable_type);
        $this->assertSame(['paid_at' => null], $first->old_values);
        $this->assertSame(['paid_at' => '2026-09-01T09:15:00+08:00'], $first->new_values);
        $this->assertStringStartsWith('System correction: Cash In '.$this->tx['first']->reference_no, $first->description);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->artisan('gcash:backfill-cash-in-paid-at')->assertSuccessful();
        $after = $this->snapshot();

        $this->artisan('gcash:backfill-cash-in-paid-at')
            ->expectsOutputToContain(sprintf(
                'Set paid_at on 0 Cash In transaction(s); 2 skipped (ids %d, %d).',
                $this->tx['marked_by_someone']->id,
                $this->tx['no_created_at']->id,
            ))
            ->assertSuccessful();

        $this->assertSame($after, $this->snapshot());
    }

    public function test_the_migration_runs_the_same_backfill_and_logs_its_count(): void
    {
        Log::spy();

        $this->migration()->up();

        $this->assertSame('2026-09-01 09:15:00', $this->paidAt('first'));
        $this->assertSame('2026-09-20 16:40:12', $this->paidAt('second'));
        $this->assertNull($this->paidAt('no_created_at'));
        $this->assertSame(2, AuditLog::where('action', GCashCashInPaidAtBackfill::AUDIT_ACTION)->count());

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message): bool => $message === 'gcash cash_in paid_at backfill: 2 updated, 2 skipped',
        )->once();
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => array_column($context['rows'], 'id') === [
                $this->tx['marked_by_someone']->id,
                $this->tx['no_created_at']->id,
            ],
        )->once();

        $after = $this->snapshot();
        $this->migration()->up();
        $this->assertSame($after, $this->snapshot());
    }

    public function test_the_migration_writes_exactly_what_the_service_writes(): void
    {
        $before = $this->snapshot();

        // The service's outcome, taken inside a savepoint and then undone.
        DB::beginTransaction();
        $result = app(GCashCashInPaidAtBackfill::class)->run(dryRun: false);
        $byService = $this->outcome();
        DB::rollBack();

        $this->assertSame($before, $this->snapshot());
        $this->assertCount(2, $byService['audit']);

        Log::spy();
        $this->migration()->up();
        $byMigration = $this->outcome();

        // The same rows, the same audit rows column for column, the same skips.
        $this->assertSame($byService, $byMigration);
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => $message === GCashCashInPaidAtBackfill::summary($result) && $context === $result,
        )->once();
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $context['rows'] === $result['skipped'],
        )->once();

        // A second run changes nothing and records nothing, and says so.
        $this->migration()->up();
        $this->assertSame($byMigration, $this->outcome());
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => $message === 'gcash cash_in paid_at backfill: 0 updated, 2 skipped'
                && $context === ['updated' => [], 'skipped' => $result['skipped']],
        )->once();
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $context['rows'] === $result['skipped'],
        )->twice();
    }

    public function test_the_migration_calls_no_application_class(): void
    {
        // A migration has to do on its last deployment what it did on its first,
        // so it must not reach into app code that can change after it ships.
        $source = file_get_contents(database_path(self::MIGRATION));

        $this->assertDoesNotMatchRegularExpression('/^use App\\\\/m', $source);
        $this->assertStringNotContainsString('App\\Services', $source);
    }

    public function test_the_migration_s_down_changes_nothing(): void
    {
        $this->migration()->up();

        $after = $this->snapshot();
        $this->migration()->down();
        $this->assertSame($after, $this->snapshot());
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transaction(array $attributes): GCashTransaction
    {
        $tx = GCashTransaction::factory()->create(array_diff_key($attributes, ['created_at' => true]));

        // Written directly so a NULL `created_at` survives the model's timestamps.
        DB::table('gcash_transactions')->where('id', $tx->id)->update([
            'created_at' => array_key_exists('created_at', $attributes) ? $attributes['created_at'] : now(),
        ]);

        return $tx->fresh();
    }

    private function paidAt(string $key): ?string
    {
        return DB::table('gcash_transactions')->where('id', $this->tx[$key]->id)->value('paid_at');
    }

    /**
     * The transactions and their backfill audit rows, every column but the
     * audit row's own id.
     *
     * @return array{rows: list<array<string, mixed>>, audit: list<array<string, mixed>>}
     */
    private function outcome(): array
    {
        return [
            'rows' => DB::table('gcash_transactions')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'audit' => DB::table('audit_logs')
                ->where('action', GCashCashInPaidAtBackfill::AUDIT_ACTION)
                ->orderBy('auditable_id')
                ->get()
                ->map(fn (object $row): array => Arr::except((array) $row, 'id'))
                ->all(),
        ];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, audit: int}
     */
    private function snapshot(): array
    {
        return [
            'rows' => DB::table('gcash_transactions')->orderBy('id')->get()->keyBy('id')->map(fn (object $row): array => (array) $row)->all(),
            'audit' => AuditLog::count(),
        ];
    }
}

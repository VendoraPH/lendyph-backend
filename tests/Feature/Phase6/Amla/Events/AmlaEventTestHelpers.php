<?php

namespace Tests\Feature\Phase6\Amla\Events;

use App\Models\AmlaEvent;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Services\Amla\Events\Harvester;
use App\Services\Savings\SavingsLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shared set-up for the AMLA events tests (AMLA-B2): the monitoring switch,
 * a clock the tests move by hand, and a harvest that starts monitoring
 * ("go-live") before any test row is written.
 */
trait AmlaEventTestHelpers
{
    /** The source tables AMLA reads and must never write. */
    protected const SOURCE_TABLES = [
        'loans', 'repayments', 'savings_transactions', 'savings_accounts', 'time_deposits',
        'share_capital_ledger', 'gcash_transactions', 'borrowers',
    ];

    protected function monitoringOn(): void
    {
        config(['amla.monitoring_enabled' => true]);
    }

    /** Set the clock to a Manila time. */
    protected function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, 'Asia/Manila'));
    }

    /** Start monitoring at 09:00 Manila on Nov 20, 2026, then move the clock a minute on. */
    protected function goLive(): void
    {
        $this->monitoringOn();
        $this->at('2026-11-20 09:00:00');
        app(Harvester::class)->harvest();
        $this->at('2026-11-20 09:01:00');
    }

    /** Move the clock past the lag and harvest. @return array<string, array<string, int>>|null */
    protected function harvestLater(string $time = '2026-11-20 09:05:00'): ?array
    {
        $this->at($time);

        return app(Harvester::class)->harvest();
    }

    /** @return list<AmlaEvent> */
    protected function eventsFor(string $source, int $sourceId): array
    {
        return AmlaEvent::query()->where('source', $source)->where('source_id', $sourceId)->orderBy('id')->get()->all();
    }

    protected function soleEvent(string $source, int $sourceId): AmlaEvent
    {
        $events = $this->eventsFor($source, $sourceId);
        $this->assertCount(1, $events, "Expected one event for {$source} #{$sourceId}.");

        return $events[0];
    }

    /** @return array<string, string> table => checksum, to prove AMLA wrote none of them */
    protected function sourceChecksums(): array
    {
        $sums = [];

        foreach (self::SOURCE_TABLES as $table) {
            $sums[$table] = (string) DB::selectOne("CHECKSUM TABLE `{$table}`")->Checksum;
        }

        return $sums;
    }

    protected function savingsAccountFor(int $borrowerId, int $branchId): SavingsAccount
    {
        return SavingsAccount::factory()->create([
            'borrower_id' => $borrowerId,
            'product_id' => SavingsProduct::factory()->create()->id,
            'branch_id' => $branchId,
            'opened_on' => '2026-11-01',
            'last_member_activity_on' => '2026-11-01',
        ]);
    }

    /** A savings row written through the ledger, as the teller writes it. */
    protected function savingsRow(SavingsAccount $account, string $side, string $type, int $centavos, array $attributes = []): SavingsTransaction
    {
        return DB::transaction(fn () => $side === 'credit'
            ? app(SavingsLedger::class)->credit($account->refresh(), $type, $centavos, $attributes)
            : app(SavingsLedger::class)->debit($account->refresh(), $type, $centavos, $attributes));
    }
}

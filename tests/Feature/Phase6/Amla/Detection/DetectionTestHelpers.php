<?php

namespace Tests\Feature\Phase6\Amla\Detection;

use App\Models\AmlaAlert;
use App\Models\AmlaEvent;
use App\Models\Borrower;
use App\Services\Amla\Detection\Detector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shared set-up for the AMLA detection tests (AMLA-B3): monitoring on, the
 * clock on Nov 20, 2026 in Manila, and AMLA events written straight into the
 * AMLA table, so each rule is tested at, below and above its threshold
 * without the money modules. The end-to-end tests go through the harvester.
 */
trait DetectionTestHelpers
{
    private int $eventSeq = 0;

    protected function startDetection(): void
    {
        config(['amla.monitoring_enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00:00', 'Asia/Manila'));
    }

    protected function member(?int $branchId = null, array $attributes = []): Borrower
    {
        return Borrower::factory()->create(['branch_id' => $branchId ?? $this->branch->id, 'status' => 'active', ...$attributes]);
    }

    /** One customer event for the member, cash money in today, with these overrides. */
    protected function event(Borrower $member, string $amount, array $overrides = []): AmlaEvent
    {
        $this->eventSeq++;

        return AmlaEvent::query()->create([
            'source' => 'savings_transaction',
            'source_id' => 900_000 + $this->eventSeq,
            'kind' => 'savings_deposit',
            'direction' => 'in',
            'is_internal' => false,
            'channel' => 'cash',
            'amount' => $amount,
            'borrower_id' => $member->id,
            'gcash_non_member_id' => null,
            'branch_id' => $member->branch_id,
            'occurred_on' => '2026-11-20',
            'recorded_at' => now(),
            'reference' => 'SVT-T-'.$this->eventSeq,
            'harvested_by' => 'monitor',
            ...$overrides,
        ]);
    }

    /** @return array<string, int>|null */
    protected function detect(): ?array
    {
        return app(Detector::class)->run();
    }

    /** @return list<AmlaAlert> */
    protected function alerts(string $code): array
    {
        return AmlaAlert::query()->where('rule_code', $code)->orderBy('id')->get()->all();
    }

    /** @return array{audit_logs: int, notifications: int} */
    protected function outsideCounts(): array
    {
        return ['audit_logs' => DB::table('audit_logs')->count(), 'notifications' => DB::table('notifications')->count()];
    }
}

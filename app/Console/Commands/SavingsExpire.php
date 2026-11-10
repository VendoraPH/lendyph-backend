<?php

namespace App\Console\Commands;

use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Services\AuditLogService;
use App\Services\Savings\Api\Refuse;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\SavingsMath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The nightly savings expiry (design §6, 00:30 Manila).
 *
 * - A hold past its `expires_on` becomes `expired` (it stopped holding money
 *   the day after that date already; this records it).
 * - A pending request past its `expires_at` (the end of the Manila day it was
 *   made) becomes `expired` and frees its `live_key`, so it can be asked for
 *   again.
 *
 * Each chunk locks its savings accounts first (one id-ordered statement),
 * then its own rows, and re-checks the status under those locks, so a hold
 * released or a request decided at the same moment is left as it is. Each
 * change writes an audit row as the system. Running it twice changes
 * nothing more; with no savings rows it does nothing.
 */
#[Signature('savings:expire')]
#[Description('Expire savings holds past their end date and approval requests past their day')]
class SavingsExpire extends Command
{
    private const CHUNK = 200;

    public function handle(SavingsLocks $locks): int
    {
        $today = now()->toDateString();
        $holds = 0;
        $requests = 0;

        SavingsHold::query()->where('status', 'active')->whereNotNull('expires_on')->where('expires_on', '<', $today)
            ->select(['id', 'account_id'])
            ->chunkById(self::CHUNK, function (Collection $chunk) use ($locks, $today, &$holds): void {
                $holds += DB::transaction(function () use ($chunk, $locks, $today): int {
                    $locks->accounts($chunk->pluck('account_id'));
                    $expired = 0;

                    foreach (SavingsHold::query()->whereKey($chunk->pluck('id'))->orderBy('id')->lockForUpdate()->get() as $hold) {
                        if ($hold->status !== 'active' || $hold->expires_on === null || $hold->expires_on->toDateString() >= $today) {
                            continue;
                        }

                        DB::table('savings_holds')->where('id', $hold->id)->update(['status' => 'expired', 'updated_at' => now()]);
                        AuditLogService::log('savings_hold_expired', $hold, ['status' => 'active'], ['status' => 'expired'],
                            'Hold of '.Refuse::peso(SavingsMath::centavos($hold->amount))." expired after {$hold->expires_on->format('M j, Y')}.");
                        $expired++;
                    }

                    return $expired;
                });
            });

        SavingsApproval::query()->where('status', 'pending')->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->select(['id', 'account_id', 'time_deposit_id'])
            ->chunkById(self::CHUNK, function (Collection $chunk) use ($locks, &$requests): void {
                $requests += DB::transaction(function () use ($chunk, $locks): int {
                    $locks->accounts($chunk->pluck('account_id')->filter());
                    $locks->timeDeposits($chunk->pluck('time_deposit_id')->filter());
                    $expired = 0;

                    foreach (SavingsApproval::query()->whereKey($chunk->pluck('id'))->orderBy('id')->lockForUpdate()->get() as $approval) {
                        if ($approval->status !== 'pending' || $approval->expires_at === null || $approval->expires_at->greaterThan(now())) {
                            continue;
                        }

                        DB::table('savings_approvals')->where('id', $approval->id)->update(['status' => 'expired', 'live_key' => null, 'updated_at' => now()]);
                        AuditLogService::log('savings_approval_expired', $approval, ['status' => 'pending'], ['status' => 'expired'],
                            "Approval request #{$approval->id} expired at the end of its day without a decision.");
                        $expired++;
                    }

                    return $expired;
                });
            });

        $this->info("Expired {$holds} hold(s) and {$requests} approval request(s).");

        return self::SUCCESS;
    }
}

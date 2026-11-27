<?php

namespace App\Console\Commands;

use App\Models\SavingsAccount;
use App\Models\SavingsSetting;
use App\Services\AuditLogService;
use App\Services\Savings\SavingsLocks;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The nightly dormancy job (design §6, 00:35 Manila).
 *
 * An active account becomes dormant once its last member activity (a
 * deposit, withdrawal or transfer; the opening date when there has been
 * none) is at least `dormancy_months` old: with 24 months, activity on
 * Nov 10, 2024 makes it dormant on Nov 10, 2026, and activity on Nov 11,
 * 2024 doesn't yet. No fee is charged, and it still earns interest.
 *
 * Each chunk locks its accounts in one id-ordered statement and re-checks
 * after the lock (a deposit at the same moment keeps the account active).
 * One audit row per account, as the system. Running it twice changes nothing
 * more; with no savings rows it does nothing.
 */
#[Signature('savings:mark-dormant')]
#[Description('Mark savings accounts dormant after the configured months without member activity')]
class SavingsMarkDormant extends Command
{
    private const CHUNK = 200;

    public function handle(SavingsLocks $locks): int
    {
        $months = max(1, (int) SavingsSetting::current()->dormancy_months);
        $today = CarbonImmutable::parse(now()->toDateString());
        $cutoff = $today->subMonthsNoOverflow($months)->toDateString();
        $marked = 0;

        SavingsAccount::query()->where('status', SavingsAccount::STATUS_ACTIVE)
            ->whereRaw('COALESCE(last_member_activity_on, opened_on) <= ?', [$cutoff])
            ->select('id')
            ->chunkById(self::CHUNK, function (Collection $chunk) use ($locks, $cutoff, $today, $months, &$marked): void {
                $marked += DB::transaction(function () use ($chunk, $locks, $cutoff, $today, $months): int {
                    $done = 0;

                    foreach ($locks->accounts($chunk->pluck('id')) as $account) {
                        $lastActivity = ($account->last_member_activity_on ?? $account->opened_on)->toDateString();

                        if ($account->status !== SavingsAccount::STATUS_ACTIVE || $lastActivity > $cutoff) {
                            continue;
                        }

                        DB::table('savings_accounts')->where('id', $account->id)
                            ->update(['status' => SavingsAccount::STATUS_DORMANT, 'dormant_since' => $today->toDateString(), 'updated_at' => now()]);
                        AuditLogService::log(
                            'savings_account_dormant',
                            $account,
                            ['status' => SavingsAccount::STATUS_ACTIVE, 'dormant_since' => null],
                            ['status' => SavingsAccount::STATUS_DORMANT, 'dormant_since' => $today->toDateString()],
                            "Savings account {$account->account_number} became dormant: no member activity since "
                                .CarbonImmutable::parse($lastActivity)->format('M j, Y')." ({$months} months).",
                        );
                        $done++;
                    }

                    return $done;
                });
            });

        $this->info("Marked {$marked} savings account(s) dormant.");

        return self::SUCCESS;
    }
}

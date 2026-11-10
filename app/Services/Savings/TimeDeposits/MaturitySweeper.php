<?php

namespace App\Services\Savings\TimeDeposits;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\TimeDeposit;
use App\Services\AuditLogService;
use App\Services\Savings\SavingsLocks;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The nightly maturity run (`savings:mature-time-deposits`, 00:40 Manila,
 * design §6).
 *
 * 1. Every active placement whose maturity date has come moves to matured,
 *    with a `time_deposit_matured` audit row as the system.
 * 2. It then settles, as the system, only what moves no cash:
 *    - `rollover_all`;
 *    - `rollover_principal` when the interest goes into the member's savings
 *      account, or there is no interest to pay out;
 *    - `payout` into the member's savings account.
 *    Anything paying out cash waits for staff, and so does a placement with a
 *    payout or pre-termination waiting for approval.
 *
 * Each placement is its own transaction under its own locks, re-checked
 * after locking, so a rerun (or a second job) finds it settled and does
 * nothing, and the journal's postable key never posts twice. A settlement
 * that is refused (a turned-off product, a frozen account, a closed period)
 * leaves the placement matured, is logged, and the run carries on; so does
 * a deadlock or lock-wait timeout on one placement (the next run retries it).
 */
class MaturitySweeper
{
    use DetectsConcurrencyErrors;

    private const CHUNK = 200;

    public function __construct(
        private readonly Settlement $settlement,
        private readonly SavingsLocks $locks,
    ) {}

    /** @return array{matured: int, settled: int, waiting: int, refused: int} */
    public function run(): array
    {
        $today = now()->toDateString();
        $counts = ['matured' => 0, 'settled' => 0, 'waiting' => 0, 'refused' => 0];

        TimeDeposit::query()
            ->whereIn('status', Settlement::OPEN_STATUSES)
            ->where('maturity_date', '<=', $today)
            ->select('id')
            ->chunkById(self::CHUNK, function ($chunk) use ($today, &$counts): void {
                foreach ($chunk as $row) {
                    try {
                        $counts['matured'] += $this->mature((int) $row->id) ? 1 : 0;
                    } catch (QueryException|DeadlockException $e) {
                        $this->skipOnConcurrency($e, (int) $row->id);
                        $counts['refused']++;

                        continue;
                    }

                    $counts[$this->autoSettle((int) $row->id, $today)]++;
                }
            });

        return $counts;
    }

    /** Active → matured, under the placement's lock. */
    private function mature(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $deposit = $this->locks->timeDeposit($id);

            if ($deposit->status !== 'active') {
                return false;
            }

            $deposit->forceFill(['status' => 'matured'])->saveQuietly();

            AuditLogService::log(
                'time_deposit_matured',
                $deposit,
                ['status' => 'active'],
                ['status' => 'matured'],
                "Time deposit {$deposit->certificate_number} matured on {$deposit->maturity_date->format('M j, Y')}, by the system.",
            );

            return true;
        });
    }

    /** @return 'settled'|'waiting'|'refused' */
    private function autoSettle(int $id, string $today): string
    {
        $plain = TimeDeposit::query()->findOrFail($id);
        $method = $this->automaticPayout($plain);

        if ($method === false) {
            return 'waiting';
        }

        try {
            return DB::transaction(function () use ($id, $today, $method): string {
                $payoutAccountId = $method === 'savings' ? TimeDeposit::query()->whereKey($id)->value('payout_account_id') : null;
                $this->locks->accounts(array_filter([$payoutAccountId]));
                $deposit = $this->locks->timeDeposit($id);

                if ($deposit->status !== 'matured' || $this->settlement->hasPendingApproval($deposit)) {
                    return 'waiting';
                }

                $this->settlement->settle($deposit, $today, $deposit->maturity_instruction, $method, null);

                return 'settled';
            });
        } catch (ValidationException|CannotPostToTheBooksException|HttpException|HttpResponseException $refused) {
            $this->logLeftMatured($id, $refused instanceof HttpResponseException
                ? (string) ($refused->getResponse()->getData(true)['message'] ?? 'conflict')
                : $refused->getMessage());

            return 'refused';
        } catch (QueryException|DeadlockException $e) {
            $this->skipOnConcurrency($e, $id);

            return 'refused';
        }
    }

    /**
     * A deadlock or lock-wait timeout on one placement is logged and the run
     * carries on (the next run picks it up again); any other database error
     * still stops the run. Inside an outer transaction Laravel rethrows a
     * deadlock as a DeadlockException, hence both.
     *
     * @throws QueryException|DeadlockException
     */
    private function skipOnConcurrency(QueryException|DeadlockException $e, int $id): void
    {
        if (! $this->causedByConcurrencyError($e)) {
            throw $e;
        }

        $this->logLeftMatured($id, 'another change held the placement at the same moment ('.strtok($e->getMessage(), '(').')');
    }

    private function logLeftMatured(int $id, string $reason): void
    {
        Log::warning('savings:mature-time-deposits could not settle a time deposit automatically; it is left for the next run or staff.', [
            'time_deposit_id' => $id,
            'reason' => $reason,
        ]);
    }

    /**
     * How the job may pay a placement out without cash leaving: null when
     * nothing is paid out, 'savings' into the member's account, or false when
     * it waits for staff.
     */
    private function automaticPayout(TimeDeposit $deposit): string|false|null
    {
        $intoSavings = $deposit->payout_account_id !== null;

        return match ($deposit->maturity_instruction) {
            'rollover_all' => null,
            'rollover_principal' => $this->settlement->split($deposit, 'rollover_principal', now()->toDateString())['paid_out'] === 0 ? null : ($intoSavings ? 'savings' : false),
            'payout' => $intoSavings ? 'savings' : false,
            default => false,
        };
    }
}

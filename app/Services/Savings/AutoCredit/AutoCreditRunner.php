<?php

namespace App\Services\Savings\AutoCredit;

use App\Models\Borrower;
use App\Models\SavingsAccount;
use App\Models\SavingsAutoCreditInstruction;
use App\Models\SavingsAutoCreditPosting;
use App\Models\SavingsAutoCreditRun;
use App\Models\SavingsAutoCreditSkip;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\AuditLogService;
use App\Services\Savings\Api\AccountScope;
use App\Services\Savings\Api\LargeTransactionAlert;
use App\Services\Savings\Api\Refuse;
use App\Services\Savings\ProductRules;
use App\Services\Savings\SavingsLedger;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\SavingsMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The savings auto-credit run (gap round S3), modelled on share capital's
 * ShareCapitalService::processAutoCredit().
 *
 * - **Due:** every active instruction on an account in the caller's branches
 *   whose schedule covers the cycle's slot (the 15th or the month end) and
 *   that was authorised on or before the cycle.
 * - **Already credited:** an account with a posting for this cycle, whichever
 *   instruction made it, is listed apart and posts nothing
 *   (`unique(account_id, cycle_date)`), so a re-run of a cycle, or a new
 *   instruction on the same slot, posts nothing. A credit later reversed
 *   reads "Credited, then reversed" and is never credited again by a run:
 *   staff post a deposit by hand if it is still owed. Two due instructions
 *   on one account (only a direct write can leave them) never block the run:
 *   the oldest credits it and the other is skipped with words.
 * - **Skipped, in words:** a withdrawn or deceased member, a closed or frozen
 *   account, and an amount the product's rules refuse (ProductRules: allow
 *   deposits, the deposit limits, the maximum balance).
 * - **Each credit** is a `deposit` through SavingsLedger (Dr the settlement
 *   account picked on the run, the bank by default / Cr 2050), dated today
 *   (a late run still records its cycle: the ledger takes no date before
 *   yesterday), counted as member activity, so it wakes a dormant account.
 *   One `savings_auto_credit_postings` row each, and one
 *   `savings_auto_credit_skips` row per skipped instruction (gap round B7).
 * - **Locks:** process() takes its locks before any plain read: the due
 *   instruction rows, then all their accounts in one SavingsLocks::accounts()
 *   call, then reads the instructions and the cycle's postings again with
 *   locking reads, so a second run waiting behind a first plans on what the
 *   first committed. A run that still loses the race on the unique index
 *   answers 409 in words. preview() is the same plan, unlocked, writing
 *   nothing, so it shows exactly what process() posts when nothing moved in
 *   between.
 *
 * Every figure is the server's, in whole centavos, sent as 2-decimal strings.
 */
class AutoCreditRunner
{
    public const DEFAULT_METHOD = 'bank_transfer';

    /** The 409 for a run that lost a race to another run of the same cycle. */
    public const RAN_ALREADY = 'This cycle was just run by someone else. Refresh to see what was credited.';

    /** An already-credited line's status, in words. */
    public const CREDITED_WORDS = ['credited' => 'Credited', 'reversed' => 'Credited, then reversed'];

    /** The settlement accounts a run can be picked through (as share capital auto-credit). */
    public const METHODS = ['bank_transfer', 'cash', 'gcash', 'maya'];

    public function __construct(
        private readonly AccountScope $scope,
        private readonly SavingsLocks $locks,
        private readonly SavingsLedger $ledger,
        private readonly AutomaticPoster $poster,
        private readonly ProductRules $rules,
        private readonly LargeTransactionAlert $alert,
    ) {}

    /**
     * What a run of this cycle would post now. Writes nothing.
     *
     * @return array<string, mixed>
     */
    public function preview(string $cycleDate, string $method, ?string $reference, User $user): array
    {
        $instructions = $this->dueInstructions($cycleDate, $user)->get();
        $accounts = SavingsAccount::query()->with('product')->whereIn('id', $instructions->pluck('account_id')->unique())->get()->keyBy('id');

        return $this->present(null, $cycleDate, $method, $reference, $this->plan($cycleDate, $instructions, $accounts, false));
    }

    /**
     * Post the run, inside the caller's transaction (IdempotentWrite).
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function process(string $cycleDate, string $method, ?string $reference, User $user): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('An auto-credit run is posted only inside a transaction.');
        }

        // Locks first, no plain read before them: the due instruction rows,
        // then their accounts in one id-ordered statement, then the
        // instructions and the cycle's postings again with locking reads, so
        // a run that waited behind another one plans on what that one
        // committed (never a stale snapshot).
        $due = $this->dueInstructions($cycleDate, $user)->lockForUpdate()->get(['id', 'account_id']);
        $accounts = $this->locks->accounts($due->pluck('account_id')->unique()->all());
        $accounts->load('product');

        $instructions = $this->dueInstructions($cycleDate, $user)->whereIn('account_id', $accounts->keys())->lockForUpdate()->get();
        $plan = $this->plan($cycleDate, $instructions, $accounts, true);
        $today = now()->toDateString();

        $run = SavingsAutoCreditRun::query()->create([
            'cycle_date' => $cycleDate,
            'posted_on' => $today,
            'method' => $method,
            'reference_number' => $reference,
            'posted_count' => count($plan['credits']),
            'posted_total' => SavingsMath::pesos($plan['credit_centavos']),
            'skipped_count' => count($plan['skipped']),
            'skips_recorded' => true,
            'created_by' => $user->id,
        ]);

        foreach ($plan['skipped'] as $skip) {
            SavingsAutoCreditSkip::query()->create([
                'run_id' => $run->id,
                'instruction_id' => $skip['instruction_id'],
                'account_id' => $skip['account_id'],
                'amount' => $skip['amount'],
                'reason' => Str::limit($skip['reason'], 500, ''),
            ]);
        }

        foreach ($plan['credits'] as $i => $credit) {
            $account = $accounts[$credit['account_id']];
            $row = $this->ledger->credit($account, 'deposit', $credit['centavos'], [
                'date' => $today,
                'method' => $method,
                'external_reference' => $reference,
                'remarks' => 'Auto-credit for the '.Cycle::words($cycleDate).' cycle',
                'created_by' => $user->id,
            ]);
            $this->poster->savingsTransaction($row, $user->id);

            $this->recordPosting([
                'instruction_id' => $credit['instruction_id'],
                'account_id' => $account->id,
                'run_id' => $run->id,
                'cycle_date' => $cycleDate,
                'transaction_id' => $row->id,
                'amount' => SavingsMath::pesos($credit['centavos']),
            ]);

            $this->alert->afterPosting($row, [$user->id]);
            $plan['credits'][$i] = [...$credit, 'transaction_id' => $row->id, 'reference' => $row->reference];
        }

        AuditLogService::log(
            'savings_auto_credit_run',
            $run,
            null,
            [
                'cycle_date' => $cycleDate,
                'posted_on' => $today,
                'method' => $method,
                'posted_count' => $run->posted_count,
                'posted_total' => $run->posted_total,
                'skipped_count' => $run->skipped_count,
            ],
            'Savings auto-credit for the '.Cycle::words($cycleDate).' cycle: '.count($plan['credits']).' credited ('
                .Refuse::peso($plan['credit_centavos']).'), '.count($plan['skipped']).' skipped, '.count($plan['already']).' already credited.',
            $user->id,
        );

        return $this->present($run->id, $cycleDate, $method, $reference, $plan);
    }

    /**
     * One account credited for the cycle. Another run that credited it first
     * (the unique index on account and cycle) answers 409 in words, never
     * 500, and this whole run rolls back.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function recordPosting(array $attributes): void
    {
        try {
            SavingsAutoCreditPosting::query()->create($attributes);
        } catch (UniqueConstraintViolationException $e) {
            if ($e->index !== SavingsAutoCreditPosting::CYCLE_INDEX) {
                throw $e;
            }

            Refuse::conflict(self::RAN_ALREADY);
        }
    }

    /**
     * The instructions due on a cycle, oldest first, on accounts the caller
     * can see.
     *
     * @return Builder<SavingsAutoCreditInstruction>
     */
    private function dueInstructions(string $cycleDate, User $user): Builder
    {
        $slot = Cycle::slotOf($cycleDate) ?? throw new LogicException("{$cycleDate} is not an auto-credit cycle date.");

        return SavingsAutoCreditInstruction::query()
            ->where('active', true)
            ->whereIn('schedule', SavingsAutoCreditInstruction::schedulesCovering($slot))
            ->whereDate('authorised_on', '<=', $cycleDate)
            ->whereIn('account_id', $this->scope->accountIds($user))
            ->orderBy('id');
    }

    /**
     * @param  Collection<int, SavingsAutoCreditInstruction>  $instructions
     * @param  Collection<int, SavingsAccount>  $accounts  keyed by id
     * @param  bool  $locking  read the cycle's postings with a locking read (process)
     * @return array{credits: list<array<string, mixed>>, skipped: list<array<string, mixed>>, already: list<array<string, mixed>>, credit_centavos: int, skipped_centavos: int}
     */
    private function plan(string $cycleDate, Collection $instructions, Collection $accounts, bool $locking): array
    {
        // Any posting on the account for this cycle counts, whichever
        // instruction made it: an account is credited once per cycle.
        $posted = SavingsAutoCreditPosting::query()
            ->with('transaction:id,reference')
            ->whereDate('cycle_date', $cycleDate)
            ->whereIn('account_id', $instructions->pluck('account_id')->unique())
            ->when($locking, fn ($q) => $q->lockForUpdate())
            ->get()
            ->keyBy('account_id');
        $reversed = SavingsTransaction::query()
            ->whereIn('reverses_transaction_id', $posted->pluck('transaction_id'))
            ->pluck('reverses_transaction_id')
            ->flip();
        $members = Borrower::query()->whereIn('id', $accounts->pluck('borrower_id')->unique())->get()->keyBy('id');

        $plan = ['credits' => [], 'skipped' => [], 'already' => [], 'credit_centavos' => 0, 'skipped_centavos' => 0];
        // The instruction (oldest first) that takes each account this cycle.
        $takenBy = [];

        foreach ($instructions as $instruction) {
            $account = $accounts[$instruction->account_id];
            $member = $members[$account->borrower_id] ?? null;
            $centavos = SavingsMath::centavos($instruction->amount);
            $line = [
                'instruction_id' => $instruction->id,
                'account_id' => $account->id,
                'account_number' => $account->account_number,
                'borrower_name' => $member?->full_name,
                'amount' => SavingsMath::pesos($centavos),
            ];

            if ($posted->has($account->id)) {
                $posting = $posted[$account->id];
                $status = $reversed->has($posting->transaction_id) ? 'reversed' : 'credited';
                $plan['already'][] = [
                    ...$line,
                    'amount' => SavingsMath::pesos(SavingsMath::centavos($posting->amount)),
                    'transaction_id' => $posting->transaction_id,
                    'reference' => $posting->transaction?->reference,
                    'status' => $status,
                    'status_label' => self::CREDITED_WORDS[$status],
                ];

                continue;
            }

            $reason = isset($takenBy[$account->id])
                ? "{$account->account_number} is credited once a cycle, and auto-credit instruction #{$takenBy[$account->id]} already credits it for this one."
                : $this->skipReason($account, $member, $centavos);

            if ($reason !== null) {
                $plan['skipped'][] = [...$line, 'reason' => $reason];
                $plan['skipped_centavos'] += $centavos;

                continue;
            }

            $takenBy[$account->id] = $instruction->id;
            $plan['credits'][] = [...$line, 'centavos' => $centavos, 'transaction_id' => null, 'reference' => null];
            $plan['credit_centavos'] += $centavos;
        }

        return $plan;
    }

    /** Why this credit can't post now, in words, or null. */
    private function skipReason(SavingsAccount $account, ?Borrower $member, int $centavos): ?string
    {
        if ($member !== null && $member->isFormerMember()) {
            return $member->status === 'deceased'
                ? "{$member->full_name} is recorded as deceased, so nothing is credited for them."
                : "{$member->full_name} has withdrawn from the cooperative, so nothing is credited for them.";
        }

        if ($account->isClosed()) {
            return "{$account->account_number} is closed and takes no deposits.";
        }

        if ($account->status === SavingsAccount::STATUS_FROZEN) {
            return "{$account->account_number} is frozen and takes no deposits until it is unfrozen.";
        }

        try {
            $this->rules->assertCanDeposit($account, $centavos);
        } catch (ValidationException $refused) {
            return (string) collect($refused->errors())->flatten()->first();
        }

        return null;
    }

    /**
     * @param  array{credits: list<array<string, mixed>>, skipped: list<array<string, mixed>>, already: list<array<string, mixed>>, credit_centavos: int, skipped_centavos: int}  $plan
     * @return array<string, mixed>
     */
    private function present(?int $runId, string $cycleDate, string $method, ?string $reference, array $plan): array
    {
        return [
            'run_id' => $runId,
            'cycle_date' => $cycleDate,
            'posting_date' => now()->toDateString(),
            'method' => $method,
            'reference_number' => $reference,
            'credits' => array_map(fn (array $credit) => collect($credit)->except('centavos')->all(), $plan['credits']),
            'skipped' => $plan['skipped'],
            'already_credited' => $plan['already'],
            'totals' => [
                'credit_count' => count($plan['credits']),
                'credit_amount' => SavingsMath::pesos($plan['credit_centavos']),
                'skipped_count' => count($plan['skipped']),
                'skipped_amount' => SavingsMath::pesos($plan['skipped_centavos']),
                'already_credited_count' => count($plan['already']),
            ],
        ];
    }
}

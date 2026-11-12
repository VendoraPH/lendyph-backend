<?php

namespace App\Services\Savings\AutoCredit;

use App\Models\SavingsAccount;
use App\Models\SavingsAutoCreditInstruction;
use App\Models\SavingsAutoCreditPosting;
use App\Models\User;
use App\Services\Savings\Api\AccountScope;
use App\Services\Savings\Api\Refuse;
use App\Services\Savings\SavingsLocks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes savings auto-credit instructions (gap round S3): create, edit and
 * delete, each in one transaction under the account's row lock, so the rule
 * of one active instruction per account per slot (the 15th, the 30th) is
 * exact, and a run, which takes the same lock, never sees half an edit.
 * Audited by the model (Auditable).
 *
 * - The account must be in the caller's branches (422 on `account_id`,
 *   worded like a missing one) and not closed. It never changes.
 * - An instruction that has credited an account is kept for the record: it
 *   can be deactivated, never deleted.
 */
class Instructions
{
    public function __construct(
        private readonly AccountScope $scope,
        private readonly SavingsLocks $locks,
    ) {}

    /**
     * Instructions on accounts in the caller's branches.
     *
     * @return Builder<SavingsAutoCreditInstruction>
     */
    public function visible(User $user): Builder
    {
        return SavingsAutoCreditInstruction::query()->whereIn('account_id', $this->scope->accountIds($user));
    }

    /** One visible instruction, or 404 (another branch's reads as missing). */
    public function find(User $user, int $id): SavingsAutoCreditInstruction
    {
        return $this->visible($user)->whereKey($id)->firstOr(fn () => abort(404, 'Auto-credit instruction not found.'));
    }

    /**
     * @param  array{account_id: int|string, amount: string, schedule: string, active?: bool, authorised_on: string, authorised_by: string, remarks?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data, User $user): SavingsAutoCreditInstruction
    {
        $accountId = (int) $data['account_id'];

        if (! $this->scope->accounts($user)->whereKey($accountId)->exists()) {
            Refuse::field('account_id', 'That savings account does not exist.');
        }

        return DB::transaction(function () use ($accountId, $data, $user): SavingsAutoCreditInstruction {
            $account = $this->locks->account($accountId);

            if ($account->isClosed()) {
                Refuse::field('account_id', "{$account->account_number} is closed, so it can't take an auto-credit instruction.");
            }

            $active = (bool) ($data['active'] ?? true);

            if ($active) {
                $this->assertNoActiveOverlap($account, $data['schedule'], null);
            }

            return SavingsAutoCreditInstruction::query()->create([
                'account_id' => $account->id,
                'amount' => $data['amount'],
                'schedule' => $data['schedule'],
                'active' => $active,
                'authorised_on' => $data['authorised_on'],
                'authorised_by' => $data['authorised_by'],
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        });
    }

    /**
     * @param  array{amount?: string, schedule?: string, active?: bool, authorised_on?: string, authorised_by?: string, remarks?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function update(SavingsAutoCreditInstruction $instruction, array $data, User $user): SavingsAutoCreditInstruction
    {
        return DB::transaction(function () use ($instruction, $data, $user): SavingsAutoCreditInstruction {
            $account = $this->locks->account($instruction->account_id);
            $locked = SavingsAutoCreditInstruction::query()->whereKey($instruction->id)->lockForUpdate()->firstOrFail();

            $schedule = $data['schedule'] ?? $locked->schedule;
            $active = array_key_exists('active', $data) ? (bool) $data['active'] : $locked->active;

            if ($active && $account->isClosed()) {
                Refuse::field('active', "{$account->account_number} is closed, so its auto-credit instruction can't be active.");
            }

            if ($active) {
                $this->assertNoActiveOverlap($account, $schedule, $locked->id);
            }

            $locked->fill([
                ...array_intersect_key($data, array_flip(['amount', 'schedule', 'authorised_on', 'authorised_by', 'remarks'])),
                'active' => $active,
            ]);

            // A save that changes nothing writes nothing (no audit row either).
            if ($locked->isDirty()) {
                $locked->forceFill(['updated_by' => $user->id])->save();
            }

            return $locked;
        });
    }

    /** @throws ValidationException */
    public function delete(SavingsAutoCreditInstruction $instruction): void
    {
        DB::transaction(function () use ($instruction): void {
            $this->locks->account($instruction->account_id);
            $locked = SavingsAutoCreditInstruction::query()->whereKey($instruction->id)->lockForUpdate()->firstOrFail();

            if (SavingsAutoCreditPosting::query()->where('instruction_id', $locked->id)->exists()) {
                Refuse::field('instruction', 'This instruction has already credited the account, so it is kept for the record. Deactivate it instead.');
            }

            $locked->delete();
        });
    }

    /**
     * Refuse a second active instruction on a slot of this account (the 15th
     * or the 30th; "both" takes the two). Read under the account lock.
     *
     * @throws ValidationException
     */
    private function assertNoActiveOverlap(SavingsAccount $account, string $schedule, ?int $exceptId): void
    {
        $slots = SavingsAutoCreditInstruction::slotsOf($schedule);

        $taken = SavingsAutoCreditInstruction::query()
            ->where('account_id', $account->id)
            ->where('active', true)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->pluck('schedule')
            ->flatMap(fn (string $other) => SavingsAutoCreditInstruction::slotsOf($other))
            ->intersect($slots)
            ->sort()
            ->values();

        if ($taken->isNotEmpty()) {
            $words = $taken->map(fn (string $slot) => $slot === SavingsAutoCreditInstruction::SCHEDULE_15 ? 'the 15th' : 'the 30th')->join(' and ');

            Refuse::field('schedule', "{$account->account_number} already has an active auto-credit instruction on {$words}. Deactivate it first, or change it instead.");
        }
    }
}

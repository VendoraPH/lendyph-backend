<?php

namespace App\Services\Reminders\Api;

use App\Http\Resources\Reminders\ReminderPauseResource;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\ReminderPause;
use App\Models\ReminderSetting;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pausing and resuming reminders: one pause in force per target (all
 * reminders, a member or a loan). Pause and resume are serialised on the
 * settings row's lock, so two staff pausing the same target at once leave
 * one pause. Each pause audits itself (Auditable) and adds a reminder event.
 */
final class ReminderPauses
{
    public function __construct(private readonly BranchAccess $branches) {}

    /**
     * Pauses in force: global ones, and the visible members' and loans'.
     *
     * @return Collection<int, ReminderPause>
     */
    public function inForce(User $user): Collection
    {
        $visible = $this->branches->for($user);

        return ReminderPause::query()
            ->inForce()
            ->when($visible !== null, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->where('scope', 'global')
                ->orWhere(fn (Builder $member) => $member->where('scope', 'borrower')->whereIn('borrower_id', Borrower::query()->visibleTo($user)->select('borrowers.id')))
                ->orWhere(fn (Builder $loan) => $loan->where('scope', 'loan')->whereIn('loan_id', Loan::query()->visibleTo($user)->select('loans.id')))))
            ->with(ReminderPauseResource::RELATIONS)
            ->latest('paused_at')
            ->latest('id')
            ->get();
    }

    /**
     * @param  array{scope: string, borrower_id?: ?int, loan_id?: ?int, reason: string, until?: ?string}  $data
     */
    public function pause(User $user, array $data): ReminderPause
    {
        return DB::transaction(function () use ($user, $data): ReminderPause {
            $this->lock();

            if ($this->current($data) !== null) {
                throw ValidationException::withMessages(['scope' => self::already($data['scope'], true)]);
            }

            return ReminderPause::query()->create([
                'scope' => $data['scope'],
                'borrower_id' => $data['scope'] === 'borrower' ? (int) $data['borrower_id'] : null,
                'loan_id' => $data['scope'] === 'loan' ? (int) $data['loan_id'] : null,
                'reason' => $data['reason'],
                'paused_at' => now(),
                'until' => filled($data['until'] ?? null) ? Carbon::parse($data['until'])->setTimezone(config('app.timezone')) : null,
                'paused_by' => $user->id,
            ]);
        });
    }

    /**
     * @param  array{scope: string, borrower_id?: ?int, loan_id?: ?int}  $data
     */
    public function resume(User $user, array $data): ReminderPause
    {
        return DB::transaction(function () use ($user, $data): ReminderPause {
            $this->lock();
            $pause = $this->current($data);

            if ($pause === null) {
                throw ValidationException::withMessages(['scope' => self::already($data['scope'], false)]);
            }

            $pause->update(['resumed_at' => now(), 'resumed_by' => $user->id]);

            return $pause;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function current(array $data): ?ReminderPause
    {
        return ReminderPause::query()
            ->inForce()
            ->where('scope', $data['scope'])
            ->when($data['scope'] === 'borrower', fn (Builder $q) => $q->where('borrower_id', (int) $data['borrower_id']))
            ->when($data['scope'] === 'loan', fn (Builder $q) => $q->where('loan_id', (int) $data['loan_id']))
            ->latest('paused_at')
            ->first();
    }

    private function lock(): void
    {
        ReminderSetting::query()->orderBy('id')->lockForUpdate()->first(['id']);
    }

    private static function already(string $scope, bool $paused): string
    {
        $state = $paused ? 'already paused' : 'not paused';

        return match ($scope) {
            'loan' => "Reminders are {$state} for this loan.",
            'borrower' => "Reminders are {$state} for this member.",
            default => $paused ? 'All reminders are already paused.' : 'There is no pause on all reminders to resume.',
        };
    }
}

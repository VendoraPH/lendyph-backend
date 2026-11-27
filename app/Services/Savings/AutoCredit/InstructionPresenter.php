<?php

namespace App\Services\Savings\AutoCredit;

use App\Http\Resources\Savings\SavingsUserRef;
use App\Models\SavingsAutoCreditInstruction;
use App\Models\SavingsAutoCreditPosting;
use App\Services\Savings\SavingsMath;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Savings auto-credit instructions as the screens read them (gap round S3):
 * the account, member, branch and product in words, the schedule in words,
 * the amount as a 2-decimal string, who entered and last changed it, and the
 * last cycle it credited.
 */
class InstructionPresenter
{
    /** @return array<string, mixed> */
    public function one(SavingsAutoCreditInstruction $instruction): array
    {
        return $this->many(collect([$instruction]))[0];
    }

    /**
     * @param  Collection<int, SavingsAutoCreditInstruction>  $instructions
     * @return list<array<string, mixed>>
     */
    public function many(Collection $instructions): array
    {
        if ($instructions->isEmpty()) {
            return [];
        }

        $instructions = (new EloquentCollection($instructions->all()))
            ->loadMissing(['account:id,account_number,borrower_id,branch_id,product_id', 'account.borrower', 'account.branch:id,name', 'account.product:id,name', 'creator', 'updater']);

        $lastCycles = SavingsAutoCreditPosting::query()
            ->whereIn('instruction_id', $instructions->pluck('id'))
            ->groupBy('instruction_id')
            ->selectRaw('instruction_id, MAX(cycle_date) as last_cycle_date')
            ->pluck('last_cycle_date', 'instruction_id');

        return $instructions->map(fn (SavingsAutoCreditInstruction $i): array => [
            'id' => $i->id,
            'account_id' => $i->account_id,
            'account_number' => $i->account?->account_number,
            'borrower_name' => $i->account?->borrower?->full_name,
            'branch_name' => $i->account?->branch?->name,
            'product_name' => $i->account?->product?->name,
            'amount' => SavingsMath::pesos(SavingsMath::centavos($i->amount)),
            'schedule' => $i->schedule,
            'schedule_label' => SavingsAutoCreditInstruction::SCHEDULE_WORDS[$i->schedule] ?? $i->schedule,
            'active' => (bool) $i->active,
            'authorised_on' => $i->authorised_on?->toDateString(),
            'authorised_by' => $i->authorised_by,
            'remarks' => $i->remarks,
            'last_cycle_date' => isset($lastCycles[$i->id]) ? substr((string) $lastCycles[$i->id], 0, 10) : null,
            'created_by' => SavingsUserRef::from($i->creator),
            'updated_by' => SavingsUserRef::from($i->updater),
            'created_at' => $i->created_at?->toJSON(),
            'updated_at' => $i->updated_at?->toJSON(),
        ])->values()->all();
    }
}

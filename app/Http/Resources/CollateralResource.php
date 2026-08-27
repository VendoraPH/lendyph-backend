<?php

namespace App\Http\Resources;

use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CollateralResource extends JsonResource
{
    /**
     * `active_loans` is the server's answer to "is this collateral already
     * pledged?" — every loan in Loan::ACTIVE_STATUSES holding it, as
     * `{id, loan_account_number}`. Empty array means free. It is present on
     * every CollateralController response because the controller eager-loads
     * `activeLoans` on all of them; the key is omitted rather than faked when
     * the relation is missing, so an absent key means "not asked", never "free".
     *
     * On GET /loans/{loan}/collaterals the list includes the loan being viewed
     * when that loan is itself active — the field answers "who holds this",
     * not "who else holds this".
     *
     * @return array{
     *     id: int,
     *     borrower_id: int,
     *     collateral_type_id: int,
     *     detail_value: string|null,
     *     amount: float,
     *     active_loans?: array<int, array{id: int, loan_account_number: string|null}>,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'borrower_id' => $this->borrower_id,
            'collateral_type_id' => $this->collateral_type_id,
            'detail_value' => $this->detail_value,
            'amount' => (float) $this->amount,
            'collateral_type' => $this->whenLoaded(
                'collateralType',
                fn () => new CollateralTypeResource($this->collateralType)
            ),
            'active_loans' => $this->whenLoaded(
                'activeLoans',
                fn () => $this->activeLoans
                    ->map(fn (Loan $loan) => [
                        'id' => $loan->id,
                        'loan_account_number' => $loan->loan_account_number,
                    ])
                    ->values()
                    ->all()
            ),
            'pivot' => $this->whenPivotLoaded('loan_collaterals', fn () => [
                'loan_id' => $this->pivot->loan_id,
                'snapshot_value' => (float) $this->pivot->snapshot_value,
                'attached_at' => $this->pivot->attached_at,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

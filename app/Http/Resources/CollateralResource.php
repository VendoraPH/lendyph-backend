<?php

namespace App\Http\Resources;

use App\Models\Collateral;
use App\Models\Loan;
use App\Models\User;
use App\Services\CollateralValuation;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Collateral',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'borrower_id', type: 'integer'),
        new OA\Property(property: 'collateral_type_id', type: 'integer'),
        new OA\Property(property: 'detail_value', type: 'string', nullable: true),
        new OA\Property(property: 'amount', type: 'number', description: 'The amount entered on the collateral. A share capital collateral is valued from the member\'s balance instead; see `effective_value`.'),
        new OA\Property(property: 'effective_value', type: 'number', description: 'What the collateral is worth as security. `amount` for every type except share capital. For share capital, the member\'s share capital balance: credits minus debits across their whole ledger, which can be negative and is 0 for a member with no entries, carried in full by each of that member\'s share capital collaterals. 0 when `value_unknown` is true.'),
        new OA\Property(property: 'value_unknown', type: 'boolean', description: 'True only for a share capital collateral when the caller lacks `share_capital:view`, so the balance behind it is withheld and `effective_value` is 0. Always false for every other type.'),
        new OA\Property(property: 'collateral_type', type: 'object', description: 'The collateral type, including its `source` (`manual` or `share_capital`).'),
        new OA\Property(
            property: 'active_loans',
            type: 'array',
            description: 'Every loan in an active status holding this collateral. Empty means free.',
            items: new OA\Items(properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'loan_account_number', type: 'string', nullable: true),
            ]),
        ),
        new OA\Property(
            property: 'pivot',
            type: 'object',
            description: 'Present only on the loan collateral list and the attach response.',
            properties: [
                new OA\Property(property: 'loan_id', type: 'integer'),
                new OA\Property(property: 'snapshot_value', type: 'number', description: 'The value the operator stated when attaching it to this loan.'),
                new OA\Property(property: 'attached_at', type: 'string', format: 'date-time', nullable: true),
            ],
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
class CollateralResource extends JsonResource
{
    /**
     * Set only by valued() and valuedCollection(). A resource built any other
     * way was never valued, and omits `effective_value` and `value_unknown`
     * rather than reporting a share capital collateral as worth 0.
     *
     * @var array{effective_value: float, value_unknown: bool}|null
     */
    private ?array $valuation = null;

    /**
     * One collateral, valued for the user asking. See CollateralValuation.
     */
    public static function valued(Collateral $collateral, User $viewer): self
    {
        $valuations = CollateralValuation::forCollaterals(new EloquentCollection([$collateral]), $viewer);

        return (new self($collateral))->withValuation($valuations[$collateral->id]);
    }

    /**
     * A list of collaterals, valued for the user asking, with every share
     * capital balance read in one query. See CollateralValuation.
     *
     * @param  EloquentCollection<int, Collateral>  $collaterals
     */
    public static function valuedCollection(EloquentCollection $collaterals, User $viewer): AnonymousResourceCollection
    {
        $valuations = CollateralValuation::forCollaterals($collaterals, $viewer);

        return self::collection($collaterals->map(
            fn (Collateral $collateral): self => (new self($collateral))->withValuation($valuations[$collateral->id])
        ));
    }

    /**
     * @param  array{effective_value: float, value_unknown: bool}  $valuation
     */
    private function withValuation(array $valuation): self
    {
        $this->valuation = $valuation;

        return $this;
    }

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
     * `effective_value` and `value_unknown` follow the same rule: present on
     * every CollateralController response because each one is built through
     * valued() or valuedCollection(), and absent from a resource that was not.
     *
     * @return array{
     *     id: int,
     *     borrower_id: int,
     *     collateral_type_id: int,
     *     detail_value: string|null,
     *     amount: float,
     *     effective_value?: float,
     *     value_unknown?: bool,
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
            'effective_value' => $this->when($this->valuation !== null, fn () => $this->valuation['effective_value']),
            'value_unknown' => $this->when($this->valuation !== null, fn () => $this->valuation['value_unknown']),
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

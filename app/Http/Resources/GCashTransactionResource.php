<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'GCashTransaction',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'reference_no', type: 'string'),
        new OA\Property(property: 'transaction_date', type: 'string', format: 'date-time'),
        new OA\Property(property: 'type', type: 'string', enum: ['cash_in', 'cash_out']),
        new OA\Property(property: 'amount', type: 'number'),
        new OA\Property(property: 'charge_amount', type: 'number'),
        new OA\Property(property: 'total_amount', type: 'number'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'paid', 'completed']),
        new OA\Property(property: 'borrower_id', type: 'integer', nullable: true),
        new OA\Property(property: 'gcash_non_member_id', type: 'integer', nullable: true, description: 'Set instead of borrower_id when the counter served a walk-in.'),
        new OA\Property(property: 'transactor_user_id', type: 'integer'),
        new OA\Property(property: 'remarks', type: 'string', nullable: true),
        new OA\Property(property: 'paid_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'paid_by_user_id', type: 'integer', nullable: true),
        new OA\Property(property: 'days_pending', type: 'integer', nullable: true, description: 'Whole days since transaction_date; only included by /reports/pending.'),
        new OA\Property(property: 'branch_id', type: 'integer', nullable: true, description: 'The walk-in\'s recorded branch (null for a member\'s and for head office). On /reports/pending only: the branch the row posts in, the member\'s branch for a member.'),
        new OA\Property(property: 'branch_name', type: 'string', nullable: true, description: 'Only included by /reports/pending: the name of that branch, or "Head office" when none.'),
    ],
)]
class GCashTransactionResource extends JsonResource
{
    /** A pending report row with no branch: a walk-in recorded at head office. */
    public const HEAD_OFFICE = 'Head office';

    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'reference_no' => $this->reference_no,
            'transaction_date' => $this->transaction_date?->toIso8601String(),
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'charge_amount' => (float) $this->charge_amount,
            'total_amount' => (float) $this->total_amount,
            'status' => $this->status,
            'borrower_id' => $this->borrower_id,
            'borrower' => $this->whenLoaded('borrower', fn () => [
                'id' => $this->borrower->id,
                'full_name' => $this->borrower->full_name,
                'borrower_code' => $this->borrower->borrower_code,
            ]),
            'gcash_non_member_id' => $this->gcash_non_member_id,
            // The branch a walk-in transaction was done in; null for a
            // member's and for head office.
            'branch_id' => $this->branch_id,
            'non_member' => $this->whenLoaded('nonMember', fn () => [
                'id' => $this->nonMember->id,
                'full_name' => $this->nonMember->full_name,
                'mobile_number' => $this->nonMember->mobile_number,
            ]),
            'transactor_user_id' => $this->transactor_user_id,
            'transactor_user' => $this->whenLoaded('transactor', fn () => [
                'id' => $this->transactor->id,
                'full_name' => trim($this->transactor->first_name.' '.$this->transactor->last_name),
            ]),
            'remarks' => $this->remarks,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'paid_by_user_id' => $this->paid_by_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($request->routeIs('gcash.reports.pending')) {
            if ($this->transaction_date) {
                $data['days_pending'] = (int) $this->transaction_date->copy()->startOfDay()
                    ->diffInDays(now()->startOfDay());
            }

            // The branch whose books the row posts in: a member's branch for
            // a member, the recorded branch for a walk-in, none for head office.
            $branch = $this->borrower_id !== null ? $this->borrower?->branch : $this->branch;
            $data['branch_id'] = $branch?->id;
            $data['branch_name'] = $branch?->name ?? self::HEAD_OFFICE;
        }

        return $data;
    }
}

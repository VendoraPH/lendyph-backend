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
        new OA\Property(property: 'borrower_id', type: 'integer'),
        new OA\Property(property: 'transactor_user_id', type: 'integer'),
        new OA\Property(property: 'remarks', type: 'string', nullable: true),
        new OA\Property(property: 'paid_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'paid_by_user_id', type: 'integer', nullable: true),
        new OA\Property(property: 'days_pending', type: 'integer', nullable: true, description: 'Whole days since transaction_date; only included by /reports/pending.'),
    ],
)]
class GCashTransactionResource extends JsonResource
{
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

        if ($request->routeIs('gcash.reports.pending') && $this->transaction_date) {
            $data['days_pending'] = (int) $this->transaction_date->copy()->startOfDay()
                ->diffInDays(now()->startOfDay());
        }

        return $data;
    }
}

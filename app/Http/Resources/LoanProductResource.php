<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LoanProduct',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'interest_rate', type: 'number'),
        new OA\Property(property: 'min_interest_rate', type: 'number', nullable: true),
        new OA\Property(property: 'max_interest_rate', type: 'number', description: 'Alias for interest_rate'),
        new OA\Property(property: 'interest_method', type: 'string'),
        new OA\Property(property: 'term', type: 'integer'),
        new OA\Property(property: 'min_term', type: 'integer', nullable: true),
        new OA\Property(property: 'max_term', type: 'integer', nullable: true),
        new OA\Property(property: 'frequency', type: 'string'),
        new OA\Property(property: 'frequencies', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'processing_fee', type: 'number'),
        new OA\Property(property: 'min_processing_fee', type: 'number', nullable: true),
        new OA\Property(property: 'max_processing_fee', type: 'number', nullable: true),
        new OA\Property(property: 'service_fee', type: 'number'),
        new OA\Property(property: 'min_service_fee', type: 'number', nullable: true),
        new OA\Property(property: 'max_service_fee', type: 'number', nullable: true),
        new OA\Property(property: 'notarial_fee', type: 'number', nullable: true),
        new OA\Property(property: 'custom_fees', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'penalty_rate', type: 'number'),
        new OA\Property(property: 'grace_period_days', type: 'integer'),
        new OA\Property(property: 'scb_required', type: 'boolean', description: 'Share Capital Build-Up is mandatory for loans from this product'),
        new OA\Property(property: 'min_scb', type: 'number', description: 'Minimum SCB amount per payment'),
        new OA\Property(property: 'max_scb', type: 'number', description: 'Maximum SCB amount per payment'),
        new OA\Property(property: 'min_amount', type: 'number'),
        new OA\Property(property: 'max_amount', type: 'number'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive']),
    ],
)]
class LoanProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'interest_rate' => $this->interest_rate,
            'min_interest_rate' => $this->min_interest_rate,
            'max_interest_rate' => $this->interest_rate,
            'interest_method' => $this->interest_method,
            'term' => $this->term,
            'min_term' => $this->min_term,
            'max_term' => $this->max_term,
            'frequency' => $this->frequency,
            'frequencies' => $this->frequencies ?? [$this->frequency],
            'processing_fee' => $this->processing_fee,
            'min_processing_fee' => $this->min_processing_fee,
            'max_processing_fee' => $this->max_processing_fee,
            'service_fee' => $this->service_fee,
            'min_service_fee' => $this->min_service_fee,
            'max_service_fee' => $this->max_service_fee,
            'notarial_fee' => $this->notarial_fee,
            'custom_fees' => $this->custom_fees ?? [],
            'penalty_rate' => $this->penalty_rate,
            'grace_period_days' => $this->grace_period_days,
            'scb_required' => (bool) $this->scb_required,
            'min_scb' => (float) $this->min_scb,
            'max_scb' => (float) $this->max_scb,
            'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

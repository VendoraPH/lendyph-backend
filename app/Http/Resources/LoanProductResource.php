<?php

namespace App\Http\Resources;

use App\Models\LoanProduct;
use App\Services\Penalties\PenaltyTerms;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\AbstractPaginator;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LoanProduct',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'interest_rate', type: 'number'),
        new OA\Property(property: 'interest_rate_frequency', type: 'string', enum: ['daily', 'weekly', 'bi_weekly', 'semi_monthly', 'monthly']),
        new OA\Property(property: 'min_interest_rate', type: 'number', nullable: true),
        new OA\Property(property: 'max_interest_rate', type: 'number', description: 'Alias for interest_rate'),
        new OA\Property(property: 'interest_method', type: 'string'),
        new OA\Property(property: 'term', type: 'integer'),
        new OA\Property(property: 'term_unit', type: 'string', enum: ['months', 'days']),
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
        new OA\Property(property: 'notarial_fee', type: 'number', nullable: true, description: 'Percentage of principal, applied as an auto-deduction on loan creation'),
        new OA\Property(property: 'custom_fees', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'penalty_rate', type: 'number', description: 'The product\'s own late penalty rate. A new loan copies it only when no active penalty configuration covers the product (see `penalty_source`).'),
        new OA\Property(property: 'penalty_source', type: 'object', description: 'Where a new loan on this product takes its late penalty from: `configuration` (an active penalty configuration covers it), `fixed_rate` (none does, and `penalty_rate` is above 0) or `none`.', properties: [
            new OA\Property(property: 'type', type: 'string', enum: ['configuration', 'fixed_rate', 'none']),
            new OA\Property(property: 'config_id', type: 'integer', nullable: true),
            new OA\Property(property: 'config_name', type: 'string', nullable: true),
            new OA\Property(property: 'rate', type: 'number', nullable: true, description: 'The fixed rate in percent, never rounded; null unless `fixed_rate`.'),
        ]),
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
    /**
     * A list loads every product's active penalty configuration in one query
     * for `penalty_source`.
     *
     * @param  mixed  $resource
     */
    public static function collection($resource): AnonymousResourceCollection
    {
        $products = match (true) {
            $resource instanceof EloquentCollection => $resource,
            $resource instanceof AbstractPaginator => $resource->getCollection(),
            default => null,
        };

        if ($products instanceof EloquentCollection && $products->every(fn (mixed $product): bool => $product instanceof LoanProduct)) {
            $products->loadMissing('activePenaltyConfigs');
        }

        return parent::collection($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'interest_rate' => $this->interest_rate,
            'min_interest_rate' => $this->min_interest_rate,
            'max_interest_rate' => $this->interest_rate,
            'interest_rate_frequency' => $this->interest_rate_frequency,
            'interest_method' => $this->interest_method,
            'term' => $this->term,
            'min_term' => $this->min_term,
            'max_term' => $this->max_term,
            'term_unit' => $this->term_unit,
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
            'penalty_source' => PenaltyTerms::sourceFor($this->resource),
            'grace_period_days' => $this->grace_period_days,
            'scb_required' => (bool) $this->scb_required,
            'min_scb' => (float) $this->min_scb,
            'max_scb' => (float) $this->max_scb,
            'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount,
            'status' => $this->status,
            // Frontend-canonical boolean alias for the activate/deactivate toggle.
            'is_active' => $this->status === 'active',
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

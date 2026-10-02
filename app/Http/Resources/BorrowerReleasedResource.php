<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * One borrower's row on the Borrowers with Loans Released report: a Borrower
 * from ReportService::borrowersReleased(), carrying the database's count and
 * sum of the loans released to them in the period, with those loans attached
 * as `releasedLoans`, oldest release first.
 */
#[OA\Schema(
    schema: 'BorrowerReleasedRow',
    properties: [
        new OA\Property(property: 'borrower_id', type: 'integer'),
        new OA\Property(property: 'borrower_name', type: 'string'),
        new OA\Property(property: 'loan_count', type: 'integer', description: 'Loans released to this borrower in the period.'),
        new OA\Property(property: 'loan_account_numbers', type: 'array', items: new OA\Items(type: 'string'), description: 'Those loans, oldest release first (ties by loan id). A loan with no number is left out here but still counted in `loan_count`.'),
        new OA\Property(property: 'total_principal', type: 'number', description: 'SUM(principal_amount) of those loans, rounded to 2 decimals.'),
        new OA\Property(property: 'last_released_at', type: 'string', format: 'date', nullable: true, description: 'The newest release date in the period; null when none of the loans carries one.'),
        new OA\Property(property: 'latest_loan_status', type: 'string', description: 'The raw `loans.status` of the newest release (ties by highest loan id).'),
    ],
)]
class BorrowerReleasedResource extends JsonResource
{
    /**
     * @return array{
     *     borrower_id: int,
     *     borrower_name: string,
     *     loan_count: int,
     *     loan_account_numbers: array<int, string>,
     *     total_principal: float,
     *     last_released_at: string|null,
     *     latest_loan_status: string|null,
     * }
     */
    public function toArray(Request $request): array
    {
        $loans = $this->releasedLoans;

        return [
            'borrower_id' => (int) $this->id,
            'borrower_name' => $this->full_name,
            'loan_count' => (int) $this->loan_count,
            'loan_account_numbers' => $loans->pluck('loan_account_number')->filter()->values()->all(),
            'total_principal' => round((float) $this->total_principal, 2),
            // `loans.released_at` is nullable: a loan released without a date
            // must not read as released today.
            'last_released_at' => $this->last_released_at === null
                ? null
                : Carbon::parse($this->last_released_at)->toDateString(),
            'latest_loan_status' => $loans->last()?->status,
        ];
    }
}

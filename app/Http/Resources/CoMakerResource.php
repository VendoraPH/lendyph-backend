<?php

namespace App\Http\Resources;

use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoMakerResource extends JsonResource
{
    /**
     * `added_by` (a user id, or null for links made before it was recorded)
     * and `added_at` say who linked this co-maker to a loan, and when. They
     * come from the `co_maker_loan` pivot, so they appear only where the
     * co-maker was read through a loan — a loan's `co_makers`, or
     * POST /loans/{loan}/co-makers — and are absent from the borrower's
     * co-maker list, where there is no one loan to speak of.
     *
     * `loans` is the other direction — every loan this co-maker is on, as
     * `{id, application_number, loan_account_number, status}` — and appears
     * only where the caller eager-loaded it: the borrower's co-maker list.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'co_maker_code' => $this->co_maker_code,
            'borrower_id' => $this->borrower_id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'full_name' => $this->full_name,
            'address' => $this->address,
            'contact_number' => $this->contact_number,
            'occupation' => $this->occupation,
            'employer' => $this->employer,
            'monthly_income' => $this->monthly_income,
            'relationship_to_borrower' => $this->relationship_to_borrower,
            'status' => $this->status,
            'borrower' => new BorrowerResource($this->whenLoaded('borrower')),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'added_by' => $this->whenPivotLoaded('co_maker_loan', fn () => $this->pivot->added_by),
            'added_at' => $this->whenPivotLoaded('co_maker_loan', fn () => $this->pivot->created_at),
            'loans' => $this->whenLoaded('loans', fn () => $this->loans
                ->map(fn (Loan $loan) => [
                    'id' => $loan->id,
                    'application_number' => $loan->application_number,
                    'loan_account_number' => $loan->loan_account_number,
                    'status' => $loan->status,
                ])
                ->values()
                ->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

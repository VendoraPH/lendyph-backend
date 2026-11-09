<?php

namespace App\Http\Resources;

use App\Models\CoMaker;
use App\Models\Loan;
use App\Services\PersonalDataKeys;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class CoMakerResource extends JsonResource
{
    /**
     * A co-maker for a caller who may not read their record: who they are and
     * how they relate to the member, never their address, contact number, work
     * or income (PersonalDataKeys::CO_MAKER).
     *
     * @return array<string, mixed>
     */
    public static function slim(CoMaker $coMaker): array
    {
        return Arr::except(self::record($coMaker), PersonalDataKeys::CO_MAKER);
    }

    /**
     * The co-maker's own fields, personal ones included.
     *
     * @return array<string, mixed>
     */
    private static function record(CoMaker $coMaker): array
    {
        return [
            'id' => $coMaker->id,
            'co_maker_code' => $coMaker->co_maker_code,
            'borrower_id' => $coMaker->borrower_id,
            'first_name' => $coMaker->first_name,
            'middle_name' => $coMaker->middle_name,
            'last_name' => $coMaker->last_name,
            'suffix' => $coMaker->suffix,
            'full_name' => $coMaker->full_name,
            'address' => $coMaker->address,
            'contact_number' => $coMaker->contact_number,
            'occupation' => $coMaker->occupation,
            'employer' => $coMaker->employer,
            'monthly_income' => $coMaker->monthly_income,
            'relationship_to_borrower' => $coMaker->relationship_to_borrower,
            'status' => $coMaker->status,
        ];
    }

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
            ...self::record($this->resource),
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

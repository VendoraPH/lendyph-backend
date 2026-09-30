<?php

namespace App\Http\Requests\Loan;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload for POST /loans/{loan}/co-makers — the release dialog's
 * "Add Co-Maker".
 *
 * Either `co_maker_id`, one of the borrower's existing co-maker records, OR a
 * new co-maker's details under the same rules as StoreCoMakerRequest. Never
 * both: a request carrying both would have one of them silently ignored, so
 * it is refused instead.
 *
 * Whether the loan is still awaiting release, and whether an existing
 * co-maker is still active, are not checked here. Both are decided under row
 * locks in LoanService::addCoMakerAwaitingRelease(), where a concurrent
 * release or deactivation cannot slip in between the check and the link.
 */
class AddLoanCoMakerRequest extends FormRequest
{
    /**
     * The new co-maker's fields, the ones `co_maker_id` excludes.
     *
     * @var list<string>
     */
    private const NEW_CO_MAKER_FIELDS = [
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'address',
        'contact_number',
        'occupation',
        'employer',
        'monthly_income',
        'relationship_to_borrower',
    ];

    /**
     * `loans:release` and nothing else, by owner decision: this is part of
     * releasing the loan, so it belongs to whoever may release it. Not
     * `borrowers:create`, even though a new co-maker record is created on the
     * borrower — the cashier who releases loans does not hold it, and a role
     * that holds it but cannot release has no business in the release dialog.
     */
    public function authorize(): bool
    {
        return $this->user()->can('loans:release');
    }

    public function rules(): array
    {
        /** @var Loan $loan */
        $loan = $this->route('loan');

        return [
            'co_maker_id' => [
                'nullable',
                'integer',
                'prohibits:'.implode(',', self::NEW_CO_MAKER_FIELDS),
                // Only the borrower's own co-makers — the list the Co-makers
                // tab shows for them.
                Rule::exists('co_makers', 'id')->where('borrower_id', $loan->borrower_id),
            ],
            'first_name' => ['required_without:co_maker_id', 'nullable', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required_without:co_maker_id', 'nullable', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:1000'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'employer' => ['nullable', 'string', 'max:255'],
            'monthly_income' => ['nullable', 'numeric', 'min:0'],
            'relationship_to_borrower' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'co_maker_id.exists' => "The selected co-maker is not one of this borrower's co-makers.",
            'co_maker_id.prohibits' => "Send either co_maker_id or a new co-maker's details, not both.",
            'first_name.required_without' => 'The first name is required when adding a new co-maker.',
            'last_name.required_without' => 'The last name is required when adding a new co-maker.',
        ];
    }
}

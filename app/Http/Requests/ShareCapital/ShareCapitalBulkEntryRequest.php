<?php

namespace App\Http\Requests\ShareCapital;

use App\Http\Requests\Concerns\ReferenceNumberMessages;
use App\Models\Borrower;
use App\Services\BranchAccess;
use App\Services\ShareCapitalService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class ShareCapitalBulkEntryRequest extends FormRequest
{
    use ReferenceNumberMessages;

    public function authorize(): bool
    {
        return $this->user()->can('share_capital:create');
    }

    /**
     * `decimal:0,2` on the amount: its column is decimal(12,2) and the model
     * casts it `decimal:2`, which would round a third decimal away without a
     * word (₱1,500.555 saved as ₱1,500.56). Exact centavos are accepted; more
     * than two decimals is a 422 on the field, never a silent rounding.
     *
     * One `method` and `reference_number` for the whole batch, stored on each
     * row; `reference_number` is required unless cash.
     *
     * The date can't be after today, as Record Payment's.
     */
    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.pledge_id' => ['required', 'integer', $this->visiblePledgeRule()],
            'entries.*.amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'entries.*.type' => ['required', 'in:credit,debit'],
            'entries.*.date' => ['required', 'date', 'before_or_equal:today'],
            'entries.*.description' => ['nullable', 'string', 'max:255'],
            'method' => ['sometimes', Rule::in(ShareCapitalService::ENTRY_METHODS)],
            'reference_number' => ['nullable', 'string', 'max:100', 'required_unless:method,cash'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->referenceNumberMessages();
    }

    /**
     * A batch sent without a method was paid in cash, as Record Payment
     * assumes (StoreRepaymentRequest).
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('method')) {
            $this->merge(['method' => 'cash']);
        }
    }

    /**
     * A pledge of a member in the caller's branches (BranchAccess): another
     * branch's pledge fails like a missing one.
     */
    private function visiblePledgeRule(): Exists
    {
        $rule = Rule::exists('share_capital_pledges', 'id');
        $branchIds = app(BranchAccess::class)->for($this->user());

        return $branchIds === null
            ? $rule
            : $rule->where(fn ($query) => $query->whereIn(
                'borrower_id',
                Borrower::query()->whereIn('branch_id', $branchIds)->select('id'),
            ));
    }
}

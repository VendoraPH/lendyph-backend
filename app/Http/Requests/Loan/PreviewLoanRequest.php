<?php

namespace App\Http\Requests\Loan;

use App\Enums\LoanFrequency;
use App\Http\Requests\Concerns\ValidatesDeductionAmounts;
use App\Models\Collateral;
use App\Models\Loan;
use App\Services\CollateralValuation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The loan form's figures, asked for while it is being filled in: every field
 * is optional, and the preview computes whatever its sections can from what
 * was sent (LoanService::formPreview()).
 *
 * Open to whoever can create or edit a loan, the two uses of the form. The
 * product's amount and rate ranges are not checked here: the form states them
 * beside its fields, and saving the loan enforces them. A term outside the
 * product's range simply has no schedule (LoanService::formPreview()).
 *
 * With `source_loan_id` it is the restructure page's preview, open to whoever
 * can restructure, as POST /loans/{id}/restructure is, and it adds that page's
 * `restructure` figures.
 *
 * Collateral is listed by id only. The server values each row from the
 * borrower's register (`borrower_id`, or the source loan's borrower on a
 * restructure), so a sent `snapshot_value` is ignored and a collateral on
 * someone else's register is refused. A share capital collateral is valued at
 * its member's balance, so a caller who may not see balances cannot preview
 * one (see after()).
 */
class PreviewLoanRequest extends FormRequest
{
    use ValidatesDeductionAmounts;

    public function authorize(): bool
    {
        if ($this->filled('source_loan_id')) {
            return $this->user()->can('loans:restructure');
        }

        return $this->user()->canAny(['loans:create', 'loans:update']);
    }

    public function rules(): array
    {
        return [
            'source_loan_id' => ['nullable', 'integer', 'exists:loans,id'],
            // Needed once collateral is listed, to find it on the register;
            // a restructure takes the source loan's borrower instead.
            'borrower_id' => [
                Rule::requiredIf(fn (): bool => ! $this->filled('source_loan_id') && ! empty($this->input('collaterals'))),
                'nullable',
                'integer',
                'exists:borrowers,id',
            ],
            'loan_product_id' => ['nullable', 'integer', 'exists:loan_products,id'],
            'principal_amount' => ['nullable', 'numeric', 'min:0'],
            // As StoreLoanRequest takes them, so the preview never shows a
            // rate saving would refuse.
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'term' => ['nullable', 'integer', 'min:1'],
            'frequency' => ['nullable', LoanFrequency::rule()],
            'start_date' => ['nullable', 'date'],
            'scb_amount' => ['nullable', 'numeric', 'min:0'],
            'collaterals' => ['nullable', 'array'],
            'collaterals.*.collateral_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('collaterals', 'id')->where('borrower_id', $this->registerBorrowerId() ?? 0),
            ],
            // The deduction inputs exactly as a create, an edit or a restructure
            // sends them, under the same rules, so the preview never lists a
            // deduction saving would refuse. Absent or null: the product's own
            // fees, as createLoan() falls back; `[]`: none.
            'deductions' => ['nullable', 'array'],
            'deductions.*.name' => ['required_with:deductions', 'string', 'max:255'],
            'deductions.*.amount' => $this->deductionAmountRule(),
            'deductions.*.type' => ['required_with:deductions', 'in:fixed,percentage'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'collaterals.*.collateral_id.exists' => 'This collateral is not registered to this borrower.',
            'collaterals.*.collateral_id.distinct' => 'Each collateral can be listed only once.',
        ];
    }

    /**
     * Refuses a share capital collateral to a caller without
     * `share_capital:view`: its value is the member's balance, so the preview's
     * total would show what the permission hides. Only once the list is valid.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $rows = $this->input('collaterals');

                if (! is_array($rows) || $rows === [] || $this->user()->can('share_capital:view')) {
                    return;
                }

                if ($validator->errors()->hasAny(['borrower_id', 'source_loan_id', 'collaterals', 'collaterals.*'])) {
                    return;
                }

                $shareCapitalIds = Collateral::query()
                    ->whereKey(array_map(static fn (array $row): int => (int) $row['collateral_id'], $rows))
                    ->with('collateralType')
                    ->get()
                    ->filter(fn (Collateral $collateral): bool => CollateralValuation::isShareCapital($collateral))
                    ->modelKeys();

                foreach ($rows as $index => $row) {
                    if (in_array((int) $row['collateral_id'], $shareCapitalIds, true)) {
                        $validator->errors()->add(
                            "collaterals.{$index}.collateral_id",
                            'Previewing a share capital collateral needs the Share Capital: View permission.',
                        );
                    }
                }
            },
        ];
    }

    /**
     * The borrower whose register the listed collateral must be on: the source
     * loan's on a restructure, else `borrower_id`. Null when neither names one,
     * which matches no collateral.
     */
    private function registerBorrowerId(): ?int
    {
        $sourceLoanId = filter_var($this->input('source_loan_id'), FILTER_VALIDATE_INT);

        if ($sourceLoanId !== false) {
            $borrowerId = Loan::whereKey($sourceLoanId)->value('borrower_id');

            return $borrowerId === null ? null : (int) $borrowerId;
        }

        $borrowerId = filter_var($this->input('borrower_id'), FILTER_VALIDATE_INT);

        return $borrowerId === false ? null : $borrowerId;
    }
}

<?php

namespace App\Http\Requests\Loan;

use App\Enums\LoanFrequency;
use App\Http\Requests\Concerns\ValidatesDeductionAmounts;
use App\Models\Collateral;
use App\Models\Loan;
use App\Services\BranchAccess;
use App\Services\CollateralValuation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
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
 * `restructure` figures. With `loan_id` it is the edit page's preview, open to
 * whoever can edit a loan, as PUT /loans/{id} is: the loan's held pledges
 * count at the value they were pledged at. The restructure edit form sends
 * both, so one preview carries the edit's figures and the restructure's: each
 * id keeps its own permission and branch checks, and the edited loan must be
 * the restructure of that source (LoanService::formPreview()).
 *
 * Collateral is listed by id only. The server values each row from the
 * borrower's register (`borrower_id`, or the edited or source loan's
 * borrower), so a sent `snapshot_value` is ignored and a collateral on someone
 * else's register is refused. Listing collateral needs `collaterals:view`, and
 * a share capital collateral, valued at its member's balance, needs
 * `share_capital:view` too (see after()). With `loan_id` and no
 * `collaterals` list the loan's held pledges count, as PUT /loans/{id} leaves
 * them untouched; a held share capital pledge is not refused then, the
 * preview withholds the collateral figures instead (LoanService::formPreview()),
 * so the edit page's preview keeps working.
 */
class PreviewLoanRequest extends FormRequest
{
    use ValidatesDeductionAmounts;

    public function authorize(): bool
    {
        $needs = array_keys(array_filter([
            'loans:restructure' => $this->filled('source_loan_id'),
            'loans:update' => $this->filled('loan_id'),
        ]));

        if ($needs === []) {
            return $this->user()->canAny(['loans:create', 'loans:update']);
        }

        foreach ($needs as $permission) {
            if (! $this->user()->can($permission)) {
                return false;
            }
        }

        return true;
    }

    public function rules(): array
    {
        return [
            // Loans and members in the caller's branches only (BranchAccess);
            // another branch's id fails like a missing one.
            'source_loan_id' => ['nullable', 'integer', $this->visibleExists('loans')],
            'loan_id' => ['nullable', 'integer', $this->visibleExists('loans')],
            // Needed once collateral is listed, to find it on the register;
            // an edit or a restructure takes its loan's borrower instead.
            'borrower_id' => [
                Rule::requiredIf(fn (): bool => ! $this->filled('source_loan_id') && ! $this->filled('loan_id') && ! empty($this->input('collaterals'))),
                'nullable',
                'integer',
                $this->visibleExists('borrowers'),
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
            'collaterals.*.collateral_id.exists' => 'This collateral is not registered to this member.',
            'collaterals.*.collateral_id.distinct' => 'Each collateral can be listed only once.',
        ];
    }

    /**
     * Once the list is valid:
     *
     *  - Listing collateral needs `collaterals:view`: the preview values each
     *    row, which would otherwise read the register's amounts to a caller
     *    who may not open it.
     *  - A share capital collateral needs `share_capital:view`: its value is
     *    the member's balance, so the preview's total would show what the
     *    permission hides. On a restructure that includes the source loan's
     *    pledges, which count whether listed or not. (An edit with no list
     *    is withheld rather than refused: LoanService::formPreview().)
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['borrower_id', 'loan_id', 'source_loan_id', 'collaterals', 'collaterals.*'])) {
                    return;
                }

                $rows = $this->input('collaterals');
                $rows = is_array($rows) ? array_values($rows) : [];

                if ($rows !== [] && ! $this->user()->can('collaterals:view')) {
                    $validator->errors()->add('collaterals', 'Previewing collateral needs the Collaterals: View permission.');

                    return;
                }

                if ($this->user()->can('share_capital:view')) {
                    return;
                }

                $this->refuseShareCapital($validator, $rows);
            },
        ];
    }

    /**
     * Adds an error for each listed share capital collateral, and one on
     * `source_loan_id` when the source loan holds one that is not listed.
     *
     * @param  list<mixed>  $rows
     */
    private function refuseShareCapital(Validator $validator, array $rows): void
    {
        $listedIds = array_map(static fn (array $row): int => (int) $row['collateral_id'], $rows);
        $sourceIds = $this->heldCollateralIds('source_loan_id');

        $ids = array_values(array_unique([...$listedIds, ...$sourceIds]));

        if ($ids === []) {
            return;
        }

        $shareCapitalIds = Collateral::query()
            ->whereKey($ids)
            ->with('collateralType')
            ->get()
            ->filter(fn (Collateral $collateral): bool => CollateralValuation::isShareCapital($collateral))
            ->modelKeys();

        foreach ($listedIds as $index => $collateralId) {
            if (in_array($collateralId, $shareCapitalIds, true)) {
                $validator->errors()->add(
                    "collaterals.{$index}.collateral_id",
                    'Previewing a share capital collateral needs the Share Capital: View permission.',
                );
            }
        }

        if (array_diff(array_intersect($sourceIds, $shareCapitalIds), $listedIds) !== []) {
            $validator->errors()->add(
                'source_loan_id',
                'This loan is secured by share capital; previewing its restructure needs the Share Capital: View permission.',
            );
        }
    }

    /**
     * The collateral ids held by the loan named in `$field`, if the caller
     * can see it (BranchAccess); none otherwise.
     *
     * @return list<int>
     */
    private function heldCollateralIds(string $field): array
    {
        $loanId = filter_var($this->input($field), FILTER_VALIDATE_INT);

        if ($loanId === false) {
            return [];
        }

        return array_map('intval', Loan::visibleTo($this->user())->find($loanId)?->collaterals()->pluck('collaterals.id')->all() ?? []);
    }

    /**
     * The borrower whose register the listed collateral must be on: the
     * edited loan's, or the source loan's on a restructure, else
     * `borrower_id`. Null when none names one, which matches no collateral.
     */
    private function registerBorrowerId(): ?int
    {
        foreach (['loan_id', 'source_loan_id'] as $field) {
            $loanId = filter_var($this->input($field), FILTER_VALIDATE_INT);

            if ($loanId !== false) {
                // Only a loan the caller can see (BranchAccess): another
                // branch's loan names no register, so its collateral matches
                // nothing.
                $borrowerId = Loan::visibleTo($this->user())->whereKey($loanId)->value('borrower_id');

                return $borrowerId === null ? null : (int) $borrowerId;
            }
        }

        $borrowerId = filter_var($this->input('borrower_id'), FILTER_VALIDATE_INT);

        return $borrowerId === false ? null : $borrowerId;
    }

    private function visibleExists(string $table): Exists
    {
        return app(BranchAccess::class)->constrainExists(Rule::exists($table, 'id'), $this->user());
    }
}

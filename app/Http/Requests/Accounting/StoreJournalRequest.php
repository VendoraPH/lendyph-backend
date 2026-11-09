<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\Concerns\AssignsCallerBranch;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A manual journal entry, straight off the entry form.
 *
 * ## `total_debit` and `total_credit` are accepted and IGNORED
 *
 * `buildJournalPayload` sends them, and there is no reason to 422 a client for
 * being complete — but they are not read anywhere, by anything. The totals of a
 * journal are recomputed from the persisted lines when it posts (see
 * JournalPoster::post()), because taking a client's totals as authoritative is
 * exactly how a client bug becomes a LEDGER bug: a header that agreed with
 * itself while disagreeing with its own lines would survive every report in the
 * system, none of which join the two.
 *
 * ## `source` is forced to `manual`
 *
 * This route is the manual-entry screen and nothing else. `source` is what lets
 * any figure on any statement be traced back to the lending transaction that
 * caused it, so a client that could label its entry `loan_collection` could
 * make a hand-typed adjustment indistinguishable from an automatic posting.
 * Automatic sources are set by the posting engine, in the same transaction as
 * the event they describe.
 *
 * ## `date` is a plain day
 *
 * `date_format:Y-m-d`, as StoreExpenseRequest has it: a date sent with a time
 * or an offset is refused rather than stored as the sender's day. And an entry
 * with no branch that has a line on the allowance can't be dated after today
 * in Manila ({@see RefusesFutureNoBranchAllowanceDates}).
 */
class StoreJournalRequest extends FormRequest
{
    use AssignsCallerBranch;
    use RefusesFutureNoBranchAllowanceDates;
    use ValidatesJournalLines;

    public function authorize(): bool
    {
        return $this->user()->can('journals:create');
    }

    public function rules(): array
    {
        return array_merge([
            'date' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:64'],
            'description' => ['required', 'string', 'max:500'],
            'branch_id' => ['nullable', 'integer', $this->callerBranchRule()],
            // Accepted so a complete payload is not refused; never read.
            'total_debit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'total_credit' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ], $this->lineRules());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => 'Enter the entry date.',
            'date.date_format' => 'Enter the entry date as a plain date (year-month-day), with no time or time zone.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->validateLineSides($v);
            $this->refuseAFutureNoBranchAllowanceDate($v);
        });
    }

    /**
     * Once the date, branch and lines are valid: no branch, a line on the
     * allowance, and a date after today in Manila is refused on `date`.
     */
    private function refuseAFutureNoBranchAllowanceDate(Validator $validator): void
    {
        $errors = $validator->errors();

        if ($errors->has('date') || $errors->has('branch_id') || filled($this->input('branch_id'))) {
            return;
        }

        $date = (string) $this->input('date');
        $today = $this->todayInManila();
        $lines = $this->input('lines');

        if ($date <= $today || ! is_array($lines)) {
            return;
        }

        $allowance = $this->allowanceAccountAmong(array_map(
            static fn (mixed $line): mixed => is_array($line) ? ($line['account_id'] ?? null) : null,
            $lines,
        ));

        if ($allowance !== null) {
            $errors->add('date', sprintf(
                'An entry with no branch that has a line on %1$s can\'t be dated after today (%2$s). Date it %2$s or earlier, or choose a branch.',
                $this->accountInWords($allowance),
                $this->dayInWords($today),
            ));
        }
    }

    /**
     * The header, with `source` fixed and the client's totals dropped.
     *
     * @return array<string, mixed>
     */
    public function journalAttributes(): array
    {
        $validated = $this->validated();

        return [
            'date' => $validated['date'],
            'source' => 'manual',
            'reference' => $this->nullIfBlank($validated['reference'] ?? null),
            'description' => trim((string) $validated['description']),
            'branch_id' => $validated['branch_id'] ?? null,
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->defaultToCallerBranch();
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

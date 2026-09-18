<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing an expense that is already in the books.
 *
 * ## Only the descriptive fields, and this is the interesting part
 *
 * Recording an expense posts a journal in the same transaction, and a posted
 * journal is IMMUTABLE — that is the rule the whole accounting module is built
 * on. So `amount`, `expense_account_id`, `payment_account_id`, `date` and
 * `branch_id` cannot be edited here: changing any of them would leave the
 * expense saying one thing and its journal — which is what every statement is
 * actually summed from — saying another, with both looking correct on their own
 * screen. The Expenses page would show ₱20,000 and the income statement
 * ₱15,000, and nothing joins the two to notice.
 *
 * They are REFUSED with an explanation rather than silently dropped. Silently
 * dropping is worse than either alternative: the request returns 200, the
 * dialog closes, and the user believes they corrected a figure that did not
 * move. The remedy for a wrong amount is to reverse the journal and record the
 * expense again, which is the same remedy the journals screen offers and for
 * the same reason.
 *
 * `payee`, `reference`, `description` and `due_date` are free to change. None
 * of them is on the journal, and `due_date` in particular is the field someone
 * most often needs to fix — it is what decides whether a payable reads as
 * overdue.
 */
class UpdateExpenseRequest extends FormRequest
{
    /**
     * The fields a posted journal fixes in place, and the reason each one
     * cannot move independently of it.
     */
    private const IMMUTABLE_FIELDS = [
        'amount' => 'the amount is the journal entry',
        'expense_account_id' => 'the expense account is one side of the journal entry',
        'payment_account_id' => 'the payment account is the other side of the journal entry',
        'date' => 'the date is what puts the entry in a reporting period',
        'branch_id' => 'the branch is recorded on the entry',
    ];

    public function authorize(): bool
    {
        return $this->user()->can('expenses:update');
    }

    public function rules(): array
    {
        return [
            // `sometimes` throughout: this is a partial update, and a field left
            // out keeps its stored value.
            'payee' => ['sometimes', 'required', 'string', 'max:160'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('payee')) {
            $this->merge(['payee' => trim((string) $this->input('payee'))]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            foreach (self::IMMUTABLE_FIELDS as $field => $because) {
                if (! $this->has($field)) {
                    continue;
                }

                $v->errors()->add($field, ucfirst($because).', and the entry has already been posted. '
                    .'A posted entry cannot be edited — reverse the journal on the Journals screen and record '
                    .'the expense again.');
            }
        });
    }
}

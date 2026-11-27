<?php

namespace App\Http\Requests\Accounting;

use App\Models\AccountingAccount;
use App\Services\Accounting\Money;
use App\Services\BranchAccess;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /accounting/opening-balances: the whole draft, replacing its lines.
 *
 * Integer centavos, one side per line, one line per account, on postable
 * (active, non-heading) accounts. `lines` may be empty. The entry belongs to
 * the whole organisation, so this needs `branches:view_all` as well as
 * `accounting:settings`.
 */
class SaveOpeningBalanceRequest extends FormRequest
{
    use ValidatesJournalLines;

    /** The most lines one journal can carry. */
    public const MAX_LINES = 500;

    public function authorize(): bool
    {
        return $this->user()->can('accounting:settings') && $this->user()->can(BranchAccess::VIEW_ALL);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Not in the future: opening balances are what the organisation
            // has, and a future-dated entry is missing from every report run
            // before that day (the CLI refuses it for the same reason).
            'as_of' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'lines' => ['present', 'array', 'max:'.self::MAX_LINES],
            'lines.*' => ['required', 'array'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounting_accounts,id'],
            'lines.*.debit' => ['required', 'integer', 'min:0', 'max:'.Money::maxCentavos()],
            'lines.*.credit' => ['required', 'integer', 'min:0', 'max:'.Money::maxCentavos()],
            'edit_version' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'as_of.before_or_equal' => 'The opening balances can\'t be dated in the future.',
            'lines.max' => 'An opening balance can carry at most '.self::MAX_LINES.' lines.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->validateLineSides($v);
            $this->validateAccounts($v);
        });
    }

    /**
     * The lines as stored: account, debit and credit, ordered by account so
     * two saves of the same entry compare equal.
     *
     * @return list<array{account_id: int, debit: int, credit: int}>
     */
    public function openingLines(): array
    {
        $lines = array_values(array_map(static fn (array $line): array => [
            'account_id' => (int) $line['account_id'],
            'debit' => (int) $line['debit'],
            'credit' => (int) $line['credit'],
        ], $this->validated()['lines']));

        usort($lines, static fn (array $a, array $b): int => $a['account_id'] <=> $b['account_id']);

        return $lines;
    }

    /** One line per account, each on an account a journal line may reference. */
    private function validateAccounts(Validator $validator): void
    {
        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        $ids = [];

        foreach ($lines as $line) {
            if (($id = $this->accountIdOf($line)) !== null) {
                $ids[] = $id;
            }
        }

        $accounts = AccountingAccount::query()->whereKey(array_unique($ids))->get()->keyBy('id');
        $seen = [];

        foreach ($lines as $index => $line) {
            $field = "lines.{$index}.account_id";
            $id = $this->accountIdOf($line);
            $account = $id === null ? null : $accounts->get($id);

            if ($account === null || $validator->errors()->has($field)) {
                continue;
            }

            $label = 'Line '.($index + 1);
            $name = "{$account->code} {$account->name}";

            if (isset($seen[$account->id])) {
                $validator->errors()->add($field, "{$label} repeats {$name}, already on line {$seen[$account->id]}. Enter one balance per account.");

                continue;
            }

            $seen[$account->id] = $index + 1;

            if ($account->is_group) {
                $validator->errors()->add($field, "{$label} is on {$name}, a heading. Enter the balance on the accounts under it.");
            } elseif (! $account->is_active) {
                $validator->errors()->add($field, "{$label} is on {$name}, which is deactivated. Reactivate it or use the account that replaced it.");
            }
        }
    }

    /** A line's account id as the integer it is stored as ("5" and 5 alike), or null. */
    private function accountIdOf(mixed $line): ?int
    {
        $id = is_array($line) ? filter_var($line['account_id'] ?? null, FILTER_VALIDATE_INT) : false;

        return $id === false ? null : $id;
    }
}

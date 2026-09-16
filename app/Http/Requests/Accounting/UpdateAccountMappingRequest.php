<?php

namespace App\Http\Requests\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Re-pointing one or more posting roles.
 *
 * Accepts a partial map — the settings screen sends whatever the operator
 * changed — and leaves every role it does not mention exactly where it was.
 */
class UpdateAccountMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('accounting:settings');
    }

    public function rules(): array
    {
        $rules = [];

        foreach (AccountingAccountMapping::ROLES as $role) {
            // Not nullable: there is no such thing as a role pointing nowhere.
            // A rule that resolved to null would fail at the moment a loan is
            // released, so an operator who wants a role to change has to say
            // what it changes TO.
            $rules[$role] = ['sometimes', 'integer', Rule::exists('accounting_accounts', 'id')];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // `only()` returns just the keys that were actually sent, which is
            // what makes a partial update partial.
            $submitted = $this->only(AccountingAccountMapping::ROLES);

            $ids = array_values(array_unique(array_filter(
                array_map(static fn ($id) => is_numeric($id) ? (int) $id : null, $submitted),
            )));

            if ($ids === []) {
                return;
            }

            // One query for the whole payload rather than one per role: this
            // endpoint can carry all thirteen at once.
            $accounts = AccountingAccount::query()->whereKey($ids)->get()->keyBy('id');

            foreach ($submitted as $role => $id) {
                $account = $accounts->get((int) $id);

                if ($account === null) {
                    continue;
                }

                if ($account->is_group) {
                    $v->errors()->add(
                        $role,
                        "{$account->code} {$account->name} is a group heading and cannot be posted to. Its balance is the total of the accounts beneath it, so an entry against it would be counted twice.",
                    );

                    continue;
                }

                if (! $account->is_active) {
                    $v->errors()->add(
                        $role,
                        "{$account->code} {$account->name} is inactive, so an automatic entry resolving through it would fail. Reactivate it or choose another account.",
                    );

                    continue;
                }

                $this->assertAccountFitsTheRole($v, (string) $role, $account);
            }
        });
    }

    /**
     * The account must be the KIND of account the role means, not merely a
     * postable one.
     *
     * Postable-and-active is the check that stops an automatic entry from
     * failing. This is the check that stops one from succeeding and being
     * wrong, which is worse: the posting engine resolves a role and posts to
     * whatever comes back, the entry balances, every report renders it, and
     * nothing anywhere fails. What changes is the arithmetic —
     * `AccountRules::signedBalance()` reads `normal_balance`, so an
     * `allowance_credit_losses` pointed at a non-contra asset turns Net Loans
     * Receivable into gross PLUS the allowance. See
     * AccountingAccountMapping::ROLE_SHAPES for the full reasoning.
     */
    private function assertAccountFitsTheRole(Validator $v, string $role, AccountingAccount $account): void
    {
        $shape = AccountingAccountMapping::ROLE_SHAPES[$role] ?? null;

        // A role with no declared shape is refused rather than waved through:
        // it means ROLES gained an entry that ROLE_SHAPES did not, and
        // accepting it would let the one role nobody has thought about point
        // anywhere at all.
        if ($shape === null) {
            $v->errors()->add($role, "There is no defined account shape for the {$role} role, so it cannot be set.");

            return;
        }

        if ($account->type !== $shape['type']) {
            $v->errors()->add(
                $role,
                "{$account->code} {$account->name} is {$account->type}, but the {$role} role has to resolve to "
                ."an {$shape['type']} account. Posting through it would put the amount on the wrong statement, "
                .'and the entry would still balance — so nothing would report the mistake.',
            );

            return;
        }

        if (($shape['is_contra'] ?? false) && ! $account->is_contra) {
            $v->errors()->add(
                $role,
                "{$account->code} {$account->name} is not a contra account. The {$role} role must resolve to one: "
                .'a contra asset carries a credit balance and SUBTRACTS from the assets above it, so an ordinary '
                .'asset here would make the net figure come out as gross plus the allowance instead of minus.',
            );

            return;
        }

        $kind = $shape['cash_kind'] ?? null;

        if ($kind !== null && $account->cash_kind !== $kind) {
            $held = $account->cash_kind === null ? 'is not a money account at all' : "is a {$account->cash_kind} account";

            $v->errors()->add(
                $role,
                "{$account->code} {$account->name} {$held}, but the {$role} role has to resolve to one whose "
                ."cash kind is {$kind}. `cash_kind` is what puts an account on the Cash & Bank screen and into the "
                .'dashboard cash figures, so settlements through it would never appear in the money on hand.',
            );
        }
    }
}

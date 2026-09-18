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
            // endpoint can carry every role at once.
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
     * Receivable into gross PLUS the allowance.
     *
     * The rule itself lives on {@see AccountingAccountMapping::roleMismatch()}
     * because the SAME disagreement can be created from the other end, by
     * re-shaping the account this role already points at — see
     * ValidatesAccountShape. One statement of the rule, two doors.
     *
     * Errors are reported against the ROLE here, because the role is the field
     * the settings screen submitted.
     */
    private function assertAccountFitsTheRole(Validator $v, string $role, AccountingAccount $account): void
    {
        $mismatch = AccountingAccountMapping::roleMismatch(
            $role,
            "{$account->code} {$account->name}",
            (string) $account->type,
            (bool) $account->is_contra,
            $account->cash_kind,
        );

        if ($mismatch !== null) {
            $v->errors()->add($role, $mismatch['message']);
        }
    }
}

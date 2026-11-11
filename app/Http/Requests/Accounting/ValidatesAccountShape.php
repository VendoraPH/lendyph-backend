<?php

namespace App\Http\Requests\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingReconciliation;
use App\Services\Accounting\AccountRules;
use App\Services\BranchAccess;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Tests\Feature\AccountingReparentMappedAccountTest;

/**
 * The cross-field rules an account has to satisfy, shared by create and update.
 *
 * Each one exists because breaking it misreports money rather than merely
 * looking untidy, and every one of them is cheaper to refuse here than to find
 * later in a statement that does not balance.
 */
trait ValidatesAccountShape
{
    /**
     * Fields whose own rules must have passed before the shape checks are worth
     * running. Adding a second error to a field that is already wrong only
     * makes the response harder to read.
     *
     * @var list<string>
     */
    private const SHAPE_FIELDS = ['code', 'type', 'parent_id', 'cash_kind', 'is_group', 'is_active', 'is_contra'];

    /**
     * @param  AccountingAccount|null  $existing  the row being updated, if any.
     *                                            Absent fields fall back to its
     *                                            current values, so a PATCH-like
     *                                            PUT is checked against what the
     *                                            account will BE, not against
     *                                            the fragment that was sent.
     */
    protected function validateAccountShape(Validator $validator, ?AccountingAccount $existing = null): void
    {
        foreach (self::SHAPE_FIELDS as $field) {
            if ($validator->errors()->has($field)) {
                return;
            }
        }

        $code = (string) $this->finalValue('code', $existing?->code);
        $type = (string) $this->finalValue('type', $existing?->type);
        $parentId = $this->finalValue('parent_id', $existing?->parent_id);
        $cashKind = $this->finalValue('cash_kind', $existing?->cash_kind);
        $isGroup = (bool) $this->finalValue('is_group', $existing?->is_group ?? false);
        $isActive = (bool) $this->finalValue('is_active', $existing?->is_active ?? true);
        $isContra = (bool) $this->finalValue('is_contra', $existing?->is_contra ?? false);

        $this->assertCodeAgreesWithType($validator, $code, $type);
        $this->assertParentIsAGroupOfTheSameType($validator, $parentId, $type, $existing);
        $this->assertGroupsHoldNoMoney($validator, $isGroup, $cashKind);
        $this->assertOnlyAssetsHoldMoney($validator, $type, $cashKind);

        if ($existing !== null) {
            $this->assertHeadingsKeepTheirChildren($validator, $existing, $isGroup);
            $this->assertMappedAccountStaysPostable($validator, $existing, $isGroup, $isActive);
            $this->assertHistoryKeepsItsSign($validator, $existing, $type, $isContra);
            $this->assertMappedRolesStillFit($validator, $existing, $type, $isContra, $cashKind);
            $this->assertMappedRolesKeepTheirPlace($validator, $existing, $parentId);
            $this->assertMappedRolesKeepAnAllowedCode($validator, $existing, $code);
        }
    }

    /**
     * Every money account belongs to a branch, whose staff reconcile it, and
     * no other account does (owner answer 9, 2026-11-11).
     *
     * - A new money account needs a branch, and so does an account that
     *   becomes one. An existing money account with no branch yet (one the
     *   data fix could not place) keeps working for its other edits, but its
     *   branch can't be cleared once set.
     * - An account that holds no money takes no branch. One that stops holding
     *   money leaves its branch (the controller clears it).
     * - The branch can change only while the account has no reconciliation,
     *   so a reconciliation never starts proving another branch's books.
     *
     * Which branches may be named is the `branch_id` rule's job
     * ({@see self::branchRule()}).
     *
     * @param  AccountingAccount|null  $existing  the row being updated, if any
     */
    protected function validateAccountBranch(Validator $validator, ?AccountingAccount $existing = null): void
    {
        if ($validator->errors()->hasAny(['cash_kind', 'branch_id'])) {
            return;
        }

        $cashKind = $this->finalCashKind($existing);
        $sent = $this->has('branch_id');
        $branchId = $sent ? $this->input('branch_id') : $existing?->branch_id;
        $branchId = ($branchId === null || $branchId === '') ? null : (int) $branchId;

        if ($cashKind === null) {
            if ($sent && $branchId !== null) {
                $validator->errors()->add(
                    'branch_id',
                    'Only a money account belongs to a branch. This account holds no money, so leave its branch empty.',
                );
            }

            $branchId = null;
        } elseif ($branchId === null && ($existing === null || $existing->cash_kind === null || $sent)) {
            $validator->errors()->add(
                'branch_id',
                'A money account belongs to a branch: pick the branch whose staff reconcile it.',
            );

            return;
        }

        if ($existing === null || $branchId === $existing->branch_id) {
            return;
        }

        if ($existing->branch_id !== null && AccountingReconciliation::query()->where('accounting_account_id', $existing->id)->exists()) {
            $validator->errors()->add(
                $cashKind === null ? 'cash_kind' : 'branch_id',
                "{$existing->code} {$existing->name} already has a reconciliation, so it stays with its branch. "
                .'Its reconciliations prove that branch\'s books; open a new money account for the other branch instead.',
            );
        }
    }

    /** The `cash_kind` the account will hold after the save, null for none. */
    public function finalCashKind(?AccountingAccount $existing = null): ?string
    {
        $existing ??= $this->route('account');
        $cashKind = $this->finalValue('cash_kind', $existing?->cash_kind);

        return ($cashKind === null || $cashKind === '') ? null : (string) $cashKind;
    }

    /**
     * `branch_id`: a branch the caller can see, so a branch user can only
     * place an account in their own branches.
     *
     * @return list<mixed>
     */
    protected function branchRule(): array
    {
        return [
            'nullable',
            'integer',
            app(BranchAccess::class)->constrainBranchExists(Rule::exists('branches', 'id'), $this->user()),
        ];
    }

    /** "an asset code", "an income code", for the code-and-type message. */
    private function codeKindInWords(string $type): string
    {
        return in_array($type[0] ?? '', ['a', 'e', 'i', 'o', 'u'], true) ? "an {$type} code" : "a {$type} code";
    }

    /**
     * An account a role resolves to can't take a code that role may never
     * post to: the net surplus account renamed to 3050 would mix the year's
     * result into the opening balances, which the settings screen already
     * refuses from the other side
     * ({@see AccountingAccountMapping::reservedAccountReason()}). Only a code
     * that actually changes is checked, so the account's other edits keep
     * working.
     */
    private function assertMappedRolesKeepAnAllowedCode(
        Validator $validator,
        AccountingAccount $existing,
        string $code,
    ): void {
        if ($code === (string) $existing->code) {
            return;
        }

        $label = "{$existing->code} {$existing->name}";

        foreach ($existing->mappings()->pluck('role') as $role) {
            if (AccountingAccountMapping::reservedAccountReason($role, $code, $label) === null) {
                continue;
            }

            $validator->errors()->add(
                'code',
                "{$label} is the account for ".AccountingAccountMapping::label($role).", and {$code} is the opening "
                ."balance equity code: the year's result would be mixed into the opening balances. Keep another "
                .'code, or point that role somewhere else first.',
            );
        }
    }

    /** The value this field will hold after the save. */
    private function finalValue(string $field, mixed $current): mixed
    {
        return $this->has($field) ? $this->input($field) : $current;
    }

    /**
     * The leading digit decides which statement an account lands on, so an
     * account typed `asset` but numbered 4010 would appear under income on
     * every report while claiming to be an asset everywhere else. There is no
     * safe way to render that, and no way for a reader to tell which half is
     * lying.
     */
    private function assertCodeAgreesWithType(Validator $validator, string $code, string $type): void
    {
        $implied = AccountRules::typeFromCode($code);

        if ($implied === null) {
            $validator->errors()->add(
                'code',
                "Account code {$code} is outside the 1-5 ranges, so it has no classification. Codes are 1xxx asset, 2xxx liability, 3xxx equity, 4xxx income, 5xxx expense.",
            );

            return;
        }

        if ($implied !== $type) {
            $validator->errors()->add(
                'code',
                "Account code {$code} is ".$this->codeKindInWords($implied).", so it can't be ".AccountingAccountMapping::typeInWords($type).'. The leading digit decides which statement this account reports on.',
            );
        }
    }

    /**
     * A parent has to be a heading, and of the same classification.
     *
     * A postable parent would take entries alongside the children whose total
     * it displays; a parent of another type would put an asset inside the
     * equity section and quietly unbalance the balance sheet.
     */
    private function assertParentIsAGroupOfTheSameType(
        Validator $validator,
        mixed $parentId,
        string $type,
        ?AccountingAccount $existing,
    ): void {
        if ($parentId === null || $parentId === '') {
            return;
        }

        if ($existing !== null && (int) $parentId === $existing->id) {
            $validator->errors()->add('parent_id', 'An account cannot be its own parent.');

            return;
        }

        $parent = AccountingAccount::query()->find($parentId);

        if ($parent === null) {
            return;
        }

        if (! $parent->is_group) {
            $validator->errors()->add(
                'parent_id',
                "{$parent->code} {$parent->name} is a postable account, not a heading. Only group accounts can have children.",
            );
        }

        if ($parent->type !== $type) {
            $validator->errors()->add(
                'parent_id',
                "{$parent->code} {$parent->name} is ".AccountingAccountMapping::typeInWords((string) $parent->type).', so '
                .AccountingAccountMapping::typeInWords($type).' cannot sit under it.',
            );
        }

        if ($existing !== null && $this->isDescendantOf($parent, $existing->id)) {
            $validator->errors()->add(
                'parent_id',
                "{$parent->code} {$parent->name} sits below this account, so making it the parent would create a loop in the chart.",
            );
        }
    }

    /** Whether `$candidate` is somewhere below the account with `$ancestorId`. */
    private function isDescendantOf(AccountingAccount $candidate, int $ancestorId): bool
    {
        $seen = [];
        $node = $candidate;

        while ($node->parent_id !== null) {
            if ($node->parent_id === $ancestorId) {
                return true;
            }

            // Defensive: a loop already in the data must not hang the request.
            if (isset($seen[$node->parent_id])) {
                return false;
            }

            $seen[$node->parent_id] = true;
            $node = AccountingAccount::query()->find($node->parent_id);

            if ($node === null) {
                return false;
            }
        }

        return false;
    }

    /**
     * `cash_kind` is what puts an account on the Cash & Bank screen, where its
     * balance is read as money the organisation actually holds. A heading's
     * balance is the sum of its subtree, so listing one there would count the
     * same pesos twice in a single figure.
     */
    private function assertGroupsHoldNoMoney(Validator $validator, bool $isGroup, mixed $cashKind): void
    {
        if ($isGroup && $cashKind !== null && $cashKind !== '') {
            $validator->errors()->add(
                'cash_kind',
                'A group heading cannot be a money account. Its balance is the total of the accounts beneath it, not a balance of its own.',
            );
        }
    }

    /**
     * Only an asset can be a money account.
     *
     * `cash_kind` is what AccountingDashboardBuilder::sumByCashKind() adds into
     * the cash figures, so marking a liability, income or expense account as
     * one reports its balance as money on hand. The role-shape check catches
     * this for MAPPED accounts; this covers the rest, and covers create as well
     * as update, where no mapping exists yet to check against.
     */
    private function assertOnlyAssetsHoldMoney(Validator $validator, string $type, mixed $cashKind): void
    {
        if ($type !== 'asset' && $cashKind !== null && $cashKind !== '') {
            $validator->errors()->add(
                'cash_kind',
                ucfirst(AccountingAccountMapping::typeInWords($type)).' cannot be a money account. A money account\'s '
                .'balance goes into the Cash & Bank screen and the dashboard cash figures, which would report this '
                .'balance as money on hand.',
            );
        }
    }

    /**
     * A heading with children cannot stop being a heading: it would become
     * postable while still displaying the sum of everything below it, so its
     * own entries and its children's would be added together.
     */
    private function assertHeadingsKeepTheirChildren(
        Validator $validator,
        AccountingAccount $existing,
        bool $isGroup,
    ): void {
        if (! $isGroup && $existing->children()->exists()) {
            $validator->errors()->add(
                'is_group',
                "{$existing->code} {$existing->name} has accounts beneath it and must stay a group heading. Move or remove its children first.",
            );
        }
    }

    /**
     * An account a posting role resolves to has to stay postable.
     *
     * Turning it into a heading, or deactivating it, breaks the next automatic
     * entry that resolves through it — a loan release or a collection, at the
     * moment it happens, which is the worst possible time to discover it.
     * Re-point the role first.
     */
    private function assertMappedAccountStaysPostable(
        Validator $validator,
        AccountingAccount $existing,
        bool $isGroup,
        bool $isActive,
    ): void {
        if ($isGroup === $existing->is_group && $isActive === $existing->is_active) {
            return;
        }

        if ($isGroup || ! $isActive) {
            $roles = $existing->mappings()->pluck('role')->all();

            if ($roles !== []) {
                $field = $isGroup ? 'is_group' : 'is_active';
                $list = AccountingAccountMapping::labelList($roles);

                $validator->errors()->add(
                    $field,
                    "{$existing->code} {$existing->name} is the account for {$list}. Point those roles somewhere else before making it unpostable.",
                );
            }
        }
    }

    /**
     * An account that has been posted to cannot change `type` or `is_contra`.
     *
     * Both derive `normal_balance`, and `normal_balance` is applied to
     * HISTORICAL lines every time a balance is computed — the trial balance,
     * the general ledger and the dashboard all read the account as it is NOW to
     * interpret movements recorded years ago. So one PUT retroactively re-signs
     * every balance this account has ever carried: a ₱2,000,000 debit-normal
     * receivable becomes a ₱2,000,000 credit, the balance sheet moves ₱4M in a
     * single step, and the ledger rows themselves are untouched and look
     * perfectly ordinary. Nothing in the audit trail says a figure changed,
     * because no figure was stored — only its interpretation moved.
     *
     * `is_group` and `is_active` are already guarded for mapped accounts by
     * {@see self::assertMappedAccountStaysPostable()}; this is the same
     * treatment for the two fields that rewrite history rather than merely
     * breaking the next posting, and it applies to EVERY account with lines,
     * mapped or not.
     *
     * The remedy is the ordinary one: deactivate the account, create a
     * correctly typed one, and move the balance across with a journal entry —
     * which leaves both halves on the record, as a correction should.
     */
    /**
     * An account a posting role resolves to has to keep the SHAPE that role
     * means.
     *
     * The inverse of the check on the settings screen, and it has to exist
     * separately because the same wrong outcome is reachable from either end.
     * `UpdateAccountMappingRequest` stops a role being pointed at an account of
     * the wrong shape; this stops the account UNDER a role being re-shaped into
     * the wrong thing. Closing one door and leaving the other is not a defence —
     * both need permissions held by the same principals, so it is only a longer
     * walk to the same number.
     *
     * Three moves this closes, none of which fail anywhere:
     *
     * - Clearing `cash_kind` on 1010. `AccountingDashboardBuilder` filters the
     *   cash figures on `cash_kind`, so collections keep posting correctly and
     *   silently stop appearing in money-on-hand.
     * - Dropping `is_contra` on 1200. The saving hook re-derives
     *   `normal_balance` to debit, and Net Loans Receivable becomes gross PLUS
     *   the provision instead of minus.
     * - Re-typing a mapped account. The role then resolves to an account on the
     *   wrong statement.
     *
     * UNCONDITIONAL — deliberately not gated on {@see AccountingAccount::hasTransactions()}.
     * On a freshly seeded chart nothing has been posted yet, so a history gate
     * would leave the whole window before the first entry wide open, which is
     * exactly when an organisation is configuring its books. The damage here is
     * to FUTURE postings, not past ones; that is the opposite precondition from
     * {@see self::assertHistoryKeepsItsSign()}, and the reason these are two
     * checks rather than one.
     *
     * Reported against the account attribute at fault, with the same remedy
     * {@see self::assertMappedAccountStaysPostable()} gives: re-point the role
     * first.
     */
    private function assertMappedRolesStillFit(
        Validator $validator,
        AccountingAccount $existing,
        string $type,
        bool $isContra,
        mixed $cashKind,
    ): void {
        $roles = $existing->mappings()->pluck('role')->all();

        if ($roles === []) {
            return;
        }

        $kind = ($cashKind === null || $cashKind === '') ? null : (string) $cashKind;
        $label = "{$existing->code} {$existing->name}";

        foreach ($roles as $role) {
            $mismatch = AccountingAccountMapping::roleMismatch($role, $label, $type, $isContra, $kind);

            if ($mismatch === null) {
                continue;
            }

            $validator->errors()->add(
                $mismatch['field'] === 'role' ? 'type' : $mismatch['field'],
                "{$label} is the account for ".AccountingAccountMapping::label($role).". {$mismatch['message']} "
                .'Point that role somewhere else before changing this account.',
            );
        }
    }

    /**
     * Roles whose reported figure is defined by WHERE the mapped account sits,
     * not just by the account itself.
     *
     * `AccountingDashboardBuilder::receivableAccountIds()` reports gross loans
     * receivable as every postable sibling under the parent of the account
     * `loans_receivable` points at. That fan-out is deliberate — moving a loan
     * from current to past due is a journal between 1110 and 1120, and counting
     * only the mapped account would make the portfolio shrink every time a
     * borrower fell behind — but it means the figure is defined by the PARENT.
     *
     * Add a role here if a report ever starts reading its account's neighbours.
     *
     * @var list<string>
     */
    private const ROLES_ANCHORED_TO_THEIR_PARENT = [
        'loans_receivable',
    ];

    /**
     * An account whose role reads its neighbours cannot be moved to a new set
     * of neighbours.
     *
     * The shape guards above ask whether the account is still the right KIND of
     * thing for its role. This asks the question they do not: whether it is
     * still in the right PLACE. Nothing else covered `parent_id` —
     * {@see self::assertParentIsAGroupOfTheSameType()} only requires the new
     * parent to be a group of the same type, which "1000 Assets" satisfies.
     *
     * So re-parenting 1110 from "1100 Loans Receivable" to "1000 Assets" was
     * accepted, and Cash on Hand, GCash, Maya, Bank Accounts and Prepaid
     * Expenses were all folded into the loan portfolio on the dashboard's
     * headline card. Nothing failed and nothing looked wrong; the number was
     * simply a different number, on the card an operator glances at first.
     *
     * Refused rather than silently re-derived, with the same remedy the other
     * mapped-account guards give: re-point the role first. Reported against
     * `parent_id`, the field actually at fault.
     *
     * Fires ONLY on an actual move, and that is load-bearing. The same review
     * that raised this gap also noted (as informational, not a defect) that
     * these guards run against the WHOLE post-save shape on every update, so a
     * blanket new constraint would make an already-mapped account that no
     * longer fits completely uneditable — including its `name` and
     * `description` — until the role is repointed. Comparing the current parent
     * against the wanted one keeps renames and every other in-place edit
     * working; {@see AccountingReparentMappedAccountTest} pins
     * that.
     */
    private function assertMappedRolesKeepTheirPlace(
        Validator $validator,
        AccountingAccount $existing,
        mixed $parentId,
    ): void {
        $current = $existing->parent_id === null ? null : (int) $existing->parent_id;
        $wanted = ($parentId === null || $parentId === '') ? null : (int) $parentId;

        if ($current === $wanted) {
            return;
        }

        $anchored = $existing->mappings()
            ->pluck('role')
            ->intersect(self::ROLES_ANCHORED_TO_THEIR_PARENT);

        if ($anchored->isEmpty()) {
            return;
        }

        $label = "{$existing->code} {$existing->name}";

        foreach ($anchored as $role) {
            $validator->errors()->add(
                'parent_id',
                "{$label} is the account for ".AccountingAccountMapping::label($role).', and that figure is reported across '
                .'every account under its heading. Moving it to another heading would change '
                .'which accounts are counted. Point that role somewhere else before moving this account.',
            );
        }
    }

    private function assertHistoryKeepsItsSign(
        Validator $validator,
        AccountingAccount $existing,
        string $type,
        bool $isContra,
    ): void {
        $typeChanged = $type !== $existing->type;
        $contraChanged = $isContra !== (bool) $existing->is_contra;

        if (! $typeChanged && ! $contraChanged) {
            return;
        }

        if (! $existing->hasTransactions()) {
            return;
        }

        $field = $typeChanged ? 'type' : 'is_contra';
        $what = $typeChanged
            ? 're-classify it from '.AccountingAccountMapping::typeInWords((string) $existing->type).' to '.AccountingAccountMapping::typeInWords($type)
            : ($isContra ? 'make it a contra account' : 'stop it being a contra account');

        $validator->errors()->add(
            $field,
            "{$existing->code} {$existing->name} already has journal entries against it, so you cannot {$what}. "
            .'Both fields decide which way the account grows, and that is applied to every entry it has EVER '
            .'carried — changing one now would silently re-sign all of its history. Deactivate this account and '
            .'move the balance to a correctly classified one with a journal entry instead.',
        );
    }
}

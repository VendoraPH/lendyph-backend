<?php

namespace App\Services;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\Money;
use App\Services\Accounting\PostingRules;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The share capital a loan release withholds, credited to the member's share
 * capital ledger.
 *
 * ## Why this exists
 *
 * A "Share Capital" deduction is money the cooperative keeps out of the loan
 * and adds to the member's capital. The release journal has booked it to the
 * Share Capital equity account since the deductions were booked by type
 * ({@see PostingRules::DEDUCTION_ROLES}), but nothing told the member's own
 * ledger: the books said the member's equity grew, while their ledger, their
 * statement and the Share Capital report said it had not. This writes the
 * missing row, in the release transaction, so the two can only move together.
 *
 * ## The amount is the journal's, by construction
 *
 * Which items are share capital, and what each is worth in centavos, is
 * answered by {@see AutomaticPoster::releaseDeductions()}: the same
 * normalisation, role lookup and per-item conversion the release journal is
 * built from. There is no second list of names here, so a "SHARE-CAPITAL."
 * item is share capital in the ledger exactly when it is in the books, and the
 * credit equals the journal's Share Capital line to the centavo. Every share
 * capital item of the FINAL deductions is summed into ONE row: the configured
 * fees and the insurance premium have been added by then, and neither is
 * share capital, so neither reaches the figure.
 *
 * ## One row per release, and only for new releases
 *
 * The row carries the loan (`loan_id`, unique), so a release writes at most
 * one and the credit can always be traced to it. Past releases are never
 * touched: `share-capital:release-deductions-report` lists them, read only.
 *
 * ## Members only
 *
 * A borrower in {@see Borrower::NON_MEMBER_STATUSES} cannot hold share
 * capital, the rule the payment path and the standalone ledger endpoint both
 * keep. Crediting equity for one would put capital on the books for someone
 * who is not a member, so such a release is refused, on `deductions`, and the
 * transaction rolls back whole.
 */
final class ShareCapitalReleaseCredit
{
    public function __construct(private readonly AutomaticPoster $poster) {}

    /**
     * Credit the member with the share capital `$loan`'s release withheld, or
     * write nothing when it withheld none.
     *
     * Must run inside the release transaction, after the deductions are final
     * and the loan account number and `released_at` are set, so a failure
     * anywhere in the release takes this row with it.
     *
     * @throws ValidationException on `deductions`, when the borrower is not a member
     * @throws CannotPostToTheBooksException when this organisation keeps books and a share capital item has no bookable amount
     */
    public function record(Loan $loan, User $releaser): ?ShareCapitalLedger
    {
        $centavos = $this->amountFor($loan);

        if ($centavos === 0) {
            return null;
        }

        $status = Borrower::query()->whereKey($loan->borrower_id)->value('status');

        if (in_array($status, Borrower::NON_MEMBER_STATUSES, true)) {
            throw ValidationException::withMessages([
                'deductions' => [
                    'This loan withholds '.Money::format($centavos).' of share capital, but the borrower is not a '
                    .'member yet, and only members can hold share capital. Remove the Share Capital deduction or '
                    .'approve the membership before releasing.',
                ],
            ]);
        }

        $number = $loan->loan_account_number ?? $loan->application_number;

        return ShareCapitalLedger::create([
            'borrower_id' => $loan->borrower_id,
            'loan_id' => $loan->getKey(),
            // Its own reference, not one ShareCapitalLedger::booted() numbers.
            // That hook builds the next `SC-YYYYMMDD-n` from a plain read, and
            // a release's snapshot is fixed before it waits on the loan account
            // number's lock, so two releases on one day, or a release and any
            // other ledger writer, could build the same reference and the
            // release would fail on its unique index. The loan account number
            // is unique, and so is this row per loan (`loan_id`), so
            // "SC-LN-000123" (23 characters at most, in a 30-character column)
            // cannot collide, and it cannot match the hook's dated pattern.
            'reference' => "SC-{$number}",
            'date' => $this->poster->releaseDate($loan),
            'description' => "Share capital deducted at release of {$number}",
            'debit' => 0,
            'credit' => sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100),
            'created_by' => $releaser->getKey(),
        ]);
    }

    /**
     * The share capital `$loan`'s deductions withhold, in centavos: what the
     * release journal credits the share capital role.
     *
     * A loan with no share capital item answers 0 without classifying its
     * deductions at all, so a release that withholds none meets no refusal
     * it did not meet before.
     *
     * An organisation that keeps no books (no chart of accounts, so
     * AutomaticPoster posts nothing) gets no refusal from here either: the
     * journal's consistency guard, which keeps the books' lines agreeing with
     * the loan's total, has no journal to protect there, and a release that
     * succeeded without it must still succeed. Its share capital items are
     * summed by the same per-item conversion and the same role lookup; an
     * item with no usable, positive amount credits nothing.
     *
     * @throws CannotPostToTheBooksException when this organisation keeps books and a share capital item has no bookable amount, exactly as the journal would refuse it
     */
    public function amountFor(Loan $loan): int
    {
        if (! $this->withholdsShareCapital($loan)) {
            return 0;
        }

        if ($this->poster->enabled()) {
            return $this->poster->releaseDeductions($loan)['mapped'][self::role()] ?? 0;
        }

        $centavos = 0;

        foreach ($this->poster->releaseDeductionItems($loan) as $item) {
            if (is_array($item) && PostingRules::deductionRoleFor($item['name']) === self::role()
                && is_int($item['amount']) && $item['amount'] > 0) {
                $centavos += $item['amount'];
            }
        }

        return $centavos;
    }

    /**
     * Whether any of `$loan`'s deduction items is share capital, by the role
     * its name maps to. Only a LIST is a list of items, as the release rule
     * reads it.
     */
    public function withholdsShareCapital(Loan $loan): bool
    {
        $items = $loan->deductions;

        if (! is_array($items) || ! array_is_list($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (is_array($item) && is_string($item['name'] ?? null) && PostingRules::deductionRoleFor($item['name']) === self::role()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The posting role a "Share Capital" deduction is credited to, read from
     * {@see PostingRules::DEDUCTION_ROLES} rather than restated here.
     */
    private static function role(): string
    {
        return PostingRules::deductionRoleFor('Share Capital')
            ?? throw new LogicException('PostingRules no longer maps a "Share Capital" deduction to a posting role.');
    }
}

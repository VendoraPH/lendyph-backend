<?php

namespace App\Services;

use App\Http\Requests\Fee\Concerns\GuardsAgainstProductFeeOverlap;
use App\Http\Requests\LoanProduct\Concerns\GuardsAgainstFeeCatalogOverlap;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\Accounting\AutomaticPoster;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * The configured fee rules in Settings, applied to a loan at release.
 *
 * ## Why this exists
 *
 * `Fee` was an orphan. FeeController wrote rows to `fees` and nothing in the
 * application ever read them, so an administrator could configure a 2% product
 * fee, see it saved, and watch every release ignore it. This is the reader.
 *
 * ## A SECOND fee mechanism already exists, and this one does not replace it
 *
 * {@see LoanService::createLoan()} derives deductions from the LOAN PRODUCT's
 * own `processing_fee` / `service_fee` / `notarial_fee` columns at application
 * time. That is a different mechanism with a different owner (the product, not
 * the fee schedule) and a different moment (application, not release), and the
 * handoff that asked for this work does not mention it at all.
 *
 * Fees from here are APPENDED to whatever those columns already put on the
 * loan. Replacing them would silently drop charges the borrower already signed
 * a disclosure for. Note the consequence: a `fees` row named "Processing Fee"
 * and a product carrying `processing_fee` will BOTH charge, because they are
 * two independently configured charges that happen to share a label. Matching
 * them by name to suppress one is the wrong fix — see the idempotency section.
 *
 * ## Where this runs in the release transaction, and why it matters
 *
 * Immediately after the loan is stamped released and BEFORE
 * {@see LoanService::applyInsuranceOnRelease()}, which is itself before
 * {@see AutomaticPoster::loanRelease()}.
 *
 * Before the poster is the load-bearing half: the poster credits cash with
 * `net_proceeds` and asserts `gross === net + deductions`. Apply fees after it
 * and the journal credits cash with money that never left the drawer — and it
 * still BALANCES, so no report would ever surface the difference.
 *
 * Before insurance is the deliberate half. Fee and insurance amounts are
 * independent, so the order changes neither total. What it changes is which
 * guard fires first when the deductions overrun the principal, and therefore
 * what the operator is told. A fee-configuration problem should report as a
 * fee-configuration problem, not as "insurance collected exceeds the loan net
 * proceeds" — which names the one number the cashier just typed in and is
 * innocent.
 *
 * ## Shape: append and adjust, never recompute
 *
 * This mirrors `applyInsuranceOnRelease()` exactly — it appends items and does
 * incremental arithmetic on `total_deductions` / `net_proceeds` in a single
 * `$loan->update()`. It deliberately does NOT call
 * {@see LoanService::computeDeductions()}: that rebuilds the whole itemised
 * list from scratch, and two writers rebuilding the same three fields from
 * different inputs would fight over them.
 */
final class LoanReleaseFeeService
{
    /**
     * The extra key a fee-sourced deduction item carries, on top of the
     * `{name, amount, type, original_value}` contract the frontend reads.
     *
     * The frontend ignores keys it does not know, so adding this does not break
     * the existing shape.
     */
    public const ITEM_FEE_KEY = 'fee_id';

    /**
     * Half a centavo — the tolerance for `loan_amount_eq`.
     *
     * `principal_amount` is `decimal:2` and arrives off the model as a STRING,
     * so the comparison runs through a float. A configured threshold of
     * `10000.10` and a stored `"10000.10"` are the same money and need not be
     * the same IEEE-754 double. Anything closer than half a centavo is the same
     * peso figure by definition.
     */
    private const PESO_EPSILON = 0.005;

    /**
     * The fee rules that apply to this loan, oldest first.
     *
     * ## Read whole, filtered in PHP — on purpose
     *
     * `fees` is a settings table: one row per configured RULE, not per loan.
     * A cooperative has a handful. Reading it whole costs one scan of a table
     * that will never grow with the portfolio, and release is a human action
     * measured in a few per day, not a batch path.
     *
     * The alternative is worse. `applicable_product_ids` is a JSON column whose
     * "all products" case is two different stored values (see
     * {@see self::appliesToProduct()}), and the six condition keys compare
     * against a term-day figure that is DERIVED from two date columns rather
     * than stored. Expressing half of that as `JSON_CONTAINS(...) OR ... IS
     * NULL OR JSON_LENGTH(...) = 0` and leaving the other half in PHP would
     * split one rule across two languages for no measurable gain, and the
     * preview and the release would then have two places to drift apart in.
     *
     * Ordered by `id` so the fingerprint and the itemised list are stable —
     * `name` is mutable and would reorder the deduction lines on a rename.
     */
    public function applicableFees(Loan $loan): EloquentCollection
    {
        $productId = (int) $loan->loan_product_id;

        return Fee::query()
            ->orderBy('id')
            ->get()
            ->filter(fn (Fee $fee): bool => $this->appliesToProduct($fee, $productId)
                && $this->conditionsHold($fee, $loan))
            ->values();
    }

    /**
     * What release WOULD do to this loan's deductions, without doing it.
     *
     * Runs the identical calculation the release takes, including the guard
     * that refuses deductions larger than the principal — so a configuration
     * that cannot be released fails HERE, in front of the cashier, with the
     * same message and before any money is counted out.
     *
     * Insurance is deliberately absent. It is not configuration; it is typed
     * into the release dialog at the moment of release, so there is nothing to
     * preview.
     *
     * ## The two totals are 2dp STRINGS, not floats
     *
     * Deliberate, and the reason is that this response is read side by side
     * with `LoanResource`. `total_deductions` and `net_proceeds` are
     * `decimal:2` casts there, so the release the cashier confirms answers
     * `"9300.00"`. A preview answering `9300` for the same money is the same
     * number in a different type: it renders as "₱9300" next to a "₱9,300.00",
     * and any client that compares the previewed figure with the released one
     * has to know which of the two it is holding. Same calculation, same shape.
     *
     * The per-item `amount` values are left as they are — those live inside a
     * JSON column on the loan and come back as numbers from BOTH endpoints
     * already, so there is nothing to reconcile.
     *
     * ## `overlap_warnings` is advisory only
     *
     * Purely additive, and preview-only — never surfaced from
     * {@see self::applyOnRelease()}, so it changes nothing about what a
     * release charges or how much; see {@see self::overlapWarnings()}.
     *
     * @return array{
     *     deductions: list<array<string, mixed>>,
     *     total_deductions: string,
     *     net_proceeds: string,
     *     fee_fingerprint: string,
     *     overlap_warnings: list<array{fee_id: int, fee_name: string, message: string}>,
     * }
     */
    public function preview(Loan $loan): array
    {
        $fees = $this->applicableFees($loan);
        $items = $this->unchargedItems($loan, $fees);
        $charged = $this->sumOf($items);

        $total = round((float) $loan->total_deductions + $charged, 2);
        $net = round((float) $loan->net_proceeds - $charged, 2);

        $this->assertWithinPrincipal($net, $charged, $total, $loan);

        return [
            'deductions' => array_merge($loan->deductions ?? [], $items),
            'total_deductions' => number_format($total, 2, '.', ''),
            'net_proceeds' => number_format($net, 2, '.', ''),
            'fee_fingerprint' => $this->fingerprint($fees),
            'overlap_warnings' => $this->overlapWarnings($loan, $fees),
        ];
    }

    /**
     * Advisory-only: which of `$fees` would double-charge the SAME
     * conceptual fee this loan's `LoanProduct` columns already collect.
     *
     * ## Why this exists alongside the write-time guards
     *
     * {@see GuardsAgainstProductFeeOverlap}
     * and {@see GuardsAgainstFeeCatalogOverlap}
     * stop a NEW colliding configuration from being saved. Neither
     * retroactively re-checks a `Fee` row that predates the guard, or a
     * product column raised before the guard shipped. This is the backstop
     * for that gap: surfaced on the preview a cashier already reads before
     * releasing, so an already-saved collision is still visible at the one
     * moment someone can still do something about it.
     *
     * Deliberately NOT called from {@see self::applyOnRelease()} — it
     * reports on the configuration, not on the money, and
     * {@see LoanReleaseFeeService} charges both mechanisms by design (see
     * the class docblock). This only tells the cashier about it first.
     *
     * @param  EloquentCollection<int, Fee>  $fees  this loan's applicable fees, as returned by {@see self::applicableFees()}
     * @return list<array{fee_id: int, fee_name: string, message: string}>
     */
    private function overlapWarnings(Loan $loan, EloquentCollection $fees): array
    {
        $product = LoanProduct::find($loan->loan_product_id);

        if (! $product) {
            return [];
        }

        $detector = app(FeeOverlapDetector::class);
        $warnings = [];

        foreach ($fees as $fee) {
            $collides = $detector->collidingProducts($fee->name, $fee->applicable_product_ids)
                ->contains('id', $product->id);

            if (! $collides) {
                continue;
            }

            $warnings[] = [
                'fee_id' => $fee->id,
                'fee_name' => $fee->name,
                'message' => "'{$fee->name}' duplicates this product's own fee and will be charged in addition to it.",
            ];
        }

        return $warnings;
    }

    /**
     * Charge the applicable fees, in one write.
     *
     * @param  string|null  $expectedFingerprint  the `fee_fingerprint` a preview
     *                                            handed out, if the caller took one. Optional: the frontend that
     *                                            prompted this work releases without previewing, and refusing those
     *                                            releases would break the screen this unblocks.
     *
     * @throws HttpResponseException 409, when the fee configuration moved since the preview
     * @throws ValidationException 422, when the fees would overrun the principal
     */
    public function applyOnRelease(Loan $loan, ?string $expectedFingerprint = null): void
    {
        $fees = $this->applicableFees($loan);

        $this->assertConfigurationIsUnchanged($fees, $expectedFingerprint);

        $items = $this->unchargedItems($loan, $fees);

        if ($items === []) {
            return;
        }

        $charged = $this->sumOf($items);

        // Incremental, exactly as applyInsuranceOnRelease() does it: add to the
        // running total, subtract from the running net. NOT `principal - total`
        // — that recomputes a field another writer in this same transaction
        // also owns, and the two would disagree the moment either one's inputs
        // changed.
        $total = round((float) $loan->total_deductions + $charged, 2);
        $net = round((float) $loan->net_proceeds - $charged, 2);

        $this->assertWithinPrincipal($net, $charged, $total, $loan);

        // ONE update, like the insurance block. Three fields that must move
        // together; two writes would leave a window where the loan's own
        // invariant (`principal === total_deductions + net_proceeds`) is false,
        // and that invariant is asserted by the accounting poster later in this
        // very transaction.
        $loan->update([
            'deductions' => array_merge($loan->deductions ?? [], $items),
            'total_deductions' => $total,
            'net_proceeds' => $net,
        ]);

        AuditLogService::log(
            action: 'release_fees',
            auditable: $loan,
            newValues: [
                'fees' => $items,
                'charged_at_release' => $charged,
                'total_deductions' => $total,
                'net_proceeds' => $net,
                'fee_fingerprint' => $this->fingerprint($fees),
            ],
            description: 'Configured fees applied on release (₱'.number_format($charged, 2).' across '
                .count($items).' '.(count($items) === 1 ? 'fee' : 'fees').')',
        );
    }

    /**
     * A fingerprint of the fee configuration this loan was quoted from.
     *
     * ## Why a fingerprint rather than comparing totals
     *
     * The requirement is that a cashier cannot confirm one amount and disburse
     * another after someone edits the fee schedule mid-flow. Comparing the
     * previewed TOTAL against the computed total would catch that, but it
     * cannot tell a changed fee apart from a rounding disagreement between two
     * clients, and it answers "these numbers differ" when the operator needs
     * "the fee schedule changed — look again". A hash says which it was.
     *
     * ## What goes into it, and what deliberately does not
     *
     * `id`, `name`, `type` and `value` of each APPLICABLE fee, in id order.
     * Those are precisely the fields that decide what this loan is charged and
     * what the cashier read on the screen.
     *
     * `conditions` and `applicable_product_ids` are absent by design. Neither
     * changes an amount; both only decide WHICH fees are applicable — and a
     * change to either that affects this loan already changes the set of rows
     * hashed here, while a change that does not affect this loan must not block
     * a release it cannot touch.
     *
     * The principal is absent for the same reason: an `approved` loan is not
     * editable, so it cannot move between preview and release.
     *
     * The empty set hashes to a stable, non-empty value — so previewing zero
     * fees and then releasing after someone adds one is still a mismatch.
     *
     * `value` is stringified rather than cast to float: the `decimal:4` cast
     * already hands back a canonical `"2.0000"`, and float formatting is a
     * worse canonical form than the one the database already agreed on.
     *
     * @param  iterable<Fee>  $fees
     */
    public function fingerprint(iterable $fees): string
    {
        $rows = [];

        foreach ($fees as $fee) {
            $rows[] = [
                (int) $fee->id,
                (string) $fee->name,
                (string) $fee->type,
                (string) $fee->value,
            ];
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /**
     * NULL and `[]` both mean "every product".
     *
     * The handoff asserts this and no code has ever implemented it, but the API
     * has already committed to it either way: `FeeResource` serialises
     * `$this->applicable_product_ids ?? []`, so a NULL row and an empty-array
     * row are indistinguishable on the wire. Treating them differently here
     * would make the same visible configuration behave two ways depending on a
     * stored value the administrator cannot see and the API will not show them.
     */
    private function appliesToProduct(Fee $fee, int $productId): bool
    {
        $ids = $fee->applicable_product_ids;

        if ($ids === null || $ids === []) {
            return true;
        }

        return in_array($productId, array_map('intval', $ids), true);
    }

    /**
     * Every POPULATED condition must hold. Absent and null keys are ignored.
     *
     * ## Contradictory conditions
     *
     * The six keys are validated independently and nothing cross-checks them,
     * so `term_days_gt: 90` together with `term_days_lt: 30` is storable and
     * can never be satisfied. It is evaluated literally, as the conjunction it
     * is: the fee simply never applies. No special case, no error at release.
     *
     * The reasons, in order of weight. A release is the wrong moment to
     * discover a settings mistake — the borrower is at the counter and the
     * alternative to "this fee did not apply" is "this loan cannot be
     * released". Inferring an intent from a contradiction means guessing which
     * of the two numbers the administrator meant, and either guess CHARGES
     * SOMEONE MONEY on a rule nobody wrote. And an unsatisfiable rule is
     * indistinguishable, at this point in the code, from a deliberately dormant
     * one: `fees` has no `is_active` column, so a rule that must not fire today
     * has nowhere else to go.
     *
     * Where it should surface is at write time, in the Fees screen, as a
     * validation error against the pair — not here. That is a change to the fee
     * CRUD contract and is called out in this PR rather than smuggled into it.
     *
     * ## `gt` / `lt` are strict, `eq` is not exact
     *
     * Strict is what the handoff specifies. `loan_amount_eq` compares within
     * half a centavo because both sides are decimal money that reaches PHP as
     * a string; see {@see self::PESO_EPSILON}.
     */
    private function conditionsHold(Fee $fee, Loan $loan): bool
    {
        $conditions = $fee->conditions;

        if (! is_array($conditions) || $conditions === []) {
            return true;
        }

        $termDays = $this->termDays($loan);
        $principal = (float) $loan->principal_amount;

        $checks = [
            'term_days_gt' => fn (int|float|string $v): bool => $termDays !== null && $termDays > (int) $v,
            'term_days_lt' => fn (int|float|string $v): bool => $termDays !== null && $termDays < (int) $v,
            'term_days_eq' => fn (int|float|string $v): bool => $termDays !== null && $termDays === (int) $v,
            'loan_amount_gt' => fn (int|float|string $v): bool => $principal > (float) $v,
            'loan_amount_lt' => fn (int|float|string $v): bool => $principal < (float) $v,
            'loan_amount_eq' => fn (int|float|string $v): bool => abs($principal - (float) $v) < self::PESO_EPSILON,
        ];

        foreach ($checks as $key => $holds) {
            $configured = $conditions[$key] ?? null;

            if ($configured === null || $configured === '') {
                continue;
            }

            if (! $holds($configured)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The loan's term in DAYS — the agreed start date to the agreed maturity
     * date, and nothing else.
     *
     * ## Why not `loans.term`
     *
     * Because `term` is not a number of days and is not a number of months
     * either. It is a PERIOD COUNT whose unit follows `frequency`: see
     * {@see LoanService::computeMaturityDate()}, where `term` is fed to
     * `addDays()`, `addWeeks()`, `addMonths()` or a multiple of 14 or 15 days
     * depending on the frequency, and {@see Loan::isOneMonthTerm()}, which
     * spells the same warning out. `term: 30` on a daily loan is thirty days;
     * on a monthly loan it is thirty months. Any `term * k` conversion is
     * therefore wrong for four of the six frequencies, and the two it is right
     * for are the ones where a calendar month is not a fixed number of days
     * anyway.
     *
     * The date pair is the only unambiguous source, and it is the pair the
     * borrower actually signed. Both columns are `date` casts, so both are
     * midnight and the difference is a whole number of days.
     *
     * ## Null dates
     *
     * A loan missing either date cannot have a term-day condition evaluated
     * against it, and such a fee does NOT apply. Failing towards not charging
     * is the recoverable direction: an uncharged fee can be collected later,
     * an over-charged borrower has already been handed the wrong money.
     * (Neither column is nullable for a loan originated here; CSV-imported
     * loans never pass through release at all.)
     */
    private function termDays(Loan $loan): ?int
    {
        if ($loan->start_date === null || $loan->maturity_date === null) {
            return null;
        }

        return (int) $loan->start_date->diffInDays($loan->maturity_date);
    }

    /**
     * Deduction items for the fees not already charged to this loan.
     *
     * ## Idempotency is keyed on `fee_id`
     *
     * Each fee-sourced item carries the id of the rule that produced it, and a
     * fee whose id is already on the loan is skipped. The two obvious
     * alternatives both fail:
     *
     * - BY NAME breaks on a rename — the administrator edits "Processing Fee"
     *   to "Processing Charge" and the loan is charged twice — and it also
     *   collides with the product-column mechanism, which writes items named
     *   "Processing Fee" / "Service Fee" / "Notarial Fee" with no fee rule
     *   behind them at all. Name matching would let a product column silently
     *   suppress a genuinely configured fee.
     * - BY COUNT ("N fees already applied") cannot tell a re-run apart from a
     *   genuinely new rule added between two releases, and blocks the second.
     *
     * The extra key is additive. The frontend reads
     * `{name, amount, type, original_value}` and ignores what it does not
     * recognise, so the existing contract is untouched.
     *
     * ## Zero-amount fees produce no line
     *
     * A ₱0 charge is not a charge, and an empty line on a borrower's disclosure
     * is worse than no line. Mirrors the insurance block, which appends nothing
     * when nothing is collected.
     *
     * @param  iterable<Fee>  $fees
     * @return list<array<string, mixed>>
     */
    private function unchargedItems(Loan $loan, iterable $fees): array
    {
        $alreadyCharged = $this->chargedFeeIds($loan);
        $items = [];

        $principal = (float) $loan->principal_amount;

        foreach ($fees as $fee) {
            $id = (int) $fee->id;

            if (in_array($id, $alreadyCharged, true)) {
                continue;
            }

            // `value` is `decimal:4` and reaches PHP as a STRING — "2.0000",
            // not 2.0. Cast it. The lending columns it lands next to are
            // `decimal:2` strings for the same reason, and this project has
            // already shipped one bug where such a string was concatenated
            // where it was meant to be summed.
            $rate = (float) $fee->value;

            $amount = $fee->type === 'percentage'
                ? round($principal * $rate / 100, 2)
                : round($rate, 2);

            if ($amount <= 0.0) {
                continue;
            }

            $items[] = [
                'name' => $fee->name,
                'amount' => $amount,
                'type' => $fee->type,
                // The RATE for a percentage fee, the peso figure for a fixed
                // one — the same convention computeDeductions() uses, so a
                // later recompute reads a fee item the way it reads its own.
                'original_value' => $rate,
                self::ITEM_FEE_KEY => $id,
            ];
        }

        return $items;
    }

    /**
     * Ids of the fee rules already itemised on this loan.
     *
     * @return list<int>
     */
    private function chargedFeeIds(Loan $loan): array
    {
        $ids = [];

        foreach ($loan->deductions ?? [] as $item) {
            if (is_array($item) && isset($item[self::ITEM_FEE_KEY])) {
                $ids[] = (int) $item[self::ITEM_FEE_KEY];
            }
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function sumOf(array $items): float
    {
        $total = 0.0;

        foreach ($items as $item) {
            $total += (float) $item['amount'];
        }

        return round($total, 2);
    }

    /**
     * Refuse to withhold more than the loan is worth.
     *
     * Named as a FEE problem, which is the point of running before the
     * insurance block: the operator is told which configuration to go and fix,
     * and the message carries the three figures they need to work out which
     * rule is too large. Throwing here rolls the whole release back — the
     * status write, the loan account number and all — which is the intended
     * failure. Releasing a loan whose net proceeds are negative would hand the
     * borrower a bill instead of money.
     */
    private function assertWithinPrincipal(float $net, float $charged, float $total, Loan $loan): void
    {
        if ($net >= 0) {
            return;
        }

        throw ValidationException::withMessages([
            'fees' => [
                'Configured fees of ₱'.number_format($charged, 2).' bring total deductions to ₱'
                .number_format($total, 2).', which exceeds the ₱'
                .number_format((float) $loan->principal_amount, 2)
                .' principal. Review the fee rules in Settings before releasing this loan.',
            ],
        ]);
    }

    /**
     * 409 when the fee schedule moved between the preview and this release.
     *
     * Not a 422: nothing the client SENT is invalid — the request was correct
     * when it was composed and the server's state changed underneath it. That
     * is what 409 is for, and it is the code this codebase already uses for
     * exactly this shape of failure (see CsvImportController's resumed-upload
     * conflicts).
     *
     * Both hashes go in the body. They are derived from configuration an
     * administrator can already read, so there is nothing to withhold, and a
     * support log that carries both can answer "did it really change?" without
     * re-deriving anything.
     *
     * @param  iterable<Fee>  $fees
     */
    private function assertConfigurationIsUnchanged(iterable $fees, ?string $expected): void
    {
        if ($expected === null || $expected === '') {
            return;
        }

        $current = $this->fingerprint($fees);

        if (hash_equals($current, $expected)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'The fee configuration changed after this release was previewed. '
                .'Reload the release preview and confirm the new figures before disbursing.',
            'errors' => [
                'fee_fingerprint' => ['The fee configuration changed after this release was previewed.'],
            ],
            'data' => [
                'expected_fee_fingerprint' => $expected,
                'current_fee_fingerprint' => $current,
            ],
        ], 409));
    }
}

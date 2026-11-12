<?php

namespace App\Services\Savings;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\PortalRequest;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Services\Savings\Api\Refuse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A savings product's own rules (gap round S1, new records only), in one
 * place. Every refusal is 422 in words with ₱ amounts, on `amount` (or the
 * field the caller names), and on `product_id` for eligibility.
 *
 * - **Money in from the member** ({@see self::assertCanDeposit()}): the
 *   product takes deposits, the amount is within its minimum and maximum
 *   deposit, and the balance stays within its maximum balance. A transfer's
 *   target checks only that it takes deposits and the maximum balance
 *   ({@see self::assertCanReceiveTransfer()}). Never applied to interest,
 *   time deposit payouts, reversals or closing: those callers don't ask.
 * - **Money out** ({@see self::decideWithdrawal()}): the product allows
 *   withdrawals, the amount is within its minimum and maximum withdrawal,
 *   and the month's free withdrawals are not used up. Beyond them it decides
 *   the excess-withdrawal fee, once, at request (or refuses when the product
 *   charges none); the payout charges that decision
 *   ({@see self::assertStillDecided()}), never counting again. A
 *   transfer's source checks that the product allows transfers and the
 *   maximum withdrawal ({@see self::assertCanTransferOut()}).
 * - **Eligibility** ({@see self::assertEligible()}): the member's type and
 *   branch are among the product's (null: all).
 *
 * The monthly count ({@see self::withdrawalsInMonth()}) is read under the
 * account's row lock: withdrawals, their approvals and members' request holds
 * are only ever written under it, so the count is exact.
 */
final class ProductRules
{
    /** Portal withdrawal requests still waiting for staff; an approved one counts through its savings approval. */
    private const OPEN_PORTAL_STATUSES = [PortalRequest::STATUS_SUBMITTED, PortalRequest::STATUS_UNDER_REVIEW, PortalRequest::STATUS_NEED_INFO];

    /** @throws ValidationException */
    public function assertCanDeposit(SavingsAccount $account, int $centavos, bool $openingDeposit = false, string $field = 'amount'): void
    {
        $product = $this->productOf($account);

        if (! $product->allow_deposits) {
            Refuse::field($field, "{$product->name} takes no deposits.");
        }

        $min = $this->limit($product->min_deposit);
        $max = $this->limit($product->max_deposit);

        // The opening deposit has its own minimum (the minimum opening balance).
        if (! $openingDeposit && $min !== null && $centavos < $min) {
            Refuse::field($field, "A deposit to {$product->name} is at least ".Refuse::peso($min).'.');
        }

        if ($max !== null && $centavos > $max) {
            Refuse::field($field, "A deposit to {$product->name} is at most ".Refuse::peso($max).'.');
        }

        $this->assertWithinMaxBalance($account, $product, $centavos, $field);
    }

    /** @throws ValidationException */
    public function assertCanReceiveTransfer(SavingsAccount $to, int $centavos, string $field = 'amount'): void
    {
        $product = $this->productOf($to);

        if (! $product->allow_deposits) {
            Refuse::field($field, "{$to->account_number} is a {$product->name} account, which takes no deposits, so it can't receive a transfer.");
        }

        $this->assertWithinMaxBalance($to, $product, $centavos, $field);
    }

    /** @throws ValidationException */
    public function assertCanTransferOut(SavingsAccount $from, int $centavos, string $field = 'amount'): void
    {
        $product = $this->productOf($from);

        if (! $product->allow_transfers) {
            Refuse::field($field, "{$product->name} doesn't allow transfers to other accounts.");
        }

        $max = $this->limit($product->max_withdrawal);

        if ($max !== null && $centavos > $max) {
            Refuse::field($field, "A transfer out of {$product->name} is at most ".Refuse::peso($max).', its maximum withdrawal.');
        }
    }

    /**
     * Check a withdrawal of `$centavos` requested now and decide its
     * excess-withdrawal fee, once: it counts in the Manila month of
     * `$countedOn` (the request's date), after everything already counted
     * there. The decision is stored on an approval or a portal request and
     * charged as it is when the withdrawal posts.
     *
     * @throws ValidationException
     */
    public function decideWithdrawal(SavingsAccount $account, int $centavos, string $countedOn, string $field = 'amount'): WithdrawalFee
    {
        $product = $this->productOf($account);
        $this->assertWithdrawalLimits($product, $centavos, $field);

        return new WithdrawalFee(
            $this->withdrawalFee($account, $product, $countedOn, $field),
            $countedOn,
            now()->format('Y-m-d H:i:s'),
            $product->free_withdrawals_per_month,
            $this->limit($product->excess_withdrawal_fee),
        );
    }

    /**
     * Check, when a withdrawal decided earlier posts, that the product still
     * allows it (switch and limits) and that the rule its fee was decided
     * on still stands: a changed free count answers 409 in words, and so
     * does a changed fee when this withdrawal carries one (one decided within
     * the free withdrawals pays none, whatever the fee). The fee itself is
     * {@see self::payoutFee()}'s.
     *
     * @throws ValidationException
     */
    public function assertStillDecided(SavingsAccount $account, int $centavos, WithdrawalFee $decided, string $field = 'amount'): void
    {
        $product = $this->productOf($account);
        $this->assertWithdrawalLimits($product, $centavos, $field);

        $freeChanged = $product->free_withdrawals_per_month !== $decided->freeWithdrawals;
        $feeChanged = $decided->fee > 0 && $this->limit($product->excess_withdrawal_fee) !== $decided->excessFee;

        if ($freeChanged || $feeChanged) {
            Refuse::conflict("{$product->name}'s free withdrawals or excess-withdrawal fee changed since this request was made (it reserved a "
                .Refuse::peso($decided->fee).' fee). Reject it and make a new request.');
        }
    }

    /**
     * The fee a withdrawal decided earlier is charged when it posts: the
     * stored fee, or nothing when the withdrawals counted before it (by
     * request time, then id, read under the account lock) no longer use up
     * the free count, because one of them was rejected, cancelled, expired
     * or reversed since. Never more than the stored fee, so a withdrawal
     * made after this one never raises it.
     */
    public function payoutFee(SavingsAccount $account, WithdrawalFee $decided): int
    {
        if ($decided->fee === 0 || $decided->freeWithdrawals === null) {
            return 0;
        }

        return $this->withdrawalsInMonth($account, $decided->countedOn, $decided) < $decided->freeWithdrawals ? 0 : $decided->fee;
    }

    /**
     * What the next withdrawal requested on `$date` would carry, for a
     * member to see before asking: `fee` in centavos (null when the product
     * charges none and the free withdrawals are used up, so it would be
     * refused) and `free_left` (null when unlimited).
     *
     * @return array{fee: int|null, free_left: int|null}
     */
    public function nextWithdrawal(SavingsAccount $account, string $date): array
    {
        $product = $this->productOf($account);
        $free = $product->free_withdrawals_per_month;

        if ($free === null) {
            return ['fee' => 0, 'free_left' => null];
        }

        $left = max(0, $free - $this->withdrawalsInMonth($account, $date));

        return ['fee' => $left > 0 ? 0 : $this->limit($product->excess_withdrawal_fee), 'free_left' => $left];
    }

    /**
     * The fee for a withdrawal counted on `$date`: 0 within the product's
     * free withdrawals for that month (or with no free count), its fee
     * beyond them, and a refusal beyond them when it charges none.
     *
     * @throws ValidationException
     */
    private function withdrawalFee(SavingsAccount $account, SavingsProduct $product, string $date, string $field): int
    {
        $free = $product->free_withdrawals_per_month;

        if ($free === null || $this->withdrawalsInMonth($account, $date) < $free) {
            return 0;
        }

        $fee = $this->limit($product->excess_withdrawal_fee);

        if ($fee === null) {
            $month = CarbonImmutable::parse($date)->format('F Y');

            Refuse::field($field, "{$product->name} allows {$free} free ".($free === 1 ? 'withdrawal' : 'withdrawals')
                ." a month, and {$account->account_number} has used ".($free === 1 ? 'it' : 'them')." for {$month}. "
                .'The product charges no fee for more, so this withdrawal is refused.');
        }

        return $fee;
    }

    /** @throws ValidationException */
    private function assertWithdrawalLimits(SavingsProduct $product, int $centavos, string $field): void
    {
        if (! $product->allow_withdrawals) {
            Refuse::field($field, "{$product->name} doesn't allow withdrawals.");
        }

        $min = $this->limit($product->min_withdrawal);
        $max = $this->limit($product->max_withdrawal);

        if ($min !== null && $centavos < $min) {
            Refuse::field($field, "A withdrawal from {$product->name} is at least ".Refuse::peso($min).'.');
        }

        if ($max !== null && $centavos > $max) {
            Refuse::field($field, "A withdrawal from {$product->name} is at most ".Refuse::peso($max).'.');
        }
    }

    /**
     * Withdrawals counted against the free count for the Manila calendar month
     * of `$date`, each once, in the month it was requested:
     *
     * - posted withdrawals by `counted_on` (their request's date; a row
     *   posted before it existed counts by its `date`), not reversed (a time
     *   deposit's funding is not a withdrawal);
     * - pending withdrawal approvals by `counted_on` (else their date);
     * - members' open portal withdrawal requests by `counted_on` (else the
     *   day they were made).
     *
     * An approved or paid request leaves the count as its approval or posted
     * row joins it, under the same lock, so nothing counts twice.
     *
     * With `$before`, only what was requested before that withdrawal counts:
     * an earlier request time, or the same time and a lower id in the same
     * table. Its own approval or portal request is left out.
     */
    public function withdrawalsInMonth(SavingsAccount $account, string $date, ?WithdrawalFee $before = null): int
    {
        $day = CarbonImmutable::parse($date);
        $from = $day->startOfMonth()->toDateString();
        $to = $day->endOfMonth()->toDateString();
        $at = $before?->requestedAt;

        $posted = DB::table('savings_transactions AS t')
            ->where('t.account_id', $account->id)
            ->where('t.type', 'withdrawal')
            ->whereNull('t.time_deposit_id')
            ->whereRaw('COALESCE(t.counted_on, t.date) BETWEEN ? AND ?', [$from, $to])
            ->whereNotExists(fn ($q) => $q->from('savings_transactions AS r')->whereColumn('r.reverses_transaction_id', 't.id'))
            ->when($at !== null, fn ($q) => $q->whereRaw('COALESCE(t.requested_at, t.created_at) < ?', [$at]))
            ->count();

        $pending = DB::table('savings_approvals AS a')
            ->where('a.account_id', $account->id)
            ->where('a.kind', 'withdrawal')
            ->where('a.status', 'pending')
            ->where(fn ($q) => $q->whereNull('a.expires_at')->orWhere('a.expires_at', '>', now()))
            ->whereRaw("COALESCE(a.counted_on, JSON_UNQUOTE(JSON_EXTRACT(a.payload, '$.date'))) BETWEEN ? AND ?", [$from, $to])
            ->when($before !== null, fn ($q) => $this->requestedBefore($q, 'a', $before, WithdrawalFee::SOURCE_APPROVAL))
            ->count();

        $requested = DB::table('portal_requests AS p')
            ->where('p.savings_account_id', $account->id)
            ->where('p.type', PortalRequest::TYPE_SAVINGS_WITHDRAWAL)
            ->whereIn('p.status', self::OPEN_PORTAL_STATUSES)
            ->where(fn ($q) => $q->whereNull('p.expires_on')->orWhere('p.expires_on', '>=', now()->toDateString()))
            ->whereRaw('COALESCE(p.counted_on, DATE(p.created_at)) BETWEEN ? AND ?', [$from, $to])
            ->when($before !== null, fn ($q) => $this->requestedBefore($q, 'p', $before, WithdrawalFee::SOURCE_PORTAL_REQUEST))
            ->count();

        return $posted + $pending + $requested;
    }

    /**
     * Narrow approvals or portal requests (`$alias`) to those requested
     * before `$before`, leaving out its own row.
     */
    private function requestedBefore(QueryBuilder $query, string $alias, WithdrawalFee $before, string $source): QueryBuilder
    {
        $own = $before->source === $source ? $before->sourceId : null;

        return $query
            ->when($own !== null, fn ($q) => $q->where("{$alias}.id", '!=', $own))
            ->where(fn ($q) => $q
                ->whereRaw("COALESCE({$alias}.requested_at, {$alias}.created_at) < ?", [$before->requestedAt])
                ->when($own !== null, fn ($q) => $q->orWhere(fn ($q) => $q
                    ->whereRaw("COALESCE({$alias}.requested_at, {$alias}.created_at) = ?", [$before->requestedAt])
                    ->where("{$alias}.id", '<', $own))));
    }

    /**
     * Refuses (422 on `$field`) a member the product isn't offered to.
     *
     * @throws ValidationException
     */
    public function assertEligible(Borrower $member, SavingsProduct $product, string $field = 'product_id'): void
    {
        $problem = $this->eligibilityProblem($member, $product);

        if ($problem !== null) {
            Refuse::field($field, $problem);
        }
    }

    public function isEligible(Borrower $member, SavingsProduct $product): bool
    {
        return $this->eligibilityProblem($member, $product) === null;
    }

    /** Why the product isn't offered to this member, in words, or null when it is. */
    public function eligibilityProblem(Borrower $member, SavingsProduct $product): ?string
    {
        $types = $product->eligible_member_types;

        if ($types !== null && ! in_array($member->member_type, $types, true)) {
            $words = implode(' and ', array_map(fn (string $t) => self::memberTypeWords($t), $types));
            $theirs = $member->member_type === null ? 'no member type recorded' : 'is '.self::article($member->member_type).' '.self::memberTypeWords($member->member_type).' member';

            return "{$product->name} is open only to {$words} members, and {$member->full_name} "
                .($member->member_type === null ? 'has ' : '').$theirs.'.';
        }

        $branches = $product->eligible_branch_ids;

        if ($branches !== null && ! in_array((int) $member->branch_id, array_map('intval', $branches), true)) {
            $branch = $member->branch_id === null ? null : Branch::query()->whereKey($member->branch_id)->value('name');

            return $branch === null
                ? "{$product->name} is offered only in some branches, and {$member->full_name} has no branch yet."
                : "{$product->name} is not offered in {$branch}.";
        }

        return null;
    }

    /**
     * Narrow a products query to those offered to the member (type and
     * branch), as {@see self::isEligible()} reads them.
     *
     * @param  Builder<SavingsProduct>  $query
     * @return Builder<SavingsProduct>
     */
    public static function constrainEligible(Builder $query, Borrower $member): Builder
    {
        $table = $query->getModel()->getTable();

        return $query
            ->where(fn ($q) => $q->whereNull("{$table}.eligible_member_types")
                ->when($member->member_type !== null, fn ($q) => $q->orWhereJsonContains("{$table}.eligible_member_types", $member->member_type)))
            ->where(fn ($q) => $q->whereNull("{$table}.eligible_branch_ids")
                ->when($member->branch_id !== null, fn ($q) => $q->orWhereJsonContains("{$table}.eligible_branch_ids", (int) $member->branch_id)));
    }

    public static function memberTypeWords(string $type): string
    {
        return match ($type) {
            'regular' => 'regular',
            'associate' => 'associate',
            default => str_replace('_', ' ', $type),
        };
    }

    /** @throws ValidationException */
    private function assertWithinMaxBalance(SavingsAccount $account, SavingsProduct $product, int $centavos, string $field): void
    {
        $max = $this->limit($product->max_balance);

        if ($max === null) {
            return;
        }

        $balance = SavingsMath::signedCentavos($account->ledger_balance);

        if ($balance + $centavos > $max) {
            Refuse::field($field, "That would take {$account->account_number} to ".Refuse::peso($balance + $centavos)
                .", above {$product->name}'s maximum balance of ".Refuse::peso($max).'. It can take up to '.Refuse::peso(max(0, $max - $balance)).' more.');
        }
    }

    private function productOf(SavingsAccount $account): SavingsProduct
    {
        $product = $account->relationLoaded('product') ? $account->product : null;

        // A product loaded with only some columns can't answer for its rules.
        return $product instanceof SavingsProduct && $product->id === $account->product_id && array_key_exists('excess_withdrawal_fee', $product->getAttributes())
            ? $product
            : SavingsProduct::query()->findOrFail($account->product_id);
    }

    /** A nullable peso limit in centavos, or null for none. */
    private function limit(mixed $pesos): ?int
    {
        return $pesos === null ? null : SavingsMath::centavos($pesos);
    }

    private static function article(string $type): string
    {
        return in_array(strtolower($type[0] ?? ''), ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
    }
}

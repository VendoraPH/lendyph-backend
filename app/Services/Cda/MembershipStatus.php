<?php

namespace App\Services\Cda;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Models\ShareCapitalLedger;
use App\Models\TimeDeposit;
use App\Services\Accounting\Money;
use App\Services\AuditLogService;
use App\Services\LoanWriteTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ending and reinstating a membership (CDA design §1).
 *
 * - `withdrawn` is refused, in words, while the member still has a loan
 *   being paid (Loan::COLLECTIBLE_STATUSES), an open savings account or time
 *   deposit, or share capital above ₱0.00: those are settled first.
 * - `deceased` is always allowed: the estate settles what is left.
 * - Reactivating a former member needs a reason and clears the end date and
 *   reason.
 *
 * Each change runs under the member's row lock and writes one audit row on
 * the member, with the reason in its description (the automatic row is
 * replaced, not added to).
 */
final class MembershipStatus
{
    /** Loans, savings and the share capital balance are read under the member's lock. */
    public function end(Borrower $borrower, string $status, string $endedOn, string $reason): Borrower
    {
        return DB::transaction(function () use ($borrower, $status, $endedOn, $reason): Borrower {
            /** @var Borrower $locked */
            $locked = Borrower::query()->whereKey($borrower->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, Borrower::NON_MEMBER_STATUSES, true)) {
                throw ValidationException::withMessages(['status' => 'Only a member can end a membership; an application is approved or rejected instead.']);
            }

            if ($locked->isFormerMember()) {
                throw ValidationException::withMessages(['status' => self::endedMessage($locked)]);
            }

            if ($locked->membership_date !== null && $endedOn < $locked->membership_date->toDateString()) {
                throw ValidationException::withMessages(['ended_on' => 'The end date can\'t be before the membership date ('.$locked->membership_date->format('M j, Y').').']);
            }

            if ($status === 'withdrawn') {
                $open = $this->stillOpen($locked);

                if ($open !== []) {
                    throw ValidationException::withMessages([
                        'status' => "{$this->name($locked)} can't withdraw while they still have ".self::listInWords($open).'. Settle or close them first.',
                    ]);
                }
            }

            $this->write($locked, [
                'status' => $status,
                'membership_ended_on' => $endedOn,
                'membership_end_reason' => $reason,
            ], 'Membership ended ('.($status === 'withdrawn' ? 'withdrawn' : 'deceased').'): '.$reason);

            return $locked;
        });
    }

    public function reactivate(Borrower $borrower, string $reason): Borrower
    {
        return DB::transaction(function () use ($borrower, $reason): Borrower {
            /** @var Borrower $locked */
            $locked = Borrower::query()->whereKey($borrower->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isFormerMember()) {
                throw LoanWriteTransaction::conflict('This member\'s status changed at the same moment. Reload and try again.');
            }

            $this->write($locked, [
                'status' => 'active',
                'membership_ended_on' => null,
                'membership_end_reason' => null,
            ], 'Membership reactivated: '.$reason);

            return $locked;
        });
    }

    /** Why a withdrawn or deceased member's status can't change here. */
    public static function endedMessage(Borrower $borrower): string
    {
        $how = $borrower->status === 'deceased' ? 'is recorded as deceased' : 'has withdrawn';
        $when = $borrower->membership_ended_on === null ? '' : ' on '.Carbon::parse($borrower->membership_ended_on)->format('M j, Y');

        return "This member {$how}{$when}. Reactivate the membership first, with a reason.";
    }

    /**
     * What keeps a member from withdrawing, in words ("1 loan still being
     * paid (LN-000004)").
     *
     * @return list<string>
     */
    private function stillOpen(Borrower $borrower): array
    {
        $open = [];

        $loans = Loan::query()->where('borrower_id', $borrower->id)->whereIn('status', Loan::COLLECTIBLE_STATUSES)
            ->orderBy('id')->pluck('loan_account_number')->all();

        if ($loans !== []) {
            $numbers = array_values(array_filter($loans));
            $open[] = self::count(count($loans), 'loan', 'loans').' still being paid'.($numbers === [] ? '' : ' ('.implode(', ', $numbers).')');
        }

        $accounts = SavingsAccount::query()->where('borrower_id', $borrower->id)->where('status', '!=', SavingsAccount::STATUS_CLOSED)->count();

        if ($accounts > 0) {
            $open[] = self::count($accounts, 'open savings account', 'open savings accounts');
        }

        $deposits = TimeDeposit::query()->where('borrower_id', $borrower->id)->whereIn('status', ['active', 'matured'])->count();

        if ($deposits > 0) {
            $open[] = self::count($deposits, 'open time deposit', 'open time deposits');
        }

        $balance = ShareCapitalLedger::balancesFor([(int) $borrower->id])[(int) $borrower->id] ?? 0.0;
        $shareCapital = (int) Money::toCentavos(number_format($balance, 2, '.', ''));

        if ($shareCapital > 0) {
            $open[] = 'share capital of '.Money::format($shareCapital);
        }

        return $open;
    }

    /** @param  array<string, mixed>  $changes */
    private function write(Borrower $borrower, array $changes, string $description): void
    {
        $old = [];

        foreach (array_keys($changes) as $field) {
            $old[$field] = $borrower->getRawOriginal($field);
        }

        AuditLogService::withoutModelAuditing(fn () => $borrower->update($changes));

        AuditLogService::log('updated', $borrower, $old, $changes, $description);
    }

    private function name(Borrower $borrower): string
    {
        return trim("{$borrower->full_name} ({$borrower->borrower_code})");
    }

    private static function count(int $count, string $one, string $many): string
    {
        return $count.' '.($count === 1 ? $one : $many);
    }

    /** @param  list<string>  $items */
    private static function listInWords(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}

<?php

namespace App\Services;

use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * A save whose row gets its number from "the highest one so far, plus one":
 * members (BRW-), co-makers (CMK-), adjustments (ADJ-) and share capital
 * entries (SC-YYYYMMDD-). Two saves at the same moment can pick the same
 * number. The unique index lets only one through, and the other used to answer
 * 500. A member save could also deadlock with another one.
 *
 * Neither means anything was wrong with the request, so the whole save is
 * rolled back and run again, on a fresh snapshot that sees the number the other
 * save took: up to MAX_TRIES times, with a short backoff. Still colliding, it
 * answers 409 with nothing saved, never 500. Nothing can be created twice: each
 * try starts only after the one before it was fully rolled back.
 *
 * - The write must build its models inside the closure. A model an earlier try
 *   saved still reads as unchanged, so a later try would not write it again.
 * - A duplicate on any other index is rethrown: it is a different fault, and
 *   retrying it would only fail the same way.
 * - A lock wait timeout answers 409 at once. It has already cost the request
 *   the whole lock wait, and five of those would outlast any client.
 *
 * Only the outermost transaction can retry. Inside one, MySQL has already
 * rolled the whole transaction back on a deadlock, and a plain read would see
 * the same stale number again. So a nested call just runs the write, and the
 * outermost one answers for it: LoanWriteTransaction or
 * CollateralWriteTransaction, which turn a collision on these indexes into
 * their own 409 without retrying, for a co-maker or an adjustment created inside
 * a loan's money write.
 *
 * Not for a save that could post twice if tried again after another one
 * committed: an auto-credit run and a bulk share capital entry answer 409 at
 * once (ShareCapitalService).
 */
final class GeneratedNumberWrite
{
    use DetectsConcurrencyErrors;

    public const MAX_TRIES = 5;

    public const BORROWER_CODE_INDEX = 'borrowers_borrower_code_unique';

    public const CO_MAKER_CODE_INDEX = 'co_makers_co_maker_code_unique';

    public const ADJUSTMENT_NUMBER_INDEX = 'loan_adjustments_adjustment_number_unique';

    public const SHARE_CAPITAL_REFERENCE_INDEX = 'share_capital_ledger_reference_unique';

    public const SAVINGS_ACCOUNT_NUMBER_INDEX = 'savings_accounts_account_number_unique';

    public const SAVINGS_TRANSACTION_REFERENCE_INDEX = 'savings_transactions_reference_unique';

    public const TIME_DEPOSIT_CERTIFICATE_INDEX = 'time_deposits_certificate_number_unique';

    /** CDA mediation cases, `MC-YYYY-NNNN` (CdaMediationCase::booted()). */
    public const MEDIATION_CASE_NUMBER_INDEX = 'cda_mediation_cases_case_number_unique';

    /** A member's membership number, given from the member code on create. */
    public const MEMBERSHIP_NUMBER_INDEX = 'borrowers_membership_number_unique';

    public const MEMBER_CONFLICT = 'Another member was created at the same moment. Submit again.';

    public const CO_MAKER_CONFLICT = 'Another co-maker was created at the same moment. Submit again.';

    public const SHARE_CAPITAL_CONFLICT = 'Another share capital entry was saved at the same moment. Submit again.';

    public const ADJUSTMENT_CONFLICT = 'Another adjustment was requested at the same moment. Submit again.';

    public const SAVINGS_CONFLICT = 'Another savings transaction was saved at the same moment. Submit again.';

    public const MEDIATION_CONFLICT = 'Another mediation case was recorded at the same moment. Submit again.';

    private const INDEXES = [
        self::BORROWER_CODE_INDEX,
        self::CO_MAKER_CODE_INDEX,
        self::ADJUSTMENT_NUMBER_INDEX,
        self::SHARE_CAPITAL_REFERENCE_INDEX,
        self::SAVINGS_ACCOUNT_NUMBER_INDEX,
        self::SAVINGS_TRANSACTION_REFERENCE_INDEX,
        self::TIME_DEPOSIT_CERTIFICATE_INDEX,
        self::MEDIATION_CASE_NUMBER_INDEX,
        self::MEMBERSHIP_NUMBER_INDEX,
    ];

    private const BACKOFF_MILLISECONDS = 20;

    private const LOCK_WAIT_TIMEOUT = 1205;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $write  run in a transaction, possibly more than once
     * @param  string  $conflictMessage  the 409's message
     * @return TReturn
     *
     * @throws HttpResponseException 409 when it is still colliding after MAX_TRIES tries
     */
    public static function run(Closure $write, string $conflictMessage): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $write();
        }

        for ($try = 1; ; $try++) {
            try {
                return DB::transaction($write);
            } catch (UniqueConstraintViolationException $e) {
                if (! self::isCollision($e)) {
                    throw $e;
                }
            } catch (PDOException $e) {
                if (! (new self)->causedByConcurrencyError($e)) {
                    throw $e;
                }

                if (($e->errorInfo[1] ?? null) === self::LOCK_WAIT_TIMEOUT) {
                    throw LoanWriteTransaction::conflict($conflictMessage);
                }
            }

            if ($try === self::MAX_TRIES) {
                throw LoanWriteTransaction::conflict($conflictMessage);
            }

            usleep($try * random_int(self::BACKOFF_MILLISECONDS, 2 * self::BACKOFF_MILLISECONDS) * 1000);
        }
    }

    /**
     * Whether this duplicate is two saves taking the same generated number.
     */
    public static function isCollision(UniqueConstraintViolationException $e): bool
    {
        return in_array($e->index, self::INDEXES, true);
    }
}

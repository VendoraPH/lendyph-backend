<?php

namespace App\Services\Savings;

use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Services\Savings\Api\Refuse;
use Illuminate\Validation\ValidationException;

/**
 * One outside payment, one record (finish run round 2, decision 11): a
 * deposit, a withdrawal (an opening deposit and an approved withdrawal
 * included) or a time deposit placement paid by any method but cash whose
 * reference is already on record for the same method anywhere in the
 * cooperative is refused, 422 in words that name the reference and nothing
 * else (no member, account or branch).
 *
 * "On record": a savings transaction or a placement with that method and
 * reference, unless the transaction was reversed (a reversed row frees its
 * reference for the entry that corrects it), or a withdrawal still waiting
 * for its checker with that method and reference in its request (review of
 * #378): it posts with them once approved, so it holds them while it waits,
 * and a rejected, cancelled or expired one frees them. The approval being
 * posted is left out of its own check. References compare as MySQL compares
 * the columns: case and trailing spaces ignored.
 *
 * Exempt: cash (a counter receipt, and a cash placement's reference is its
 * own TD number), and the writers that never call this: auto-credit runs and
 * the system's own rows (interest, tax, fees, closing, settlements and
 * reversals). New records only: rows already saved are never re-checked.
 *
 * The callers run it under the account row lock (the Teller), the member row
 * lock (the opener) or the member's branch lock (a placement), so two entries
 * for one account or one member can't both take a reference. Two entries for
 * different members at the same moment are not serialised.
 */
class DuplicateReference
{
    /** "another …", by method. */
    private const RECORD_WORDS = [
        'bank_transfer' => 'bank transfer',
        'gcash' => 'GCash transaction',
        'maya' => 'Maya transaction',
        'savings' => 'transfer from savings',
    ];

    /**
     * @param  int|null  $postingApprovalId  the approved withdrawal being posted, which doesn't count against itself
     *
     * @throws ValidationException when the reference is already on record for this method
     */
    public function assertUnused(?string $method, ?string $reference, string $field = 'reference', ?int $postingApprovalId = null): void
    {
        $reference = $reference === null ? '' : trim($reference);

        if ($method === null || $method === 'cash' || $reference === '') {
            return;
        }

        if ($this->onRecord($method, $reference, $postingApprovalId)) {
            Refuse::field($field, self::message($method, $reference));
        }
    }

    /** `Reference "GC-1" is already on record for another GCash transaction. …` */
    public static function message(string $method, string $reference): string
    {
        return sprintf(
            'Reference "%s" is already on record for another %s. Check the reference and enter the right one.',
            $reference,
            self::RECORD_WORDS[$method] ?? 'transaction by that method',
        );
    }

    private function onRecord(string $method, string $reference, ?int $postingApprovalId): bool
    {
        $onSavings = SavingsTransaction::query()
            ->where('method', $method)
            ->where('external_reference', $reference)
            ->whereNotExists(fn ($reversals) => $reversals->selectRaw('1')
                ->from('savings_transactions as reversals')
                ->whereColumn('reversals.reverses_transaction_id', 'savings_transactions.id'))
            ->exists();

        return $onSavings
            || TimeDeposit::query()->where('funding_method', $method)->where('reference', $reference)->exists()
            || $this->heldByAWaitingWithdrawal($method, $reference, $postingApprovalId);
    }

    /**
     * A withdrawal request waiting for its checker, with this method and
     * reference in its stored request. The JSON value is compared in the
     * columns' collation, so case and trailing spaces are ignored here too.
     */
    private function heldByAWaitingWithdrawal(string $method, string $reference, ?int $postingApprovalId): bool
    {
        return SavingsApproval::query()
            ->where('kind', 'withdrawal')
            ->where('status', 'pending')
            ->when($postingApprovalId !== null, fn ($approvals) => $approvals->whereKeyNot($postingApprovalId))
            ->where('payload->payment_method', $method)
            ->whereNotNull('payload->reference')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.reference')) COLLATE utf8mb4_unicode_ci = ?", [$reference])
            ->exists();
    }
}

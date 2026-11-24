<?php

namespace App\Services\Savings;

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
 * reference, compared as MySQL compares them (case and trailing spaces
 * ignored), unless the transaction was reversed: a reversed row frees its
 * reference for the entry that corrects it.
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
     * @throws ValidationException when the reference is already on record for this method
     */
    public function assertUnused(?string $method, ?string $reference, string $field = 'reference'): void
    {
        $reference = $reference === null ? '' : trim($reference);

        if ($method === null || $method === 'cash' || $reference === '') {
            return;
        }

        if ($this->onRecord($method, $reference)) {
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

    private function onRecord(string $method, string $reference): bool
    {
        $onSavings = SavingsTransaction::query()
            ->where('method', $method)
            ->where('external_reference', $reference)
            ->whereNotExists(fn ($reversals) => $reversals->selectRaw('1')
                ->from('savings_transactions as reversals')
                ->whereColumn('reversals.reverses_transaction_id', 'savings_transactions.id'))
            ->exists();

        return $onSavings || TimeDeposit::query()->where('funding_method', $method)->where('reference', $reference)->exists();
    }
}

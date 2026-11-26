<?php

namespace App\Services\Savings;

use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Services\Savings\Api\Refuse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * One outside payment, one record (finish run round 2, decision 11): a
 * deposit, a withdrawal (an opening deposit and an approved withdrawal
 * included), a payout at closing, at maturity or on pre-termination (round
 * 5: money leaving, like a withdrawal) or a time deposit placement paid by
 * any method but cash whose reference is already on record for the same
 * method anywhere in the cooperative is refused, 422 in words that name the
 * reference and nothing else (no member, account or branch).
 *
 * "On record": a savings transaction (a closing payout's row included), a
 * placement or a time deposit's payout with that method and reference,
 * unless the transaction was reversed (a reversed row frees its reference
 * for the entry that corrects it).
 *
 * "Held" (reviews of #378): a withdrawal or payout still waiting for its
 * checker with that method and reference in its request posts with them
 * once approved, so it holds them while it waits, in words of its own that
 * say where to look; a rejected, cancelled or expired one frees them. An
 * approved one being posted counts only those requested before it (a lower
 * id): of two waiting ones that already share a reference (from before this
 * check, or two requests at the same instant), the older posts and the newer
 * is refused, whichever is approved first.
 *
 * References compare as MySQL compares the columns: case and trailing spaces
 * ignored.
 *
 * Exempt: cash (a counter receipt, and a cash placement's reference is its
 * own TD number), and the writers that never call this: auto-credit runs and
 * the system's own rows (interest, tax, fees, the maturity job's settlements
 * and reversals). New records only: rows already saved are never re-checked.
 *
 * One writer per method at a time (finish run round 6, money review): before
 * it reads, the check takes the method's row in `savings_reference_locks`
 * `FOR UPDATE`, held until the caller's transaction ends, so two writers on
 * different accounts checking the same method wait for each other and the
 * second sees the first's reference. It is always the last of the caller's
 * record locks: every caller already holds its own first, and none takes an
 * account, placement or member lock after it:
 * - Teller (deposit, withdrawal, an approved withdrawal posting): the account;
 * - AccountCloser (close, an approved close): the account;
 * - AccountOpener: the member row;
 * - Placement (a non-cash placement): the member's branch;
 * - Settlement and the td_payout and pretermination executors: the payout
 *   account, then the placement;
 * - PayoutReferences (a reference recorded afterwards): the account, then the
 *   transaction; or the placement.
 * Cash, a blank reference and a method with no lock row take no lock.
 */
class DuplicateReference
{
    /** "a … withdrawal", by method. */
    private const WITHDRAWAL_WORDS = [
        'bank_transfer' => 'bank transfer',
        'gcash' => 'GCash',
        'maya' => 'Maya',
    ];

    /**
     * The approval kinds that pay money out once approved, with the payload
     * keys holding their method and reference.
     */
    private const HOLDING_KINDS = [
        'withdrawal' => ['payment_method', 'reference'],
        'close' => ['payout_method', 'payout_reference'],
        'td_payout' => ['payout_method', 'payout_reference'],
        'pretermination' => ['payout_method', 'payout_reference'],
    ];

    /** The methods with a row in `savings_reference_locks` (the migration seeds them). */
    private const LOCKED_METHODS = ['bank_transfer', 'gcash', 'maya', 'savings'];

    /** "another …", by method. */
    private const RECORD_WORDS = [
        'bank_transfer' => 'bank transfer',
        'gcash' => 'GCash transaction',
        'maya' => 'Maya transaction',
        'savings' => 'transfer from savings',
    ];

    /**
     * @param  int|null  $postingApprovalId  the approved withdrawal or payout being posted: only those waiting since before it count
     *
     * @throws ValidationException when the reference is already on record, or held, for this method
     */
    public function assertUnused(?string $method, ?string $reference, string $field = 'reference', ?int $postingApprovalId = null): void
    {
        $reference = $reference === null ? '' : trim($reference);

        if ($method === null || $method === 'cash' || $reference === '') {
            return;
        }

        if (in_array($method, self::LOCKED_METHODS, true)) {
            $this->lockMethod($method);
        }

        if ($this->onRecord($method, $reference)) {
            Refuse::field($field, self::message($method, $reference));
        }

        $holder = $this->heldBy($method, $reference, $postingApprovalId);

        if ($holder !== null) {
            Refuse::field($field, self::heldMessage($method, $reference, $holder));
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

    /**
     * `Reference "BDO-9" is held by a bank transfer withdrawal waiting for
     * approval. …`; a payout (`close`, `td_payout`, `pretermination`) reads
     * "payout".
     */
    public static function heldMessage(string $method, string $reference, string $kind = 'withdrawal'): string
    {
        $what = $kind === 'withdrawal' ? 'withdrawal' : 'payout';
        $words = self::WITHDRAWAL_WORDS[$method] ?? null;

        return sprintf(
            'Reference "%s" is held by a %s waiting for approval. Check the reference, or decide that %s first.',
            $reference,
            $words === null ? $what : "{$words} {$what}",
            $what,
        );
    }

    /**
     * Wait for any other writer checking `$method`, and hold its lock row
     * until this transaction ends.
     */
    private function lockMethod(string $method): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A reference is checked only inside the transaction that saves it.');
        }

        DB::table('savings_reference_locks')->where('method', $method)->lockForUpdate()->value('method');
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

        return $onSavings
            || TimeDeposit::query()->where('funding_method', $method)->where('reference', $reference)->exists()
            || TimeDeposit::query()->where('payout_method', $method)->where('payout_reference', $reference)->exists();
    }

    /**
     * The kind of the oldest withdrawal or payout request waiting for its
     * checker with this method and reference in its stored request, or null;
     * for an approved one being posted, only those requested before it. The
     * JSON value is compared in the columns' collation, so case and trailing
     * spaces are ignored here too.
     */
    private function heldBy(string $method, string $reference, ?int $postingApprovalId): ?string
    {
        return SavingsApproval::query()
            ->where('status', 'pending')
            ->when($postingApprovalId !== null, fn ($approvals) => $approvals->where('id', '<', $postingApprovalId))
            ->where(function ($approvals) use ($method, $reference): void {
                foreach (self::HOLDING_KINDS as $kind => [$methodKey, $referenceKey]) {
                    $approvals->orWhere(fn ($held) => $held
                        ->where('kind', $kind)
                        ->where("payload->{$methodKey}", $method)
                        ->whereNotNull("payload->{$referenceKey}")
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.{$referenceKey}')) COLLATE utf8mb4_unicode_ci = ?", [$reference]));
                }
            })
            ->orderBy('id')
            ->value('kind');
    }
}

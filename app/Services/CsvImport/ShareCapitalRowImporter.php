<?php

namespace App\Services\CsvImport;

use App\Models\Borrower;
use App\Models\CsvImportRow;
use App\Models\CsvImportRun;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\Accounting\Money;
use App\Services\BranchAccess;
use App\Services\Branches\BranchRowLock;
use App\Services\CashReferences;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Writes one staged row of the share capital file onto its member's ledger
 * (finish run round 2, decision 13; ClickUp 14zfbg0e8ev), inside the import
 * pass's chunk transaction (CsvImportProcessor::writeChunk()), and says what
 * became of it.
 *
 * ## Who the row is for
 *
 * The row's Account No. or Member ID names a member by their member ID
 * (`borrower_code`) or account number (`external_account_no`); a member of the
 * same run's members file has the account number from that file by now. Only
 * members the run's starter can see count (BranchAccess), so another branch's
 * member reads as no member, never as someone this import can't reach. The
 * member must be a member (not a pending or rejected registration) who hasn't
 * withdrawn, as a share capital entry made in the app needs; a deceased
 * member's estate still settles. A member's branch that is closed or waiting
 * to close takes nothing (BranchRowLock), in words.
 *
 * Locks in the order every share capital write takes them: the member's
 * branch row, then the member's row, read again under its shared lock (a
 * member moved to another branch in between is refused, in words).
 *
 * ## Duplicates
 *
 * Against the ledger, which by now holds every row this run has written: by
 * reference when the row has one (the ledger's reference number or its own
 * SC- reference, in any case; a CSH number as the app writes it, so
 * CSH-12-2026-7 is CSH-0012-2026-0007), else by member, date, type and amount. A row
 * whose referenced entry an earlier import wrote with the same figures is
 * `already_imported`; any other duplicate is skipped, naming where the entry
 * it repeats came from.
 *
 * ## Withdrawals
 *
 * The share capital file's contributions are all written before its
 * withdrawals (CsvImportProcessor), and a withdrawal is refused when the
 * member's balance, read in posting order (date, then entry), would fall below
 * ₱0.00 at its date or any time after it.
 *
 * ## What is written
 *
 * One entry, marked `is_imported`, in the member's branch that was locked,
 * with the ledger's own SC- reference (ShareCapitalLedger::booted()) and no
 * payment method, since no money moved in this app. It posts no journal, as an imported loan doesn't: these
 * are the old books' figures, and the accountant brings them over through the
 * opening balances. Its reference is never one of the app's CSH numbers given
 * here; when it is already in this deployment's own CSH format, the cash
 * series is moved past it (CashReferences::advance()) in the same
 * transaction, so the app never gives that number to a cash entry.
 */
final class ShareCapitalRowImporter
{
    public const NEEDS_PERMISSION = 'share_capital_needs_permission';

    public const NEEDS_PERMISSION_MESSAGE = 'Only staff who can record share capital can import it, and the person who started this import can\'t any more. Have someone who can record share capital start the import again.';

    private const FROM_AN_EARLIER_IMPORT = 'from an earlier import';

    /** Finishes BranchRowLock's refusal: `Branch "…" (…) is closed, so …`. */
    public const BRANCH_CONSEQUENCE = 'no share capital can be imported for its members';

    public function __construct(private readonly CashReferences $cashReferences = new CashReferences) {}

    /**
     * The writer for one chunk. The starter's access and the cash series'
     * owner are read once here, fresh on every chunk: a run can wait days on
     * its product mapping, and its starter can lose the access meanwhile.
     * Built before the chunk's transaction opens (CsvImportProcessor), as
     * CashReferences wants its owner read.
     *
     * @return Closure(CsvImportRow): RowOutcome
     */
    public function writerFor(CsvImportRun $run): Closure
    {
        $starter = $run->initiated_by === null ? null : User::query()->find($run->initiated_by);

        if ($starter === null || $starter->status === 'inactive' || ! $starter->can('share_capital:create')) {
            return static fn (CsvImportRow $row): RowOutcome => RowOutcome::failed(self::NEEDS_PERMISSION, self::NEEDS_PERMISSION_MESSAGE);
        }

        $branches = app(BranchAccess::class)->for($starter);
        $owner = $this->cashReferences->owner();

        return fn (CsvImportRow $row): RowOutcome => $this->write($run, $row, $branches, $owner);
    }

    /**
     * @param  list<int>|null  $branches  the starter's branches; null is every branch
     * @param  int|null  $owner  the cash series' owner (CashReferences::owner())
     */
    private function write(CsvImportRun $run, CsvImportRow $row, ?array $branches, ?int $owner): RowOutcome
    {
        $normalized = NormalizedRow::fromPayload((array) $row->normalized);
        $identifier = (string) $normalized->value('account_no');

        $members = Borrower::query()
            ->where(fn ($query) => $query->where('borrower_code', $identifier)->orWhere('external_account_no', $identifier))
            ->when($branches !== null, fn ($query) => $query->whereIn('branch_id', $branches))
            ->orderBy('id')
            ->limit(2)
            ->get(['id', 'branch_id']);

        if ($members->isEmpty()) {
            return RowOutcome::failed('borrower_not_found', "No member was found with account number or member ID \"{$identifier}\", so this entry wasn't imported. Use the account number from the members file, or the member's ID in Lendyph.");
        }

        if ($members->count() > 1) {
            return RowOutcome::skipped('ambiguous_match', "\"{$identifier}\" is one member's ID and another member's account number, so this entry wasn't imported. Write the member ID of the member it belongs to.");
        }

        $memberId = (int) $members->first()->id;
        $branchId = $members->first()->branch_id === null ? null : (int) $members->first()->branch_id;

        // The lock order of every share capital write (ShareCapitalService):
        // the member's branch row first, then the member's own row, read again
        // under its shared lock. A member moved to another branch in between
        // posts nothing in a branch that wasn't locked and checked, and the
        // member row stays locked until the chunk commits.
        try {
            BranchRowLock::lock(['account_no' => $branchId], ['account_no' => self::BRANCH_CONSEQUENCE]);
        } catch (ValidationException $refused) {
            return RowOutcome::failed('branch_closed', (string) collect($refused->errors())->flatten()->first(), $memberId);
        }

        $member = Borrower::query()->whereKey($memberId)->sharedLock()->first(['id', 'status', 'branch_id']);

        if ($member === null) {
            return RowOutcome::failed('borrower_not_found', "No member was found with account number or member ID \"{$identifier}\", so this entry wasn't imported. Use the account number from the members file, or the member's ID in Lendyph.");
        }

        if (($member->branch_id === null ? null : (int) $member->branch_id) !== $branchId) {
            return RowOutcome::failed('member_moved', 'This member was moved to another branch while the import was writing this entry, so it wasn\'t imported. Import the file again to bring it in: the entries already imported are skipped.', $memberId);
        }

        if (in_array($member->status, Borrower::NON_MEMBER_STATUSES, true)) {
            return RowOutcome::failed('not_a_member', "The record with account number or member ID \"{$identifier}\" is a registration that isn't an approved member, so no share capital is recorded for it.", $memberId);
        }

        if ($member->status === 'withdrawn') {
            return RowOutcome::failed('member_withdrawn', "The member with account number or member ID \"{$identifier}\" withdrew from the cooperative, so no share capital is recorded for them.", $memberId);
        }

        $entry = [
            'member' => (int) $member->id,
            'date' => (string) $normalized->value('transaction_date'),
            'side' => $this->side($normalized),
            'centavos' => $this->centavos($normalized),
            // As the normalizer writes it (a CSH number the app's way), for a
            // row staged before it did.
            'reference' => ShareCapitalRowNormalizer::reference($normalized->value('reference_number')),
        ];

        $duplicate = $this->duplicateOf($entry, $row);

        if ($duplicate !== null) {
            return $duplicate;
        }

        if ($entry['side'] === ShareCapitalRowNormalizer::DEBIT) {
            $lowest = $this->lowestBalanceFrom($entry['member'], $entry['date']);

            // In words only: the report is read with imports:process alone, so
            // it never shows the member's balance.
            if ($entry['centavos'] > $lowest) {
                return RowOutcome::failed('withdrawal_exceeds_balance', sprintf(
                    'This withdrawal of %s on %s is more than the member\'s share capital can cover from that date on, so it wasn\'t imported. A share capital balance can\'t go below ₱0.00.',
                    Money::format($entry['centavos']),
                    $entry['date'],
                ), $entry['member']);
            }
        }

        $series = $this->ownCashSeries($entry['reference'], $owner);

        if ($series !== null && $series['sequence'] > CashReferences::MAX_SEQUENCE) {
            return RowOutcome::failed('cash_reference_too_large', "Reference {$entry['reference']} is written as one of this app's own cash numbers (CSH), and its number is larger than that numbering goes. Check the reference.", $entry['member']);
        }

        // The series row before the entry, in the order a cash entry made in
        // the app takes them (ShareCapitalService::createEntry()).
        if ($series !== null && $owner !== null) {
            $this->cashReferences->advance(CashReferences::SHARE_CAPITAL, $owner, $series['year'], $series['sequence']);
        }

        $remarks = $normalized->value('remarks');
        $pesos = $this->pesos($entry['centavos']);

        $written = ShareCapitalLedger::create([
            'borrower_id' => $entry['member'],
            // The branch that was locked and checked above, as every share
            // capital write posts its row (ShareCapitalService).
            'branch_id' => $branchId,
            'date' => $entry['date'],
            'description' => is_string($remarks) ? $remarks : ($entry['side'] === ShareCapitalRowNormalizer::CREDIT ? 'Contribution (imported)' : 'Withdrawal (imported)'),
            'debit' => $entry['side'] === ShareCapitalRowNormalizer::DEBIT ? $pesos : '0.00',
            'credit' => $entry['side'] === ShareCapitalRowNormalizer::CREDIT ? $pesos : '0.00',
            'reference_number' => $entry['reference'],
            'created_by' => $run->initiated_by,
            'is_imported' => true,
        ]);

        return RowOutcome::imported(
            borrowerId: $entry['member'],
            category: $normalized->warnings === [] ? null : 'imported_with_warnings',
            message: $normalized->warnings === [] ? null : mb_substr(implode(' ', array_column($normalized->warningsToArray(), 'message')), 0, 2000),
            shareCapitalLedgerId: (int) $written->id,
        );
    }

    /**
     * What the ledger already holds that this row repeats, as the row's
     * outcome, or null when it holds nothing of the kind. See the class
     * docblock for the rule.
     *
     * @param  array{member: int, date: string, side: string, centavos: int, reference: string|null}  $entry
     */
    private function duplicateOf(array $entry, CsvImportRow $row): ?RowOutcome
    {
        if ($entry['reference'] !== null) {
            $existing = ShareCapitalLedger::query()
                ->where(fn ($query) => $query->where('reference_number', $entry['reference'])->orWhere('reference', $entry['reference']))
                ->orderBy('id')
                ->first();

            if ($existing === null) {
                return null;
            }

            $where = $this->whereFrom($existing, $row);

            if ($where === self::FROM_AN_EARLIER_IMPORT && $this->sameFigures($existing, $entry)) {
                return RowOutcome::alreadyImported($entry['member'], "This entry, reference {$entry['reference']}, was brought in by an earlier import, so it was skipped.", shareCapitalLedgerId: (int) $existing->id);
            }

            return RowOutcome::skipped('duplicate_reference', "Reference {$entry['reference']} is already on the share capital ledger, {$where}, so this row wasn't imported. A reference number belongs to one transaction.", $entry['member']);
        }

        $existing = ShareCapitalLedger::query()
            ->where('borrower_id', $entry['member'])
            ->where('date', $entry['date'])
            ->where($entry['side'], $this->pesos($entry['centavos']))
            ->orderBy('id')
            ->first();

        if ($existing === null) {
            return null;
        }

        return RowOutcome::skipped('duplicate_transaction', sprintf(
            'This member already has a %s of %s dated %s on the share capital ledger, %s, so this row wasn\'t imported. If both are real, give each its own reference number.',
            $entry['side'] === ShareCapitalRowNormalizer::CREDIT ? 'contribution' : 'withdrawal',
            Money::format($entry['centavos']),
            $entry['date'],
            $this->whereFrom($existing, $row),
        ), $entry['member']);
    }

    /**
     * @param  array{member: int, date: string, side: string, centavos: int, reference: string|null}  $entry
     */
    private function sameFigures(ShareCapitalLedger $existing, array $entry): bool
    {
        $other = $entry['side'] === ShareCapitalRowNormalizer::CREDIT ? 'debit' : 'credit';

        return (int) $existing->borrower_id === $entry['member']
            && $existing->date?->toDateString() === $entry['date']
            && Money::toCentavos((string) $existing->{$entry['side']}) === $entry['centavos']
            && Money::toCentavos((string) $existing->{$other}) === 0;
    }

    /**
     * Where an entry on the ledger came from, for a message: a line of this
     * file, an earlier import, or the app itself.
     */
    private function whereFrom(ShareCapitalLedger $existing, CsvImportRow $row): string
    {
        if (! $existing->is_imported) {
            return 'recorded in Lendyph';
        }

        $line = DB::table('csv_import_rows')
            ->where('share_capital_ledger_id', $existing->id)
            ->where('csv_import_file_id', $row->csv_import_file_id)
            ->value('line_number');

        return $line === null ? self::FROM_AN_EARLIER_IMPORT : "from line {$line} of this file";
    }

    /**
     * The lowest the member's share capital stands, in centavos, from the end
     * of `$date` on: the balance after every entry dated on or before it, and
     * after each later entry, in posting order (date, then entry), which is how
     * the Subsidiary Ledger runs it. A new entry dated `$date` goes after that
     * day's others, so a withdrawal of up to this much leaves no point at or
     * after it below ₱0.00.
     */
    private function lowestBalanceFrom(int $memberId, string $date): int
    {
        $entries = DB::table('share_capital_ledger')
            ->where('borrower_id', $memberId)
            ->orderBy('date')
            ->orderBy('id')
            ->get(['date', 'credit', 'debit']);

        $balance = 0;
        $lowest = null;

        foreach ($entries as $entry) {
            $later = substr((string) $entry->date, 0, 10) > $date;

            if ($later && $lowest === null) {
                $lowest = $balance;
            }

            $balance += (int) Money::toCentavos((string) $entry->credit) - (int) Money::toCentavos((string) $entry->debit);

            if ($later) {
                $lowest = min($lowest, $balance);
            }
        }

        return $lowest ?? $balance;
    }

    /**
     * The year and number of a reference in this deployment's own cash
     * share capital series (CSH, its owner), or null for any other reference.
     *
     * @return array{year: int, sequence: int}|null
     */
    private function ownCashSeries(?string $reference, ?int $owner): ?array
    {
        if ($reference === null || $owner === null) {
            return null;
        }

        $number = CashReferences::parse($reference);

        if ($number === null || $number['prefix'] !== CashReferences::SHARE_CAPITAL || $number['owner'] !== $owner || $number['sequence'] < 1) {
            return null;
        }

        return ['year' => $number['year'], 'sequence' => $number['sequence']];
    }

    /**
     * The staged amount, held to being integer centavos: a money value that
     * came back as anything else from the JSON column would be a different
     * figure from the one the file holds.
     */
    private function centavos(NormalizedRow $normalized): int
    {
        $value = $normalized->value('amount');

        if (! is_int($value)) {
            throw new LogicException('A staged share capital amount came back as '.get_debug_type($value).' rather than integer centavos.');
        }

        return $value;
    }

    /**
     * The staged side of the ledger, `credit` or `debit`, and only those:
     * it also names the column the duplicate check reads.
     */
    private function side(NormalizedRow $normalized): string
    {
        $side = $normalized->value('transaction_type');

        if (! in_array($side, [ShareCapitalRowNormalizer::CREDIT, ShareCapitalRowNormalizer::DEBIT], true)) {
            throw new LogicException('A staged share capital row holds no transaction type the ledger has.');
        }

        return $side;
    }

    /** Integer centavos as the exact decimal string the ledger stores. */
    private function pesos(int $centavos): string
    {
        return intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }
}

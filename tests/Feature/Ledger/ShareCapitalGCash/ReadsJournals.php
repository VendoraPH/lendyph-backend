<?php

namespace Tests\Feature\Ledger\ShareCapitalGCash;

use App\Models\AccountingJournal;
use App\Models\GCashTransaction;
use App\Models\ShareCapitalLedger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads the journals a share capital or GCash action posted. Uses
 * PostsJournals::account() for account codes, so the class seeds the chart
 * through seedChartOfAccounts() first.
 */
trait ReadsJournals
{
    private function journalOn(Model $postable, string $source): ?AccountingJournal
    {
        return AccountingJournal::query()
            ->where('postable_type', $postable->getMorphClass())
            ->where('postable_id', $postable->getKey())
            ->where('source', $source)
            ->first();
    }

    /** @return Collection<int, AccountingJournal> */
    private function journalsFrom(string $source): Collection
    {
        return AccountingJournal::query()->where('source', $source)->orderBy('id')->get();
    }

    /** Centavos on one side of an account in a journal. */
    private function centavosOn(AccountingJournal $journal, string $code, string $side): int
    {
        return (int) $journal->lines()->where('accounting_account_id', $this->account($code))->sum($side);
    }

    /**
     * Each line as "code side centavos", sorted, so a test can state the
     * whole journal and nothing else may sneak in.
     *
     * @return list<string>
     */
    private function linesOf(AccountingJournal $journal): array
    {
        $codes = array_flip($this->accountIds);

        return $journal->lines()->get()
            ->map(fn ($line): string => $codes[(int) $line->accounting_account_id]
                .((int) $line->debit > 0 ? ' Dr '.(int) $line->debit : ' Cr '.(int) $line->credit))
            ->sort()
            ->values()
            ->all();
    }

    private function shareCapitalJournalCount(): int
    {
        return AccountingJournal::query()->where('postable_type', (new ShareCapitalLedger)->getMorphClass())->count();
    }

    private function gcashJournalCount(): int
    {
        return AccountingJournal::query()->where('postable_type', (new GCashTransaction)->getMorphClass())->count();
    }
}

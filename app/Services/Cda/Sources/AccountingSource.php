<?php

namespace App\Services\Cda\Sources;

use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\AccountingYearClose;
use App\Services\Accounting\AccountRules;
use App\Services\Accounting\IncomeStatementBuilder;
use App\Services\Cda\Reports\Rational;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Figures from the books for CDA reports (design §2), read the way the
 * accounting reports read them: posted and reversed journals count, drafts
 * don't.
 *
 * - `accounting.year_closed`: the period's year has a standing year-end
 *   close (AccountingYearClose, `closed_year`).
 * - `accounting.balance {code}`: the account's balance as of the day in its
 *   normal direction; a group heading sums every account under it.
 * - `accounting.flow {code, side}`: the debits, credits or net movement in a
 *   range, leaving out closing entries and their reversals (they move the
 *   year's result, they aren't activity).
 *
 * Until the year is closed every figure is still computed, marked
 * provisional (`meta.year_closed: false`); the report raises the Critical
 * issue.
 */
final class AccountingSource implements CdaSource
{
    public function resolve(string $key, array $params, SourceContext $context): SourceResult
    {
        $closed = self::yearClosed($context->year());

        return match ($key) {
            'accounting.year_closed' => new SourceResult($closed, $closed ? 'Accounting › Year-end close' : 'Accounting › Year-end close (not closed)', 'boolean', null, false, ['year_closed' => $closed]),
            'accounting.balance' => $this->balance($params, $context, $closed),
            'accounting.flow' => $this->flow($params, $context, $closed),
            default => throw SourceUnavailable::unknownKey($key, 'accounting'),
        };
    }

    public static function yearClosed(int $year): bool
    {
        return AccountingYearClose::query()->where('closed_year', $year)->where('status', AccountingYearClose::STATUS_CLOSED)->exists();
    }

    /** The label suffix for a figure from the books. */
    public static function basis(bool $closed): string
    {
        return $closed ? 'closed year' : 'year not closed';
    }

    private function balance(array $params, SourceContext $context, bool $closed): SourceResult
    {
        $asOf = $context->date($params['as_of']);
        $account = $this->account((string) $params['code']);
        $value = null;

        if ($account !== null) {
            $row = $this->lines($this->accountIds($account))->where('j.date', '<=', $asOf)
                ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')->first();
            $value = Rational::fromCentavos(AccountRules::signedBalance((string) $account->normal_balance, (int) $row->debit, (int) $row->credit))->toFixed(2);
        }

        return new SourceResult(
            $value,
            SourceResult::labelFor('Accounting › '.$params['code'].' '.($account->name ?? '(no such account)').' ('.self::basis($closed).')', $asOf),
            'money',
            $asOf,
            ! $closed,
            ['year_closed' => $closed] + ($account === null ? ['note' => "The chart of accounts has no account {$params['code']}."] : []),
        );
    }

    private function flow(array $params, SourceContext $context, bool $closed): SourceResult
    {
        $from = $context->date($params['from']);
        $to = $context->date($params['to']);
        $account = $this->account((string) $params['code']);
        $value = null;

        if ($account !== null) {
            $query = $this->lines($this->accountIds($account))->where('j.date', '>=', $from)->where('j.date', '<=', $to);
            IncomeStatementBuilder::withoutClosingEntries($query, 'j');
            $row = $query->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')->first();
            $centavos = match ($params['side']) {
                'debit' => (int) $row->debit,
                'credit' => (int) $row->credit,
                default => AccountRules::signedBalance((string) $account->normal_balance, (int) $row->debit, (int) $row->credit),
            };
            $value = Rational::fromCentavos($centavos)->toFixed(2);
        }

        $side = ['debit' => 'debits', 'credit' => 'credits', 'net' => 'net movement'][$params['side']];

        return new SourceResult(
            $value,
            SourceResult::labelFor('Accounting › '.$params['code'].' '.($account->name ?? '(no such account)').", {$side} (".self::basis($closed).')', $to),
            'money',
            $to,
            ! $closed,
            ['year_closed' => $closed] + ($account === null ? ['note' => "The chart of accounts has no account {$params['code']}."] : []),
        );
    }

    private function account(string $code): ?AccountingAccount
    {
        return AccountingAccount::query()->where('code', $code)->first();
    }

    /**
     * The account and every account under it, so a group heading reads as
     * the sum of its children.
     *
     * @return list<int>
     */
    private function accountIds(AccountingAccount $account): array
    {
        $ids = [(int) $account->id];
        $frontier = $ids;

        while ($frontier !== []) {
            $frontier = AccountingAccount::query()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->diff($ids)->values()->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    /** @param  list<int>  $accountIds */
    private function lines(array $accountIds): Builder
    {
        return DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            ->whereIn('l.accounting_account_id', $accountIds);
    }
}

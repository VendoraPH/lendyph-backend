<?php

namespace App\Services\Cda\Sources;

use App\Services\Cda\Reports\Rational;
use Illuminate\Support\Facades\DB;

/**
 * Share capital for CDA reports (design §2), from the share capital ledger,
 * the same rows and arithmetic as the Share Capital report
 * (ReportService::shareCapital): credits less debits dated on or before the
 * day.
 *
 * - `share_capital.paid_up`: the balance as of the day (the report's
 *   closing balance).
 * - `share_capital.holders`: members whose own balance isn't zero on the
 *   day (the report's member count).
 * - `share_capital.movements`: credits, debits or their net in a range.
 *
 * Whole cooperative; money in centavos, sent as 2-decimal strings.
 */
final class ShareCapitalSource implements CdaSource
{
    public function resolve(string $key, array $params, SourceContext $context): SourceResult
    {
        return match ($key) {
            'share_capital.paid_up' => $this->paidUp($params, $context),
            'share_capital.holders' => $this->holders($params, $context),
            'share_capital.movements' => $this->movements($params, $context),
            default => throw SourceUnavailable::unknownKey($key, 'share_capital'),
        };
    }

    private function paidUp(array $params, SourceContext $context): SourceResult
    {
        $asOf = $context->date($params['as_of']);
        $row = DB::table('share_capital_ledger')->where('date', '<=', $asOf)
            ->selectRaw('COALESCE(SUM(credit), 0) as credits, COALESCE(SUM(debit), 0) as debits')->first();

        $balance = Rational::of((string) $row->credits)->subtract(Rational::of((string) $row->debits));

        return new SourceResult($balance->toFixed(2), SourceResult::labelFor('Share capital ledger', $asOf), 'money', $asOf, MembersSource::notYetEnded($asOf));
    }

    private function holders(array $params, SourceContext $context): SourceResult
    {
        $asOf = $context->date($params['as_of']);
        $count = DB::query()->fromSub(
            DB::table('share_capital_ledger')->where('date', '<=', $asOf)->groupBy('borrower_id')
                ->selectRaw('borrower_id, SUM(credit) - SUM(debit) as balance'),
            'balances',
        )->where('balance', '<>', 0)->count();

        return new SourceResult($count, SourceResult::labelFor('Share capital holders', $asOf), 'count', $asOf, MembersSource::notYetEnded($asOf));
    }

    private function movements(array $params, SourceContext $context): SourceResult
    {
        $from = $context->date($params['from']);
        $to = $context->date($params['to']);
        $row = DB::table('share_capital_ledger')->whereBetween('date', [$from, $to])
            ->selectRaw('COALESCE(SUM(credit), 0) as credits, COALESCE(SUM(debit), 0) as debits')->first();

        $credits = Rational::of((string) $row->credits);
        $debits = Rational::of((string) $row->debits);
        [$value, $what] = match ($params['side']) {
            'credit' => [$credits, 'Share capital paid in'],
            'debit' => [$debits, 'Share capital withdrawn'],
            default => [$credits->subtract($debits), 'Share capital, net movement'],
        };

        return new SourceResult($value->toFixed(2), SourceResult::labelFor($what, $to), 'money', $to, MembersSource::notYetEnded($to));
    }
}

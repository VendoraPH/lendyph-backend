<?php

namespace App\Services\Cda\Sources;

use App\Services\Cda\Reports\Rational;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Savings and time deposits for CDA reports (design §2), by the Savings GL
 * Control's subledger rule (Savings\Reports\GlControl), so the figures equal
 * that report's subledger column:
 *
 * - regular savings: every savings row's credits less debits dated on or
 *   before the day;
 * - time deposits: the principal of every placement made by the day and not
 *   settled by it.
 *
 * `kind` picks one; without it, both. Accounts count those opened by the day
 * and not closed by it (time deposits: placed and not settled); depositors
 * count their members once. Whole cooperative.
 */
final class SavingsSource implements CdaSource
{
    public function resolve(string $key, array $params, SourceContext $context): SourceResult
    {
        $asOf = $context->date($params['as_of']);
        $kind = $params['kind'] ?? null;
        $regular = $kind === null || $kind === 'regular';
        $deposits = $kind === null || $kind === 'time_deposit';
        $what = match ($kind) {
            'regular' => 'Savings deposits',
            'time_deposit' => 'Time deposits',
            default => 'Savings and time deposits',
        };

        return match ($key) {
            'savings.balance' => new SourceResult(
                Rational::of(0)
                    ->add($regular ? $this->sum(DB::table('savings_transactions')->where('date', '<=', $asOf), 'credit')->subtract($this->sum(DB::table('savings_transactions')->where('date', '<=', $asOf), 'debit')) : Rational::of(0))
                    ->add($deposits ? $this->sum($this->placements($asOf), 'principal') : Rational::of(0))
                    ->toFixed(2),
                SourceResult::labelFor($what, $asOf),
                'money',
                $asOf,
                MembersSource::notYetEnded($asOf),
            ),
            'savings.accounts' => new SourceResult(
                ($regular ? $this->openAccounts($asOf)->count() : 0) + ($deposits ? $this->placements($asOf)->count() : 0),
                SourceResult::labelFor($what.' accounts', $asOf),
                'count',
                $asOf,
                MembersSource::notYetEnded($asOf),
            ),
            'savings.depositors' => new SourceResult(
                $this->depositors($asOf, $regular, $deposits),
                SourceResult::labelFor('Depositors ('.strtolower($what).')', $asOf),
                'count',
                $asOf,
                MembersSource::notYetEnded($asOf),
            ),
            default => throw SourceUnavailable::unknownKey($key, 'savings'),
        };
    }

    private function sum(Builder $query, string $column): Rational
    {
        return Rational::of((string) ($query->sum($column) ?: 0));
    }

    private function placements(string $asOf): Builder
    {
        return DB::table('time_deposits')
            ->where('placement_date', '<=', $asOf)
            ->where(fn (Builder $q) => $q->whereNull('settled_on')->orWhere('settled_on', '>', $asOf));
    }

    private function openAccounts(string $asOf): Builder
    {
        return DB::table('savings_accounts')
            ->where('opened_on', '<=', $asOf)
            ->where(fn (Builder $q) => $q->whereNull('closed_on')->orWhere('closed_on', '>', $asOf));
    }

    private function depositors(string $asOf, bool $regular, bool $deposits): int
    {
        $members = collect();

        if ($regular) {
            $members = $members->merge($this->openAccounts($asOf)->pluck('borrower_id'));
        }

        if ($deposits) {
            $members = $members->merge($this->placements($asOf)->pluck('borrower_id'));
        }

        return $members->map(fn ($id) => (int) $id)->unique()->count();
    }
}

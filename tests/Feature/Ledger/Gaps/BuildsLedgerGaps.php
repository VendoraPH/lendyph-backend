<?php

namespace Tests\Feature\Ledger\Gaps;

use App\Models\Loan;
use App\Models\Repayment;
use App\Services\Accounting\AutomaticPoster;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Fixtures for the ledger gaps report: past events made before the chart
 * existed (so nothing posted), and the same events posted by today's rules.
 *
 * Needs PostsJournals and SetupLendyPH on the test class.
 */
trait BuildsLedgerGaps
{
    private function poster(): AutomaticPoster
    {
        return app(AutomaticPoster::class);
    }

    /** Pesos as the decimal string a `decimal:2` column stores. */
    private function pesos(int $centavos): string
    {
        return sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
    }

    /** Make `$new` a restructure of `$source`, with the split A1 stores at release when given. */
    private function restructureOf(Loan $new, Loan $source, ?array $split = null): Loan
    {
        $attributes = ['source_loan_id' => $source->id];

        if ($split !== null) {
            [$carried, $interest, $penalty, $writtenOff] = $split;
            $attributes += [
                'restructure_carried_principal' => $this->pesos($carried),
                'restructure_capitalised_interest' => $this->pesos($interest),
                'restructure_capitalised_penalty' => $this->pesos($penalty),
                'restructure_written_off_principal' => $this->pesos($writtenOff),
            ];
        }

        $new->update($attributes);

        return $new->fresh();
    }

    private function pay(Loan $loan, float $pesos = 11800): Repayment
    {
        $id = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $pesos,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');

        return Repayment::findOrFail($id);
    }

    /** @param  array<string, mixed>  $query */
    private function gaps(array $query = []): TestResponse
    {
        $suffix = $query === [] ? '' : '?'.http_build_query($query);

        return $this->getJson('/api/accounting/ledger-gaps'.$suffix);
    }

    /**
     * The rows of one kind in a response, keyed by record id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsOf(TestResponse $response, string $kind): array
    {
        return collect($response->json('data'))
            ->where('kind', $kind)
            ->keyBy('record_id')
            ->all();
    }

    /**
     * Everything a write would move in the books and the records the report reads.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $tables = ['accounting_journals', 'accounting_journal_lines', 'loans', 'repayments', 'share_capital_ledger', 'loan_adjustments', 'gcash_transactions', 'audit_logs'];
        $snapshot = [];

        foreach ($tables as $table) {
            $snapshot[$table] = [
                'rows' => DB::table($table)->count(),
                'max_id' => DB::table($table)->max('id'),
                'updated' => $table === 'audit_logs' ? null : DB::table($table)->max('updated_at'),
            ];
        }

        return $snapshot;
    }

    /**
     * Runs $read and returns every write statement it issued.
     *
     * @return list<string>
     */
    private function writesDuring(callable $read): array
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $read();

        $this->assertNotSame([], $statements, 'Nothing was read, so the check proves nothing.');

        return array_values(array_filter(
            $statements,
            static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $sql) === 1,
        ));
    }
}

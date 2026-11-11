<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * Spelled out rather than read off RetiredCustomFeesDrop and the
     * LoanProduct model: this migration has to write on its last deployment
     * exactly what it wrote on its first, whatever those classes become.
     * Polymorphic rows store the full class name; there is no morph map.
     */
    private const AUDITABLE_TYPE = 'App\Models\LoanProduct';

    /**
     * Drop the retired product "Additional Fees" (`custom_fees`), owner answer
     * of 2026-11-11. They were never charged, so only the product rows change.
     *
     * The same selection, skips and audit rows as RetiredCustomFeesDrop, whose
     * docblock explains them, written here with the query builder alone.
     * DropRetiredProductCustomFeesTest holds the two to the same outcome.
     * `php artisan loan-products:drop-retired-custom-fees --dry-run` lists what
     * this will drop on a database before it is deployed there.
     *
     * A product whose stored value isn't a list is left alone and logged. Each
     * product is re-read under a lock and dropped only when it still holds what
     * was listed, so a second run changes nothing. Each change writes one audit
     * row as a system change (no user). On a fresh database there are no
     * products yet, so this does nothing.
     *
     * The summary line is printed as well as logged, so the count shows in the
     * deploy log. Not under the test suite, which runs every migration on a
     * fresh database for each worker and would print it each time.
     */
    public function up(): void
    {
        $result = ['dropped' => [], 'skipped' => []];

        DB::table('loan_products')
            ->whereNotNull('custom_fees')
            ->select(['id', 'name', 'custom_fees'])
            ->chunkById(200, function ($products) use (&$result): void {
                foreach ($products as $product) {
                    $fees = json_decode((string) $product->custom_fees, true);
                    $entry = ['id' => (int) $product->id, 'name' => (string) $product->name];

                    if ($fees === [] || $fees === null) {
                        continue;
                    }

                    if (! is_array($fees) || ! array_is_list($fees)) {
                        $result['skipped'][] = [...$entry, 'reason' => 'its stored fees are not a list'];

                        continue;
                    }

                    if ($this->write($product, $fees)) {
                        $result['dropped'][] = [...$entry, 'fees' => $fees];

                        continue;
                    }

                    $result['skipped'][] = [...$entry, 'reason' => 'changed while the fix ran'];
                    Log::warning('A loan product\'s custom fees changed while the fix ran; it was left as it is.', $entry);
                }
            });

        $summary = sprintf(
            'retired product custom fees: %d dropped from %d product(s), %d left as they are',
            array_sum(array_map(fn (array $product): int => count($product['fees']), $result['dropped'])),
            count($result['dropped']),
            count($result['skipped']),
        );

        Log::info($summary, $result);

        if ($result['skipped'] !== []) {
            Log::warning('Loan products whose custom fees could not be read were left unchanged.', ['products' => $result['skipped']]);
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    /**
     * Nothing to undo: the fees were never charged, the audit rows keep what
     * each product held, and audit rows are never deleted.
     */
    public function down(): void {}

    /**
     * @param  list<mixed>  $fees
     */
    private function write(object $product, array $fees): bool
    {
        return DB::transaction(function () use ($product, $fees): bool {
            $stored = DB::table('loan_products')->where('id', $product->id)->lockForUpdate()->value('custom_fees');

            if ($stored === null || json_decode((string) $stored, true) !== $fees) {
                return false;
            }

            $now = now();

            DB::table('loan_products')->where('id', $product->id)->update(['custom_fees' => null, 'updated_at' => $now]);

            $names = array_map(
                fn (mixed $fee): string => is_array($fee) && is_scalar($fee['name'] ?? null) && (string) $fee['name'] !== '' ? (string) $fee['name'] : 'unnamed',
                $fees,
            );

            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'updated',
                'auditable_type' => self::AUDITABLE_TYPE,
                'auditable_id' => $product->id,
                'old_values' => json_encode(['custom_fees' => $fees], JSON_UNESCAPED_UNICODE),
                'new_values' => json_encode(['custom_fees' => null]),
                'ip_address' => null,
                'user_agent' => null,
                'description' => sprintf(
                    'System correction: Loan product %s retired Additional Fees dropped (%d %s: %s). They were never charged.',
                    json_encode((string) $product->name, JSON_UNESCAPED_UNICODE),
                    count($fees),
                    count($fees) === 1 ? 'fee' : 'fees',
                    implode(', ', $names),
                ),
                'created_at' => $now,
            ]);

            return true;
        });
    }
};

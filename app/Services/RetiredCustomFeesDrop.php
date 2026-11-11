<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Drop the retired product "Additional Fees" (`loan_products.custom_fees`),
 * owner answer of 2026-11-11.
 *
 * No release, preview, disclosure or journal ever charged them (round r7), so
 * only the product rows change: no loan, schedule or journal is touched.
 *
 * A product holding a non-empty list has it set to null. One holding null or
 * `[]` holds no fees and is passed over. One holding something that isn't a
 * list (a damaged value) is uncertain: it is left alone and listed.
 *
 * Each product is written in its own transaction: the row is read again under
 * a lock and dropped only when it still holds exactly what was listed, so a
 * product changed while the fix ran is left as it now is, listed and logged.
 * Each change writes one `updated` audit row as a system change (no user, no
 * address) with the old list and null, the row the Auditable trait would
 * write for a product edit. The update goes through the query builder, so the
 * trait writes no second row. A dropped product holds null, so a second run
 * changes nothing.
 *
 * Used by `loan-products:drop-retired-custom-fees` (with `--dry-run` to
 * preview a database). The 2026_11_11_620100 migration does the same on
 * deploy, written out with the query builder so that it depends on no
 * application class; keep them in step (DropRetiredProductCustomFeesTest
 * compares them). Afterwards `loan-products:retired-custom-fees-report` lists
 * none.
 */
class RetiredCustomFeesDrop
{
    private const CHUNK = 200;

    /**
     * Polymorphic rows store the full class name; there is no morph map.
     */
    private const AUDITABLE_TYPE = 'App\Models\LoanProduct';

    /**
     * Drop every product's retired fees, or with `$dryRun` only say which.
     *
     * @return array{dropped: list<array{id: int, name: string, fees: list<mixed>}>, skipped: list<array{id: int, name: string, reason: string}>}
     */
    public function run(bool $dryRun): array
    {
        $result = ['dropped' => [], 'skipped' => []];

        DB::table('loan_products')
            ->whereNotNull('custom_fees')
            ->select(['id', 'name', 'custom_fees'])
            ->chunkById(self::CHUNK, function ($products) use ($dryRun, &$result): void {
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

                    if ($dryRun || $this->write($product, $fees)) {
                        $result['dropped'][] = [...$entry, 'fees' => $fees];

                        continue;
                    }

                    $result['skipped'][] = [...$entry, 'reason' => 'changed while the fix ran'];
                    Log::warning('A loan product\'s custom fees changed while the fix ran; it was left as it is.', $entry);
                }
            });

        return $result;
    }

    /**
     * The one line the migration prints to the deploy log.
     *
     * @param  array{dropped: list<array{fees: list<mixed>}>, skipped: list<mixed>}  $result
     */
    public static function summary(array $result): string
    {
        return sprintf(
            'retired product custom fees: %d dropped from %d product(s), %d left as they are',
            array_sum(array_map(fn (array $product): int => count($product['fees']), $result['dropped'])),
            count($result['dropped']),
            count($result['skipped']),
        );
    }

    /**
     * Drop one product's fees and record it, when the locked row still holds
     * the fees that were listed.
     *
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
}

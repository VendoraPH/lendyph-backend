<?php

namespace App\Console\Commands;

use App\Services\Accounting\Money;
use App\Services\RetiredCustomFeesDrop;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('loan-products:drop-retired-custom-fees {--dry-run : List the products and fees that would be dropped without writing}')]
#[Description('Drop the retired custom fees (Additional Fees) stored on loan products. They were never charged, so no loan, schedule or journal changes')]
class DropRetiredProductCustomFees extends Command
{
    /**
     * The same logic the 2026_11_11_620100 migration runs on deploy, so a dry
     * run on a database shows what it will change there.
     */
    public function handle(RetiredCustomFeesDrop $drop): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run. Nothing is written.');
        }

        $result = $drop->run($dryRun);
        $fees = 0;

        foreach ($result['dropped'] as $product) {
            $fees += count($product['fees']);
            $this->line(sprintf(
                '  %s (id %d): %s %d fee(s): %s',
                $product['name'],
                $product['id'],
                $dryRun ? 'would drop' : 'dropped',
                count($product['fees']),
                implode('; ', array_map(fn (mixed $fee): string => $this->fee($fee), $product['fees'])),
            ));
        }

        foreach ($result['skipped'] as $product) {
            $this->line("  {$product['name']} (id {$product['id']}) left as it is: {$product['reason']}");
        }

        $this->info(sprintf(
            '%s %d fee(s) from %d product(s); %d left as they are.',
            $dryRun ? 'Would drop' : 'Dropped',
            $fees,
            count($result['dropped']),
            count($result['skipped']),
        ));

        return self::SUCCESS;
    }

    /**
     * "Handling (fixed, ₱1,500.00)", "Rush (percentage, 0.3333%)".
     */
    private function fee(mixed $fee): string
    {
        $fee = is_array($fee) ? $fee : [];
        $name = $this->text($fee['name'] ?? null, 'unnamed');
        $type = $this->text($fee['type'] ?? null, 'no type');
        $value = $fee['value'] ?? null;

        if (! is_numeric($value)) {
            return "{$name} ({$type}, no value)";
        }

        if ($type === 'fixed') {
            return "{$name} ({$type}, {$this->pesos($value)})";
        }

        $parts = [$this->number($value).'%'.(! empty($fee['per_month']) ? ' per month' : '')];

        foreach (['min_amount' => 'min', 'max_amount' => 'max'] as $key => $label) {
            if (is_numeric($fee[$key] ?? null)) {
                $parts[] = "{$label} {$this->pesos($fee[$key])}";
            }
        }

        return "{$name} ({$type}, ".implode(', ', $parts).')';
    }

    private function pesos(mixed $value): string
    {
        return Money::format(Money::toCentavos(is_string($value) ? $value : (float) $value) ?? 0);
    }

    /**
     * Up to four decimals, as rates are stored, without trailing zeros.
     */
    private function number(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    private function text(mixed $value, string $missing): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : $missing;
    }
}

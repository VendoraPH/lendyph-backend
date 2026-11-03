<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Requests\LoanProduct\UpdateLoanProductRequest;
use App\Models\LoanProduct;
use App\Services\Accounting\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The loan products still holding retired custom fees ("Additional fees").
 *
 * Product custom fees were never charged by any release, preview, disclosure
 * or journal, and a save can no longer change them
 * ({@see UpdateLoanProductRequest::CUSTOM_FEES_RETIRED}). Fees are set up in
 * Settings → Fees, scoped to the product. This lists what each product still
 * holds, so someone can recreate the ones they need there.
 *
 * ## It only ever reads
 *
 * Every statement is a SELECT: no transaction is opened and nothing is
 * written, so it is safe to run on production as it is.
 *
 * One row per fee: the product id and name, then the fee's name, type, value
 * (pesos for a fixed fee, a percentage otherwise, with a formula's per-month
 * flag and bounds) and conditions. Products are read in chunks by id.
 */
#[Signature('loan-products:retired-custom-fees-report')]
#[Description('List loan products still holding retired custom fees (Additional fees), so they can be recreated in Settings → Fees. Read only')]
class ReportRetiredProductCustomFees extends Command
{
    private const CHUNK = 200;

    /**
     * How each catalog fee condition reads, by key.
     *
     * @var array<string, string>
     */
    private const CONDITION_LABELS = [
        'term_days_gt' => 'term over %s days',
        'term_days_lt' => 'term under %s days',
        'term_days_eq' => 'term of %s days',
        'loan_amount_gt' => 'loan amount over %s',
        'loan_amount_lt' => 'loan amount under %s',
        'loan_amount_eq' => 'loan amount of %s',
    ];

    /** @var list<list<string>> */
    private array $rows = [];

    private int $products = 0;

    public function handle(): int
    {
        $this->info('Read only. Nothing is written.');
        $this->newLine();

        LoanProduct::query()
            ->whereNotNull('custom_fees')
            ->select(['id', 'name', 'custom_fees'])
            ->chunkById(self::CHUNK, function (EloquentCollection $products): void {
                foreach ($products as $product) {
                    $this->list($product);
                }
            });

        $this->report();

        return self::SUCCESS;
    }

    private function list(LoanProduct $product): void
    {
        $fees = $product->custom_fees;

        if (! is_array($fees) || $fees === []) {
            return;
        }

        $this->products++;

        foreach ($fees as $fee) {
            $fee = is_array($fee) ? $fee : [];

            $this->rows[] = [
                (string) $product->id,
                (string) $product->name,
                $this->text($fee['name'] ?? null),
                $this->text($fee['type'] ?? null),
                $this->value($fee),
                $this->conditions($fee['conditions'] ?? null),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $fee
     */
    private function value(array $fee): string
    {
        $value = $fee['value'] ?? null;

        if (! is_numeric($value)) {
            return '—';
        }

        if (($fee['type'] ?? null) === 'fixed') {
            return $this->pesos($value);
        }

        $parts = [$this->number($value).'%'.(! empty($fee['per_month']) ? ' per month' : '')];

        foreach (['min_amount' => 'min', 'max_amount' => 'max'] as $key => $label) {
            if (is_numeric($fee[$key] ?? null)) {
                $parts[] = "{$label} {$this->pesos($fee[$key])}";
            }
        }

        return implode(', ', $parts);
    }

    private function conditions(mixed $conditions): string
    {
        if (! is_array($conditions)) {
            return '—';
        }

        $parts = [];

        foreach ($conditions as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $label = self::CONDITION_LABELS[$key] ?? null;

            if ($label === null || ! is_numeric($value)) {
                $parts[] = "{$key}: ".json_encode($value, JSON_UNESCAPED_UNICODE);

                continue;
            }

            $parts[] = sprintf($label, str_starts_with((string) $key, 'loan_amount_') ? $this->pesos($value) : $this->number($value));
        }

        return $parts === [] ? '—' : implode(', ', $parts);
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

    private function text(mixed $value): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : '—';
    }

    private function report(): void
    {
        $this->line('Loan products holding retired custom fees');

        if ($this->rows === []) {
            $this->line('  None.');
        } else {
            $this->table(['Product ID', 'Product', 'Fee', 'Type', 'Value', 'Conditions'], $this->rows);
        }

        $this->newLine();
        $fees = count($this->rows);
        $this->line(sprintf(
            'Products holding retired custom fees: %s %s, %s %s',
            number_format($this->products),
            $this->products === 1 ? 'product' : 'products',
            number_format($fees),
            $fees === 1 ? 'fee' : 'fees',
        ));
        $this->line('Recreate any you need in Settings → Fees, scoped to the product.');
    }
}

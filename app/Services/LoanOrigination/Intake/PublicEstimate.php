<?php

namespace App\Services\LoanOrigination\Intake;

use App\Models\LoanProduct;
use App\Services\LoanService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The applicant's loan estimate (origination §2, decision 5): the loan form's
 * own preview (LoanService::formPreview()), so it equals what staff see for
 * the same loan. The product's standard rate, its own fees and the release
 * date taken as today in Manila. Writes nothing.
 *
 * - `total_repayment` is the contract total, principal plus interest; the
 *   charges, net proceeds and any share capital build-up are separate (the
 *   Total Payable rule).
 * - `installment` is the largest single instalment (principal plus
 *   interest), so the applicant never sees less than a period asks.
 */
final class PublicEstimate
{
    public function __construct(private readonly LoanService $loans) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function estimate(LoanProduct $product, BigDecimal $amount, int $term, string $frequency): array
    {
        $this->assertWithinProduct($product, $amount, $term);

        $today = Carbon::now('Asia/Manila')->toDateString();
        $preview = $this->loans->formPreview([
            'loan_product_id' => $product->id,
            'principal_amount' => (string) $amount,
            'interest_rate' => (string) $product->interest_rate,
            'term' => $term,
            'frequency' => $frequency,
            'start_date' => $today,
        ]);

        $schedule = $preview['amortization'];
        $deductions = $preview['deductions'];

        if ($schedule === null) {
            throw ValidationException::withMessages(['loan_product_id' => 'This loan product can\'t be estimated online. Please contact the office.']);
        }

        if ($deductions !== null && $deductions['error'] !== null) {
            throw ValidationException::withMessages(['amount' => $deductions['error']]);
        }

        $principalCentavos = (int) (string) $amount->multipliedBy(100)->toScale(0, RoundingMode::HalfUp);
        $interestCentavos = (int) round(((float) $schedule['totals']['interest_due']) * 100);
        $charges = [...($deductions['items'] ?? []), ...($deductions['configured_fees'] ?? [])];

        return [
            'installment' => collect($schedule['rows'])->max(fn (array $row) => $row['total_due']) ?? 0,
            'installments_count' => count($schedule['rows']),
            'frequency' => $frequency,
            'total_interest' => $schedule['totals']['interest_due'],
            'total_charges' => $deductions['total_deductions'] ?? 0,
            'total_repayment' => ($principalCentavos + $interestCentavos) / 100,
            'net_proceeds' => $deductions['net_proceeds'] ?? null,
            'charges' => array_map(fn (array $item) => ['name' => (string) $item['name'], 'amount' => $item['amount']], $charges),
            'schedule' => array_map(fn (array $row) => [
                'number' => $row['period_number'],
                'due_date' => $row['due_date'],
                'principal' => $row['principal_due'],
                'interest' => $row['interest_due'],
                'amount' => $row['total_due'],
            ], $schedule['rows']),
            'assumptions' => $this->assumptions($product, $today),
        ];
    }

    /** @throws ValidationException */
    private function assertWithinProduct(LoanProduct $product, BigDecimal $amount, int $term): void
    {
        $min = $product->min_amount === null ? null : BigDecimal::of((string) $product->min_amount);
        $max = $product->max_amount === null ? null : BigDecimal::of((string) $product->max_amount);
        $errors = [];

        if ($amount->isLessThanOrEqualTo(0) || ($min !== null && $amount->isLessThan($min)) || ($max !== null && $amount->isGreaterThan($max))) {
            $errors['amount'] = match (true) {
                $min !== null && $max !== null => 'Enter an amount from '.self::pesos($min).' to '.self::pesos($max).'.',
                $min !== null => 'Enter at least '.self::pesos($min).'.',
                $max !== null => 'Enter at most '.self::pesos($max).'.',
                default => 'Enter the amount you want to borrow.',
            };
        }

        if (($product->min_term !== null && $term < $product->min_term) || ($product->max_term !== null && $term > $product->max_term)) {
            $unit = $product->term_unit?->value ?? 'months';
            $errors['term'] = "Choose a term from {$product->min_term} to {$product->max_term} {$unit}.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return list<string> */
    private function assumptions(LoanProduct $product, string $today): array
    {
        $lines = [
            'Worked out as if the loan were released today ('.Carbon::parse($today)->format('F j, Y').'). Your real schedule starts on the day the loan is released.',
            'Uses the product\'s standard interest rate and its own charges.',
            'Charges are taken from the amount released, so you receive the net proceeds.',
        ];

        if ($product->scb_required) {
            $lines[] = 'A share capital build-up may be added to each payment. It isn\'t included here.';
        }

        $lines[] = 'This is an estimate, not an offer. The office confirms the final figures.';

        return $lines;
    }

    private static function pesos(BigDecimal $amount): string
    {
        return '₱'.number_format((float) (string) $amount->toScale(2, RoundingMode::HalfUp), 2);
    }
}

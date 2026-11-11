<?php

namespace App\Services\Cda\Sources;

use App\Http\Resources\Cda\CdaPresenter;
use App\Services\Cda\Classification\LoanClassification;

/**
 * Loan classification and provisioning (CDA design §7): `loans.classification`
 * (a table, one row per bucket), `loans.provision_required`,
 * `loans.allowance_booked` (the 1200 balance) and `loans.provision_gap`
 * (required − booked).
 *
 * They read the year-end capture; without one they are computed live from
 * current balances and marked provisional. `meta` keeps the basis and the
 * capture time, so the report shows where the figure came from.
 */
final class ClassificationSource implements CdaSource
{
    public function __construct(private readonly LoanClassification $classification) {}

    public function resolve(string $key, array $params, SourceContext $context): SourceResult
    {
        $figures = $context->remember('classification:'.$context->period->id, fn (): SourceResult => new SourceResult(
            $this->classification->for($context->period),
            'Loan classification',
            'any',
        ))->value;

        $meta = [
            'basis' => $figures['basis'],
            'captured_at' => $figures['captured_at']?->toIso8601String(),
            'warning' => $figures['warning'],
        ];
        $label = fn (string $what) => SourceResult::labelFor($figures['basis'] === LoanClassification::BASIS_LIVE ? "{$what} (current balances)" : $what, $figures['as_of']);
        $money = fn (string $what, int $centavos) => new SourceResult(CdaPresenter::money($centavos), $label($what), 'money', $figures['as_of'], $figures['provisional'], $meta);

        return match ($key) {
            'loans.classification' => new SourceResult(
                array_map(fn (array $bucket): array => [
                    'bucket' => $bucket['bucket'],
                    'label' => $bucket['label'],
                    'dpd_range' => $bucket['dpd_range'],
                    'loan_count' => $bucket['loan_count'],
                    'outstanding_principal' => CdaPresenter::money($bucket['principal']),
                    'rate_percent' => $bucket['rate_percent'],
                    'required' => CdaPresenter::money($bucket['required']),
                ], $figures['buckets']),
                $label('Loan classification'),
                'table',
                $figures['as_of'],
                $figures['provisional'],
                $meta,
            ),
            'loans.provision_required' => $money('Required allowance', $figures['totals']['required']),
            'loans.allowance_booked' => $money('Allowance booked (1200)', $figures['totals']['booked']),
            'loans.provision_gap' => $money('Provision gap', $figures['totals']['gap']),
            default => throw SourceUnavailable::unknownKey($key, 'classification'),
        };
    }
}

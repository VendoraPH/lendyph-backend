<?php

namespace App\Services\Cda\Reports;

use App\Models\CdaOfficer;
use App\Models\CdaRegulatoryProfile;
use App\Models\CdaReport;
use App\Services\Accounting\BalanceSheetBuilder;
use App\Services\Accounting\TrialBalanceBuilder;
use App\Services\Cda\Registers\TrainingCompliance;
use App\Services\Cda\Sources\AccountingSource;
use App\Services\Cda\Sources\SourceContext;
use App\Services\Cda\Sources\SourceRegistry;
use App\Services\Cda\Sources\SourceResult;
use App\Services\Cda\Sources\SourceUnavailable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The named checks a template rule may run (TemplateDefinitionValidator::
 * CHECKS, design §4). Each answers null when it passes, or the problem in
 * words, with counts. A template never carries code: only these names.
 */
final class NamedChecks
{
    /** The profile fields a CDA filing needs, in words. */
    public const PROFILE_REQUIRED = [
        'cda_registration_no' => 'CDA registration number',
        'cda_registration_date' => 'registration date',
        'cda_region' => 'CDA region',
        'cooperative_type' => 'cooperative type',
        'cooperative_category' => 'category',
        'asset_class' => 'asset size',
        'tin' => 'TIN',
        'address_line' => 'address',
        'address_city' => 'city or municipality',
        'address_province' => 'province',
    ];

    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly BalanceSheetBuilder $balanceSheet,
        private readonly TrialBalanceBuilder $trialBalance,
    ) {}

    /** @param  array<string, mixed>  $params */
    public function run(string $check, array $params, SourceContext $context, ReportDefinition $definition): ?string
    {
        $year = $context->year();
        $periodEnd = $context->date('period_end');

        return match ($check) {
            'accounting.year_closed' => AccountingSource::yearClosed($year) ? null
                : "The {$year} accounting year isn't closed yet, so the figures from the books are provisional. Close the year in Accounting first.",
            'fs.balanced' => $this->sheet($context, $periodEnd)['is_balanced'] ? null
                : 'The balance sheet as of '.self::day($periodEnd).' doesn\'t balance: assets differ from liabilities and equity by '.Rational::fromCentavos((int) $this->sheet($context, $periodEnd)['difference'])->abs()->toFixed(2).'.',
            'fs.tb_matches_fs' => $this->trialBalanceMatches($context, $periodEnd),
            'members.missing_field' => $this->membersMissing($params, $context),
            'officers.training_incomplete' => $this->trainingIncomplete($context),
            'officers.required_positions' => $this->requiredPositions($context, $periodEnd),
            'profile.complete' => $this->profileComplete(),
            'period.cte_valid' => CdaRegulatoryProfile::current()->cteCovers($context->date('period_start')) ? null
                : "The cooperative has no valid Certificate of Tax Exemption covering {$year}. Record it in CDA › Settings.",
            'classification.captured' => $context->period->year_end_captured_at !== null ? null
                : "The year-end loan capture hasn't run for {$year}, so loan classification is computed from current balances.",
            'template.verified' => $definition->isVerified() ? null
                : 'This form follows the CDA template as understood in v1 and is still to be checked against the official form.',
            'pesos.rules_verified' => $this->pesosVerified($context),
            default => throw new InvalidArgumentException("Unknown check \"{$check}\"."),
        };
    }

    private function sheet(SourceContext $context, string $asOf): array
    {
        return $context->remember("fs.sheet|{$asOf}", fn () => new SourceResult($this->balanceSheet->build($asOf), '', 'any'))->value;
    }

    private function trialBalanceMatches(SourceContext $context, string $asOf): ?string
    {
        $trial = $this->trialBalance->build($asOf);

        if (! $trial['is_balanced']) {
            return 'The trial balance as of '.self::day($asOf).' doesn\'t balance: debits and credits differ by '.Rational::fromCentavos(abs((int) $trial['difference']))->toFixed(2).'.';
        }

        $sheet = $this->sheet($context, $asOf);

        return $sheet['is_balanced'] ? null
            : 'The balance sheet built from the trial balance as of '.self::day($asOf).' doesn\'t balance.';
    }

    private function membersMissing(array $params, SourceContext $context): ?string
    {
        $field = (string) ($params['field'] ?? 'membership_date');
        $count = (int) $this->sources->resolve('members.missing', ['field' => $field], $context)->value;
        $what = ['membership_date' => 'membership date', 'membership_number' => 'membership number', 'gender' => 'sex recorded', 'birthdate' => 'birthdate', 'member_type' => 'member type'][$field] ?? $field;

        return $count === 0 ? null : ($count === 1 ? "1 member has no {$what}." : "{$count} members have no {$what}.");
    }

    /**
     * Officers serving in the year whose position's required trainings aren't
     * all met, by C3's TrainingCompliance (the same rule the officers
     * register and its source show).
     */
    private function trainingIncomplete(SourceContext $context): ?string
    {
        if (! class_exists(TrainingCompliance::class)) {
            return 'Officer trainings can\'t be checked until the officers register is available.';
        }

        $from = $context->date('period_start');
        $to = $context->date('period_end');
        $compliance = app(TrainingCompliance::class);
        $incomplete = CdaOfficer::query()->servingBetween($from, $to)->where('status', 'active')->with('trainings')->get()
            ->filter(fn (CdaOfficer $officer) => $compliance->evaluate($officer, $from, $to)['status'] === TrainingCompliance::INCOMPLETE)
            ->count();

        return $incomplete === 0 ? null : ($incomplete === 1 ? '1 officer hasn\'t completed the required trainings.' : "{$incomplete} officers haven't completed the required trainings.");
    }

    /** The officers a filing names (chairperson, general manager, secretary, treasurer), through `officers.current`. */
    private function requiredPositions(SourceContext $context, string $asOf): ?string
    {
        $missing = [];

        try {
            foreach (CdaOfficer::PROFILE_POSITIONS as $position) {
                if (! FieldValue::filled($this->sources->resolve('officers.current', ['position' => $position, 'as_of' => $asOf], $context)->value)) {
                    $missing[] = str_replace('_', ' ', $position);
                }
            }
        } catch (SourceUnavailable) {
            return 'The officers can\'t be checked until the officers register is available.';
        }

        return $missing === [] ? null : 'No '.self::list($missing).' is recorded as serving on '.self::day($asOf).'.';
    }

    private function profileComplete(): ?string
    {
        $profile = CdaRegulatoryProfile::current();
        $missing = [];

        foreach (self::PROFILE_REQUIRED as $field => $words) {
            if (! FieldValue::filled($profile->{$field})) {
                $missing[] = $words;
            }
        }

        return $missing === [] ? null : 'The regulatory profile has no '.self::list($missing).'.';
    }

    private function pesosVerified(SourceContext $context): ?string
    {
        $pesos = CdaReport::query()->with('template')->where('period_id', $context->period->id)->where('report_type', 'pesos')->first();

        return ($pesos?->template?->isVerified() ?? false) ? null
            : 'The PESOS indicators are placeholders until checked against the official MC 2025-12 tool.';
    }

    /** @param  list<string>  $items */
    private static function list(array $items): string
    {
        if (count($items) < 2) {
            return implode('', $items);
        }

        $last = array_pop($items);

        return implode(', ', $items).' or '.$last;
    }

    private static function day(string $date): string
    {
        return Carbon::parse($date)->format('M j, Y');
    }
}

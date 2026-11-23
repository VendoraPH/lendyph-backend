<?php

namespace App\Services\LoanOrigination\Assessment;

use App\Models\BrandingSetting;
use App\Models\OriginationApplication;
use App\Models\OriginationAssessment;
use App\Models\User;
use App\Services\Accounting\Money;
use App\Services\Cda\Exports\ExportContent;
use App\Services\Cda\Exports\PdfRenderer;
use App\Services\CreditScoring\Centavos;
use Carbon\CarbonImmutable;

/**
 * An assessment revision as a PDF, made on the server through the CDA
 * exports' locked-down dompdf renderer (no remote fetches, no scripts). It
 * prints what the screen shows the downloader: the request, the figures, the
 * limits and the result in words, ₱ with two decimals, with the figures that
 * count a linked member's loans left out ("—") for a downloader who may not
 * see them (MemberLoanFigures). It names the applicant and the reference
 * only: no contact details, address or draft.
 */
final class AssessmentPdf
{
    public const MARK = 'Online application assessment · internal';

    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly MemberLoanFigures $memberLoans,
    ) {}

    public function render(OriginationAssessment $assessment, OriginationApplication $application, User $user, string $documentStatus): string
    {
        return $this->pdf->render($this->content($assessment, $application, $user, $documentStatus));
    }

    public function content(OriginationAssessment $assessment, OriginationApplication $application, User $user, string $documentStatus): ExportContent
    {
        $assessment->loadMissing(['ruleRevision', 'creator', 'loanProduct']);
        $hidden = $this->memberLoans->hiddenFrom($user, $assessment);
        $figures = $hidden ? MemberLoanFigures::withheld($assessment->figures) : $assessment->figures;
        $explanation = $hidden ? CapacityAssessor::explanationWithoutFigures($assessment) : (string) $assessment->explanation;
        $limits = $assessment->thresholds;
        $generatedAt = CarbonImmutable::now('Asia/Manila')->format('M j, Y g:i A');
        $by = trim((string) $user->full_name) !== '' ? (string) $user->full_name : (string) $user->username;
        $title = 'Capacity Assessment';
        $period = ($application->reference_number ?? 'Application #'.$application->id).' · version '.$assessment->version;
        $field = fn (string $label, ?string $display) => ['label' => $label, 'display' => $display ?? '—'];
        $money = fn (?string $pesos) => $pesos === null ? null : Money::format(Centavos::of(ltrim($pesos, '-')) * (str_starts_with($pesos, '-') ? -1 : 1));
        $check = fn (?bool $passed) => $passed === null ? 'Not worked out' : ($passed ? 'Passes' : 'Fails');

        $sections = [
            ['key' => 'application', 'title' => 'Application', 'kind' => 'fields', 'fields' => [
                $field('Reference', $application->reference_number),
                $field('Applicant', $application->applicant_name),
                $field('Loan product', $assessment->loanProduct?->name),
                $field('Amount requested', $money((string) $assessment->requested_amount)),
                $field('Term', (string) $assessment->requested_term),
                $field('Payment schedule', ucfirst(str_replace('_', ' ', $assessment->frequency))),
                $field('Documents', $documentStatus),
            ]],
            ['key' => 'figures', 'title' => 'Monthly figures', 'kind' => 'fields', 'fields' => [
                $field('Declared income', $money($figures['declared_monthly_income'] ?? null)),
                $field('Verified income', $money($figures['verified_monthly_income'] ?? null)),
                $field('Eligible income', $money($figures['eligible_monthly_income'] ?? null)),
                $field('Expenses', $money($figures['monthly_expenses'] ?? null)),
                $field('Debt payments elsewhere', $money($figures['outside_debt_payments'] ?? null)),
                $field('Debt payments on Lendy loans', $money($figures['lendy_debt_payments'] ?? null)),
                $field('Disposable income', $money($figures['disposable_income'] ?? null)),
                $field('Proposed instalment', $money($figures['proposed_monthly_installment'] ?? null)),
                $field('Instalment taken as', $assessment->instalment_basis),
            ]],
            ['key' => 'limits', 'title' => 'Limits', 'kind' => 'fields', 'fields' => [
                $field('Debt-to-income ratio', ($figures['dti_ratio'] ?? null) === null ? null : "{$figures['dti_ratio']}% (at most {$limits['max_dti']}%) · ".$check($assessment->checks['dti'] ?? null)),
                $field('Coverage ratio', ($figures['coverage_ratio'] ?? null) === null ? null : "{$figures['coverage_ratio']} (at least {$limits['min_coverage']}) · ".$check($assessment->checks['coverage'] ?? null)),
                $field('Remaining income', ($figures['remaining_income'] ?? null) === null ? null : $money($figures['remaining_income']).' (at least '.$money($limits['min_remaining_income']).') · '.$check($assessment->checks['remaining_income'] ?? null)),
                $field('Rules', 'Revision '.$assessment->ruleRevision->version),
            ]],
            ['key' => 'result', 'title' => 'Result', 'kind' => 'fields', 'fields' => [
                $field('Result', OriginationAssessment::RESULT_LABELS[$assessment->result].($assessment->provisional ? ' (provisional)' : '')),
                $field('Explanation', $explanation),
                $field('Missing information', $assessment->missing_information === [] ? 'None' : implode(' ', $assessment->missing_information)),
                $field('Conflicts', $assessment->conflicts === [] ? 'None' : implode(' ', $assessment->conflicts)),
                $field('Officer\'s findings', $assessment->officer_findings),
                $field('Recommendations', $assessment->recommendations),
                $field('Assessed by', $assessment->creator?->full_name),
                $field('Assessed on', $assessment->created_at === null ? null : CarbonImmutable::parse($assessment->created_at, 'Asia/Manila')->format('M j, Y g:i A')),
            ]],
        ];

        return new ExportContent(
            reportId: 0,
            reportType: 'origination_assessment',
            title: $title,
            periodLabel: $period,
            coopName: $this->coopName(),
            coopAddress: null,
            registrationNo: null,
            statusLabel: self::MARK,
            stageLabel: null,
            isDraft: false,
            snapshotId: null,
            snapshotVersion: null,
            checksum: null,
            templateId: null,
            templateVersion: 'Assessment rules revision '.$assessment->ruleRevision->version,
            excelTemplateDocumentId: null,
            generatedAt: $generatedAt,
            generatedBy: $by,
            orientation: 'portrait',
            sections: $sections,
            values: [],
            types: [],
            profile: [],
            excel: [],
            footerText: "{$title} · {$period} · Generated {$generatedAt} (Manila) by {$by} · ".self::MARK,
        );
    }

    private function coopName(): string
    {
        $name = BrandingSetting::query()->first()?->organization_name;

        return filled($name) ? (string) $name : (string) config('app.name');
    }
}

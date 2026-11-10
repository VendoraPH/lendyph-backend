<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\AccountingAccount;
use App\Models\AccountingYearClose;
use App\Models\Borrower;
use App\Models\BrandingSetting;
use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Models\CdaReportTemplate;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\PeriodOpener;
use App\Services\Cda\Reports\ReportEngine;
use App\Services\Cda\TemplateDefinitionValidator;
use Tests\Feature\Cda\Foundation\CdaTestHelpers;

/**
 * Shared set-up for the C2 report tests: a small template that exercises
 * sources, a calculation, a manual field and rules; a period opened with it;
 * members with membership dates; and users holding exact permissions.
 */
trait CdaReportTestHelpers
{
    use CdaTestHelpers;

    /** A compact CAPR-shaped template: sourced, calculated and manual fields. */
    protected function testDefinition(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => 1,
            'meta' => ['verified' => false, 'source_doc' => 'Test form'],
            'parts' => ['annual'],
            'sections' => [
                ['key' => 'general', 'title' => 'General', 'kind' => 'fields', 'fields' => [
                    ['key' => 'cooperative_name', 'label' => 'Cooperative name', 'type' => 'text', 'required' => true, 'source' => ['key' => 'coop.name']],
                    ['key' => 'registration_no', 'label' => 'CDA registration number', 'type' => 'text', 'source' => ['key' => 'profile.field', 'params' => ['name' => 'cda_registration_no']], 'overridable' => true],
                ]],
                ['key' => 'membership', 'title' => 'Membership', 'kind' => 'fields', 'fields' => [
                    ['key' => 'members_total', 'label' => 'Total members', 'type' => 'number', 'decimals' => 0, 'required' => true, 'source' => ['key' => 'members.count']],
                    ['key' => 'members_male', 'label' => 'Male', 'type' => 'number', 'decimals' => 0, 'source' => ['key' => 'members.count', 'params' => ['gender' => 'male']]],
                    ['key' => 'members_female', 'label' => 'Female', 'type' => 'number', 'decimals' => 0, 'source' => ['key' => 'members.count', 'params' => ['gender' => 'female']]],
                    ['key' => 'members_unspecified', 'label' => 'Sex not recorded', 'type' => 'number', 'decimals' => 0, 'calc' => ['op' => 'sub', 'args' => ['members_total', ['op' => 'add', 'args' => ['members_male', 'members_female']]]]],
                ]],
                ['key' => 'employment', 'title' => 'Employment', 'kind' => 'fields', 'fields' => [
                    ['key' => 'employees_regular', 'label' => 'Regular employees', 'type' => 'number', 'decimals' => 0, 'required' => true],
                    ['key' => 'payroll', 'label' => 'Annual payroll', 'type' => 'money'],
                    ['key' => 'payroll_per_employee', 'label' => 'Payroll per employee', 'type' => 'money', 'calc' => ['op' => 'div', 'args' => ['payroll', 'employees_regular']]],
                ]],
            ],
            'rules' => [
                ['id' => 'gender_sum', 'type' => 'sum_equals', 'parts' => ['members_male', 'members_female'], 'total' => 'members_total', 'severity' => 'warning', 'category' => 'member_data', 'component' => 'sources', 'fix_href' => '/borrowers', 'message' => 'Some members have no sex recorded.'],
                ['id' => 'template_verified', 'type' => 'check', 'check' => 'template.verified', 'severity' => 'info', 'category' => 'invalid_data', 'component' => 'fields'],
            ],
        ], $overrides);
    }

    /** An active template for the type, newer than the seeded one, so a period opened after it uses it. */
    protected function activeTemplate(string $type, array $definition, string $version = '2026.9'): CdaReportTemplate
    {
        $errors = app(TemplateDefinitionValidator::class)->validate($definition, $type);
        $this->assertSame([], $errors, 'The test template is not valid: '.json_encode($errors));

        return CdaReportTemplate::query()->create([
            'report_type' => $type,
            'version' => $version,
            'name' => 'Test '.$type,
            'effective_from' => '2026-01-01',
            'cooperative_types' => [],
            'asset_classes' => [],
            'definition' => $definition,
            'status' => CdaReportTemplate::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
    }

    protected function openYear(int $year = 2026): CdaReportingPeriod
    {
        return app(PeriodOpener::class)->open($year, $this->admin);
    }

    protected function report(CdaReportingPeriod $period, string $type, string $part = 'annual'): CdaReport
    {
        return CdaReport::query()->where('period_id', $period->id)->where('report_type', $type)->where('part', $part)->firstOrFail();
    }

    /** The test template on CAPR, a period, and the CAPR report refreshed once. */
    protected function caprWithTestTemplate(array $overrides = []): CdaReport
    {
        $this->activeTemplate('capr', $this->testDefinition($overrides));
        $report = $this->report($this->openYear(), 'capr');
        app(ReportEngine::class)->refresh($report);

        return $report->refresh();
    }

    protected function member(array $attributes = []): Borrower
    {
        return Borrower::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'membership_date' => '2026-02-01',
            'gender' => 'female',
            'birthdate' => '1970-01-01',
        ], $attributes));
    }

    /** The accounting year closed in the books (the state YearEndCloser leaves). */
    protected function closeBooks(int $year): AccountingYearClose
    {
        if (! AccountingAccount::query()->exists()) {
            app(ChartOfAccountsSeeder::class)->seed($this->admin->id);
        }

        return AccountingYearClose::query()->create([
            'year' => $year,
            'closed_year' => $year,
            'status' => AccountingYearClose::STATUS_CLOSED,
            'net_surplus_account_id' => AccountingAccount::query()->where('type', 'equity')->where('is_group', false)->orderBy('code')->value('id'),
            'net_surplus' => 0,
            'periods_closed' => [],
            'closed_by' => $this->admin->id,
            'closed_at' => now(),
        ]);
    }

    protected function brandAs(string $name): void
    {
        BrandingSetting::current()->update(['organization_name' => $name]);
    }

    /** Every CDA permission except the restricted views, plus view_all. */
    protected function cdaStaff(array $extra = []): User
    {
        return $this->userWith(array_merge(
            ['branches:view_all', CdaAccess::VIEW, CdaAccess::PREPARE, CdaAccess::REVIEW, CdaAccess::APPROVE, CdaAccess::FINALIZE, CdaAccess::REOPEN, CdaAccess::SUBMIT],
            $extra,
        ));
    }
}

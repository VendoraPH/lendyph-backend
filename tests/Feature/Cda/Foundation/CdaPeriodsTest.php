<?php

namespace Tests\Feature\Cda\Foundation;

use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Models\CdaReportTemplate;
use App\Models\CdaSetting;
use App\Services\Cda\Deadlines;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Reporting periods and their report instances (CDA design §3, §10): one
 * period per calendar year (a second answers 409), eleven instances (H1 and
 * H2 mediation as separate rows; ATIR and ABR applicable only with a valid
 * CTE), each keeping the template selected when it was created. Deadlines:
 * 120 days after Dec 31 (Apr 29 in a leap year), a half's end + 30 days for
 * mediation; the report override, else the period extension, else the
 * default; `days_remaining` in Manila days.
 */
class CdaPeriodsTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-04-20 23:30:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_cooperative_with_a_valid_cte_gets_eleven_applicable_instances(): void
    {
        $this->withCte('valid', '2028-12-31');

        $response = $this->postJson('/api/cda/periods', ['year' => 2026])->assertCreated()
            ->assertJsonPath('data.year', 2026)
            ->assertJsonPath('data.period_start', '2026-01-01')
            ->assertJsonPath('data.period_end', '2026-12-31')
            ->assertJsonPath('data.default_deadline', '2027-04-30')
            ->assertJsonPath('data.effective_deadline', '2027-04-30')
            ->assertJsonPath('data.days_remaining', 10);

        $rows = collect($response->json('data.reports'));
        $this->assertCount(11, $rows);
        $this->assertSame(11, CdaReport::query()->count());
        $this->assertTrue($rows->every(fn (array $row) => $row['applicable'] === true));
        $this->assertTrue($rows->every(fn (array $row) => $row['status'] === 'draft'));
        $this->assertSame(
            ['abr:annual', 'atir:annual', 'capr:annual', 'financial_statements:annual', 'mediation:h1', 'mediation:h2', 'officers_trainings:annual', 'performance_audit:annual', 'pesos:annual', 'social_audit:annual', 'sworn_statement:annual'],
            $rows->map(fn (array $row) => $row['report_type'].':'.$row['part'])->sort()->values()->all(),
        );

        $byKey = $rows->keyBy(fn (array $row) => $row['report_type'].':'.$row['part']);
        $this->assertSame('2026-07-30', $byKey['mediation:h1']['default_due_on']);
        $this->assertSame('2027-01-30', $byKey['mediation:h2']['default_due_on']);
        $this->assertSame('2027-04-30', $byKey['capr:annual']['effective_deadline']);
        $this->assertSame(-264, $byKey['mediation:h1']['days_remaining']);
        $this->assertSame('First half (January–June)', $byKey['mediation:h1']['title_part']);
        $this->assertSame('Annual Benefits Report (ABR)', $byKey['abr:annual']['title']);
        $this->assertNotNull($byKey['capr:annual']['template']['id']);
        $this->assertFalse($byKey['capr:annual']['template']['verified']);
    }

    public function test_atir_and_abr_are_not_applicable_without_a_valid_cte(): void
    {
        $this->withCte('valid', '2025-12-31');

        $rows = collect($this->postJson('/api/cda/periods', ['year' => 2026])->assertCreated()->json('data.reports'))
            ->keyBy('report_type');

        $this->assertCount(10, $rows);
        $this->assertFalse($rows['atir']['applicable']);
        $this->assertFalse($rows['abr']['applicable']);
        $this->assertStringContainsString('Certificate of Tax Exemption', $rows['atir']['not_applicable_reason']);
        $this->assertTrue($rows['capr']['applicable']);
        $this->assertSame(11, CdaReport::query()->count());
    }

    public function test_a_second_period_for_the_same_year_answers_409(): void
    {
        $this->postJson('/api/cda/periods', ['year' => 2026])->assertCreated();
        $this->postJson('/api/cda/periods', ['year' => 2026])->assertStatus(409);

        $this->assertSame(1, CdaReportingPeriod::query()->count());
        $this->assertSame(11, CdaReport::query()->count());
        $this->postJson('/api/cda/periods', ['year' => 1999])->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->postJson('/api/cda/periods', ['year' => 2029])->assertUnprocessable()->assertJsonValidationErrors('year');
    }

    public function test_the_annual_deadline_is_120_days_after_dec_31_and_april_29_in_a_leap_year(): void
    {
        $deadlines = app(Deadlines::class);
        $settings = CdaSetting::current();

        $this->assertSame('2027-04-30', $deadlines->annual(2026, $settings));
        $this->assertSame('2028-04-29', $deadlines->annual(2027, $settings));
        $this->assertSame('2026-07-30', $deadlines->forInstance(2026, 'mediation', 'h1', $settings));
        $this->assertSame('2027-01-30', $deadlines->forInstance(2026, 'mediation', 'h2', $settings));

        $this->postJson('/api/cda/periods', ['year' => 2027])->assertCreated()->assertJsonPath('data.default_deadline', '2028-04-29');
    }

    public function test_an_extension_moves_the_annual_reports_and_an_override_wins_over_both(): void
    {
        $this->postJson('/api/cda/periods', ['year' => 2026])->assertCreated();

        $this->patchJson('/api/cda/periods/2026', ['extended_deadline' => '2027-05-31', 'extension_reference' => 'CDA MC 2027-03'])
            ->assertOk()
            ->assertJsonPath('data.extended_deadline', '2027-05-31')
            ->assertJsonPath('data.effective_deadline', '2027-05-31')
            ->assertJsonPath('data.days_remaining', 41);

        $this->patchJson('/api/cda/periods/2026', ['extended_deadline' => '2027-04-01', 'extension_reference' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('extended_deadline');
        $this->patchJson('/api/cda/periods/2026', ['extended_deadline' => '2027-06-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('extension_reference');

        $capr = CdaReport::query()->where('report_type', 'capr')->firstOrFail();
        $h1 = CdaReport::query()->where('report_type', 'mediation')->where('part', 'h1')->firstOrFail();

        $rows = collect($this->getJson('/api/cda/periods/2026')->assertOk()->json('data.reports'))->keyBy('id');
        $this->assertSame('2027-05-31', $rows[$capr->id]['effective_deadline']);
        $this->assertSame('2026-07-30', $rows[$h1->id]['effective_deadline'], 'The period extension covers the annual reports only.');

        $this->patchJson("/api/cda/reports/{$capr->id}/deadline", ['override_on' => '2027-06-15', 'reference' => 'Letter 15'])
            ->assertOk()
            ->assertJsonPath('data.due_override_on', '2027-06-15')
            ->assertJsonPath('data.effective_deadline', '2027-06-15')
            ->assertJsonPath('data.days_remaining', 56);

        $this->patchJson("/api/cda/reports/{$capr->id}/deadline", ['override_on' => '2027-06-20'])
            ->assertUnprocessable()->assertJsonValidationErrors('reference');

        $this->patchJson("/api/cda/reports/{$capr->id}/deadline", ['override_on' => null])
            ->assertOk()->assertJsonPath('data.effective_deadline', '2027-05-31');
    }

    public function test_days_remaining_counts_manila_days(): void
    {
        $this->assertSame(1, Deadlines::daysRemaining('2027-04-21', Carbon::parse('2027-04-20 23:59:00', 'Asia/Manila')));
        $this->assertSame(0, Deadlines::daysRemaining('2027-04-21', Carbon::parse('2027-04-20 16:30:00', 'UTC')));
        $this->assertSame(-1, Deadlines::daysRemaining('2027-04-21', Carbon::parse('2027-04-22 00:01:00', 'Asia/Manila')));
    }

    public function test_a_report_keeps_its_template_and_selection_takes_the_newest_matching_active_one(): void
    {
        $seeded = CdaReportTemplate::query()->where('report_type', 'capr')->where('status', 'active')->firstOrFail();
        $this->postJson('/api/cda/periods', ['year' => 2026])->assertCreated();
        $capr2026 = CdaReport::query()->where('report_type', 'capr')->firstOrFail();

        $newer = CdaReportTemplate::factory()->create([
            'report_type' => 'capr', 'version' => '2027.1', 'status' => 'active',
            'effective_from' => '2027-01-01', 'definition' => $seeded->definition,
        ]);
        $credit = CdaReportTemplate::factory()->create([
            'report_type' => 'capr', 'version' => '2027.2-credit', 'status' => 'active',
            'effective_from' => '2027-06-01', 'cooperative_types' => ['credit'], 'definition' => $seeded->definition,
        ]);

        $this->postJson('/api/cda/periods', ['year' => 2027])->assertCreated();
        $capr2027 = CdaReport::query()->where('report_type', 'capr')->where('id', '!=', $capr2026->id)->firstOrFail();

        $this->assertSame($seeded->id, $capr2026->fresh()->template_id);
        $this->assertSame($newer->id, $capr2027->template_id, 'A template for another cooperative type is not selected.');
        $this->assertNotSame($credit->id, $capr2027->template_id);
    }
}

<?php

namespace Tests\Feature\Cda\Registers;

use App\Models\Borrower;
use App\Models\CdaMediationCase;
use App\Models\CdaOfficer;
use App\Models\CdaOfficerTraining;
use App\Models\CdaReport;
use App\Models\CdaSetting;
use App\Models\CdaSocialProgram;
use App\Services\Cda\Sources\OfficersSource;
use App\Services\Cda\Sources\SourceContext;
use App\Services\Cda\Sources\SourceRegistry;
use App\Services\Cda\Sources\SourceResult;
use App\Services\Cda\Sources\SourceUnavailable;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The officers, social and mediation sources (CDA design §2, §6, §11
 * Registers): officers count for a year when their term overlaps it, linked
 * officers read their name and sex from the member, the training summary
 * carries the compliance indicator, social programs sum in centavos, and a
 * mediation case belongs to every half it overlaps.
 */
class CdaRegisterSourcesTest extends TestCase
{
    use RegisterHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->freezeClock();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $params */
    private function resolve(string $key, array $params = [], ?string $type = null, string $part = 'annual'): SourceResult
    {
        $period = $this->period();
        $report = $type === null ? null : CdaReport::query()->where('period_id', $period->id)->where('report_type', $type)->where('part', $part)->sole();

        return app(SourceRegistry::class)->resolve($key, $params, new SourceContext($period, $report));
    }

    public function test_officers_outside_the_year_are_excluded_and_linked_officers_read_from_the_member(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Rosa', 'middle_name' => null, 'suffix' => null, 'last_name' => 'Lim', 'gender' => 'female']);
        CdaOfficer::factory()->create(['borrower_id' => $member->id, 'name' => null, 'sex' => null, 'position' => 'chairperson', 'term_start' => '2025-03-01', 'term_end' => '2027-02-28']);
        CdaOfficer::factory()->create(['name' => 'Pedro Cruz', 'sex' => 'male', 'position' => 'general_manager', 'term_start' => '2026-06-01']);
        CdaOfficer::factory()->create(['name' => 'Gone Before', 'sex' => 'male', 'position' => 'treasurer', 'term_start' => '2022-01-01', 'term_end' => '2025-12-31']);
        CdaOfficer::factory()->create(['name' => 'Starts Later', 'sex' => 'female', 'position' => 'secretary', 'term_start' => '2027-01-01']);
        CdaOfficer::factory()->create(['name' => 'Left Mid Year', 'sex' => 'female', 'position' => 'board_member', 'term_start' => '2024-01-01', 'term_end' => '2026-04-30', 'status' => 'resigned']);
        $deleted = CdaOfficer::factory()->create(['name' => 'Deleted', 'position' => 'board_member', 'term_start' => '2026-01-01']);
        $deleted->delete();

        $list = $this->resolve('officers.list');

        $this->assertSame('table', $list->unit);
        $this->assertSame(['Rosa Lim', 'Left Mid Year', 'Pedro Cruz'], array_column($list->value, 'name'));
        $this->assertSame(['female', 'female', 'male'], array_column($list->value, 'sex'));
        $this->assertSame('Chairperson', $list->value[0]['position']);
        $this->assertSame('2025-03-01', $list->value[0]['term_start']);
        $this->assertSame('Officers register · as of Dec 31, 2026', $list->label);

        $this->assertSame(3, $this->resolve('officers.count')->value);
        $this->assertSame(2, $this->resolve('officers.count', ['sex' => 'female'])->value);
        $this->assertSame(2, $this->resolve('officers.count', ['group' => 'board'])->value);
        $this->assertSame(1, $this->resolve('officers.count', ['group' => 'management'])->value);
        $this->assertSame('Rosa Lim', $this->resolve('officers.current', ['position' => 'chairperson'])->value);
        $this->assertSame('Pedro Cruz', $this->resolve('officers.current', ['position' => 'general_manager'])->value);
        $this->assertNull($this->resolve('officers.current', ['position' => 'treasurer'])->value);
        $this->assertNull($this->resolve('officers.current', ['position' => 'general_manager', 'as_of' => '2026-05-31'])->value);
    }

    public function test_a_person_holding_two_positions_counts_once(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'gender' => 'male']);
        CdaOfficer::factory()->create(['borrower_id' => $member->id, 'name' => null, 'sex' => null, 'position' => 'board_member', 'term_start' => '2025-01-01']);
        CdaOfficer::factory()->create(['borrower_id' => $member->id, 'name' => null, 'sex' => null, 'position' => 'committee_member', 'committee' => 'Audit', 'term_start' => '2025-01-01']);

        $this->assertSame(1, $this->resolve('officers.count')->value);
        $this->assertSame(1, $this->resolve('officers.count', ['sex' => 'male', 'group' => 'committee'])->value);
        $this->assertCount(2, $this->resolve('officers.list')->value);
    }

    public function test_the_training_summary_carries_the_compliance_indicator(): void
    {
        CdaSetting::current()->update(['required_trainings' => ['chairperson' => ['fundamentals', 'governance'], 'treasurer' => ['fundamentals']]]);
        $chair = CdaOfficer::factory()->create(['name' => 'Chair', 'position' => 'chairperson', 'term_start' => '2025-01-01']);
        $treasurer = CdaOfficer::factory()->create(['name' => 'Treasurer', 'position' => 'treasurer', 'term_start' => '2025-01-01']);
        CdaOfficer::factory()->create(['name' => 'Member', 'position' => 'board_member', 'term_start' => '2025-01-01']);
        CdaOfficerTraining::factory()->create(['officer_id' => $chair->id, 'requirement_key' => 'fundamentals', 'completed_on' => '2025-06-01', 'hours' => '8.00']);
        CdaOfficerTraining::factory()->create(['officer_id' => $chair->id, 'requirement_key' => 'governance', 'completed_on' => '2026-02-01', 'hours' => '4.50']);
        CdaOfficerTraining::factory()->create(['officer_id' => $treasurer->id, 'requirement_key' => 'fundamentals', 'completed_on' => '2023-01-01', 'expires_on' => '2025-12-31', 'hours' => '8.00']);

        $summary = $this->resolve('trainings.summary');
        $rows = collect($summary->value)->keyBy('officer');

        $this->assertSame(['officer' => 'Chair', 'position' => 'Chairperson', 'required' => 2, 'completed' => 2, 'hours' => '4.50', 'status' => 'Complete', 'status_key' => 'complete', 'missing' => []], $rows['Chair']);
        $this->assertSame('Incomplete', $rows['Treasurer']['status']);
        $this->assertSame(['fundamentals'], $rows['Treasurer']['missing']);
        $this->assertSame('Not required', $rows['Member']['status']);
        $this->assertSame(['officers_required' => 2, 'complete' => 1, 'incomplete' => 1, 'percent' => '50.00'], $summary->meta['compliance']);
    }

    public function test_social_programs_sum_the_periods_held_programs_in_centavos(): void
    {
        $period = $this->period();
        CdaSocialProgram::factory()->create(['period_id' => $period->id, 'category' => 'health', 'actual_beneficiaries' => 10, 'budget' => '100.10', 'actual_expense' => '0.10', 'cdf_amount' => '0.05']);
        CdaSocialProgram::factory()->create(['period_id' => $period->id, 'category' => 'education', 'actual_beneficiaries' => 7, 'budget' => '0.20', 'actual_expense' => '0.20', 'cdf_amount' => '0.15', 'status' => 'ongoing']);
        CdaSocialProgram::factory()->create(['period_id' => $period->id, 'category' => 'health', 'actual_beneficiaries' => 99, 'actual_expense' => '99.00', 'status' => 'cancelled']);
        CdaSocialProgram::factory()->create(['period_id' => $period->id, 'category' => 'health', 'actual_beneficiaries' => 99, 'actual_expense' => '99.00', 'status' => 'planned']);
        CdaSocialProgram::factory()->create(['period_id' => $this->period(2025)->id, 'actual_expense' => '500.00']);

        $this->assertSame(2, $this->resolve('social_programs.summary', ['measure' => 'count'])->value);
        $this->assertSame('count', $this->resolve('social_programs.summary', ['measure' => 'count'])->unit);
        $this->assertSame(17, $this->resolve('social_programs.summary', ['measure' => 'beneficiaries'])->value);
        $actual = $this->resolve('social_programs.summary', ['measure' => 'actual_expense']);
        $this->assertSame('0.30', $actual->value);
        $this->assertSame('money', $actual->unit);
        $this->assertSame('0.20', $this->resolve('social_programs.summary', ['measure' => 'cdf_amount'])->value);
        $this->assertSame('100.30', $this->resolve('social_programs.summary', ['measure' => 'budget'])->value);
        $this->assertSame('0.10', $this->resolve('social_programs.summary', ['measure' => 'actual_expense', 'category' => 'health'])->value);

        $list = $this->resolve('social_programs.list')->value;
        $this->assertCount(2, $list);
        $this->assertSame('Health', $list[0]['category']);
        $this->assertSame('0.05', $list[0]['cdf_amount']);
    }

    public function test_a_mediation_case_spanning_both_halves_appears_in_both(): void
    {
        $spanning = CdaMediationCase::factory()->create(['filed_on' => '2026-05-01', 'resolved_on' => '2026-08-15', 'status' => 'resolved', 'complainant' => 'Juan Dela Cruz']);
        CdaMediationCase::factory()->create(['filed_on' => '2026-02-01', 'resolved_on' => '2026-03-01', 'status' => 'resolved']);
        CdaMediationCase::factory()->create(['filed_on' => '2026-09-01', 'status' => 'pending']);
        CdaMediationCase::factory()->create(['filed_on' => '2025-02-01', 'resolved_on' => '2025-12-31', 'status' => 'resolved']);

        $h1 = $this->resolve('mediation.cases', [], 'mediation', 'h1')->value;
        $h2 = $this->resolve('mediation.cases', [], 'mediation', 'h2')->value;

        $this->assertContains($spanning->case_number, array_column($h1, 'case_number'));
        $this->assertContains($spanning->case_number, array_column($h2, 'case_number'));
        $this->assertCount(2, $h1);
        $this->assertCount(2, $h2);
        $this->assertArrayNotHasKey('complainant', $h1[0]);
        $this->assertSame(2, $this->resolve('mediation.summary', ['half' => 'h1'])->value);
        $this->assertSame(3, $this->resolve('mediation.summary', [], 'capr')->value);
        $this->assertSame(2, $this->resolve('mediation.summary', ['status' => 'resolved'], 'capr')->value);
        $this->assertSame(1, $this->resolve('mediation.summary', ['status' => 'pending'], 'mediation', 'h2')->value);
        $this->assertSame('Mediation register · as of Jun 30, 2026', $this->resolve('mediation.summary', [], 'mediation', 'h1')->label);
    }

    public function test_a_key_the_family_doesnt_know_is_refused_in_words(): void
    {
        $this->expectException(SourceUnavailable::class);
        $this->expectExceptionMessage('The officers sources don\'t know "officers.unknown".');

        app(OfficersSource::class)->resolve('officers.unknown', [], new SourceContext($this->period()));
    }
}

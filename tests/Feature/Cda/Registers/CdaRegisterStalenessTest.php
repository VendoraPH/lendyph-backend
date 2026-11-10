<?php

namespace Tests\Feature\Cda\Registers;

use App\Models\CdaReport;
use App\Services\Cda\CdaAccess;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A register write marks the draft reports that read it as stale (CDA design
 * §4), so the screen offers Refresh: officers feed CAPR and the officers
 * report, social programs feed CAPR, the social audit and the ABR, and
 * mediation feeds CAPR and the mediation halves it overlaps. Reports past
 * draft, and reports that don't apply (ABR without a CTE), are left
 * alone.
 */
class CdaRegisterStalenessTest extends TestCase
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

    /** @return list<string> "type/part" of the period's stale reports */
    private function stale(): array
    {
        return CdaReport::query()->where('period_id', $this->period()->id)->whereNotNull('stale_at')->orderBy('report_type')->orderBy('part')
            ->get()->map(fn (CdaReport $report) => "{$report->report_type}/{$report->part}")->all();
    }

    public function test_an_officer_write_marks_capr_and_the_officers_report_stale_but_not_a_finalized_one(): void
    {
        $period = $this->period();
        CdaReport::query()->where('period_id', $period->id)->where('report_type', 'capr')->update(['status' => CdaReport::STATUS_FINALIZED]);
        $this->actingAs($this->cdaUser([CdaAccess::MANAGE_OFFICERS]));

        $this->postJson('/api/cda/officers', ['name' => 'Ana Reyes', 'sex' => 'female', 'position' => 'treasurer', 'term_start' => '2026-01-01'])->assertCreated();

        $this->assertSame(['officers_trainings/annual'], $this->stale());
    }

    public function test_social_and_mediation_writes_mark_their_reports_stale(): void
    {
        $this->period();
        $this->actingAs($this->cdaUser([CdaAccess::MANAGE_SOCIAL, CdaAccess::MANAGE_MEDIATION, CdaAccess::VIEW_MEDIATION]));

        $this->postJson('/api/cda/periods/2026/social-programs', ['name' => 'Clean-up drive', 'category' => 'community'])->assertCreated();
        $this->assertSame(['capr/annual', 'social_audit/annual'], $this->stale());

        CdaReport::query()->update(['stale_at' => null]);
        $this->postJson('/api/cda/periods/2026/mediation-cases', ['filed_on' => '2026-08-03', 'status' => 'pending'])->assertCreated();
        $this->assertSame(['capr/annual', 'mediation/h2'], $this->stale());
    }
}

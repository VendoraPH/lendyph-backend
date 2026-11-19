<?php

namespace Tests\Feature\Phase6\Amla\Reports;

use App\Models\AmlaAlert;
use App\Models\Branch;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The AMLA dashboard counts (AMLA-B7): each block gated by its own key (null
 * without it), branch-scoped, and AMLA-only.
 */
class AmlaDashboardCountsTest extends TestCase
{
    use ReportTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startCases();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_each_count_is_gated_by_its_own_key_and_follows_branch_scope(): void
    {
        $other = Branch::factory()->create();
        $this->structuringAlert();
        $this->structuringAlert($other->id);
        $this->ctrCandidate();
        $this->openCase($this->headOffice(['amla:cases']));

        $this->actingAs($this->amlaUser(['amla:cdd']))->getJson('/api/amla/dashboard/counts')->assertOk()
            ->assertJsonPath('data.alerts', null)->assertJsonPath('data.cases', null)->assertJsonPath('data.ctr_candidates', null);

        $branch = $this->actingAs($this->amlaUser(['amla:alerts', 'amla:ctr']))->getJson('/api/amla/dashboard/counts')->assertOk()->json('data');
        $this->assertSame(1, $branch['alerts']['high']);
        $mine = AmlaAlert::query()->where('branch_id', $this->branch->id)->whereIn('status', AmlaAlert::WORKABLE)->count();
        $this->assertSame($mine, $branch['alerts']['total']);
        $this->assertLessThan(AmlaAlert::query()->count(), $branch['alerts']['total']);
        $this->assertSame(1, $branch['ctr_candidates']['detected']);
        $this->assertNull($branch['cases']);
        $this->assertNull($branch['cdd']);
        $this->assertNotNull($branch['filings']);

        $head = $this->actingAs($this->headOffice(['amla:alerts', 'amla:cases', 'amla:cdd']))->getJson('/api/amla/dashboard/counts')->json('data');
        $this->assertSame(2, $head['alerts']['high']);
        $this->assertSame(AmlaAlert::query()->whereIn('status', AmlaAlert::WORKABLE)->count(), $head['alerts']['total']);
        $this->assertSame(1, $head['cases']['open']);
        $this->assertGreaterThan(0, array_sum($head['cdd']));
        $this->assertNull($head['ctr_candidates']);

        $this->actingAs($this->amlaUser([]))->getJson('/api/amla/dashboard/counts')->assertForbidden();
    }
}

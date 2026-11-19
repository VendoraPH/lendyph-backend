<?php

namespace Tests\Feature\Linda;

use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\PeriodOpener;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaMatcher;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaWording;
use Illuminate\Support\Carbon;

/**
 * Linda round 3, CDA questions: the next deadline and the reporting year's
 * readiness, from the CDA dashboard's own code (ReportDashboard::dashboard(),
 * GET /cda/periods/{year}/dashboard) for the newest reporting year, as the
 * dashboard opens. It needs `cda:view` and `branches:view_all`, and a report
 * the caller can't open (mediation, audit, tax) is left out, as on the
 * dashboard.
 */
class LindaCdaAnswerTest extends LindaTopicTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-19 10:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function openYears(int ...$years): void
    {
        foreach ($years as $year) {
            app(PeriodOpener::class)->open($year, $this->admin);
        }
    }

    /** @return array<string, mixed> */
    private function dashboard(User $user, int $year = 2026): array
    {
        return $this->actingAs($user)->getJson("/api/cda/periods/{$year}/dashboard")->assertOk()->json('data');
    }

    public function test_the_figures_equal_the_cda_dashboard_for_the_newest_year(): void
    {
        $this->openYears(2025, 2026);
        $head = $this->headOffice([CdaAccess::VIEW, CdaAccess::VIEW_MEDIATION, CdaAccess::VIEW_AUDIT, CdaAccess::VIEW_TAX]);
        $data = $this->dashboard($head);
        $next = $data['next_deadline'];

        $reply = $this->ask($head, LindaIntent::CdaSummary);
        $stats = $this->stats($reply);

        $this->assertSame('answered', $reply->outcome);
        $this->assertSame('CdaSummaryAnswer', $reply->answerFunction);
        $this->assertEquals($data['readiness'], $stats['Lendy CDA Readiness']);
        $this->assertSame($data['counts']['applicable'], $stats['Applicable Reports']);
        $this->assertSame($data['counts']['filed'], $stats['Filed']);
        $this->assertSame($data['counts']['critical'], $stats['Critical Issues']);
        $this->assertSame($data['counts']['warnings'], $stats['Warnings']);
        $this->assertNotNull($next);
        $this->assertSame($next['effective_deadline'], $stats['Next Deadline']);
        $this->assertSame('Mediation and Conciliation Report', $next['title']);
        $this->assertSame(
            'For the 2026 CDA reporting year, Lendy CDA Readiness is '.$data['readiness'].'% across '.$data['counts']['applicable']
            .' applicable reports, and '.$data['counts']['filed'].' are filed. '
            .LindaWording::count($data['counts']['critical'], 'critical issue').' and '.LindaWording::count($data['counts']['warnings'], 'warning')
            .' are open. The next deadline is Mediation and Conciliation Report (second half, July–December), due on January 30, 2027, in 72 days.',
            $reply->answer,
        );
        $this->assertSame(['/cda?year=2026', '/cda/calendar?year=2026'], $this->linkUrls($reply));
    }

    public function test_restricted_reports_stay_hidden_as_on_the_dashboard(): void
    {
        $this->openYears(2026);
        $viewer = $this->headOffice([CdaAccess::VIEW]);
        $data = $this->dashboard($viewer);

        $reply = $this->ask($viewer, LindaIntent::CdaSummary);
        $stats = $this->stats($reply);

        $this->assertSame($data['counts']['applicable'], $stats['Applicable Reports']);
        $this->assertEquals($data['readiness'], $stats['Lendy CDA Readiness']);
        $this->assertSame($data['next_deadline']['effective_deadline'] ?? null, $stats['Next Deadline'] ?? null);
        $this->assertStringNotContainsString('Mediation', $reply->answer);
    }

    public function test_it_needs_cda_view_and_every_branch(): void
    {
        $this->openYears(2026);

        $this->assertSame('denied', $this->ask($this->branchUser([CdaAccess::VIEW]), LindaIntent::CdaSummary)->outcome);
        $this->assertSame('denied', $this->ask($this->headOffice(), LindaIntent::CdaSummary)->outcome);
    }

    public function test_no_reporting_period_is_said_plainly(): void
    {
        $reply = $this->ask($this->headOffice([CdaAccess::VIEW]), LindaIntent::CdaSummary);

        $this->assertSame('not_yet', $reply->outcome);
        $this->assertSame('No CDA reporting year has been opened yet, so there is no deadline or readiness to show.', $reply->answer);
        $this->assertSame([], $reply->blocks);
    }

    public function test_a_named_branch_or_period_still_reads_the_whole_cooperative_for_the_newest_year(): void
    {
        $this->openYears(2026);
        $head = $this->headOffice([CdaAccess::VIEW]);
        $plain = $this->ask($head, LindaIntent::CdaSummary);

        $branch = $this->ask($head, LindaIntent::CdaSummary, branch: 'Far');
        $this->assertSame($this->stats($plain), $this->stats($branch));
        $this->assertStringEndsWith('CDA reports cover the whole cooperative, so these figures are for all branches.', $branch->answer);

        $period = $this->ask($head, LindaIntent::CdaSummary, LindaPeriod::named('last_month'));
        $this->assertSame($this->stats($plain), $this->stats($period));
        $this->assertStringEndsWith('These are the newest reporting year\'s figures; open the CDA Dashboard to see another year.', $period->answer);
    }

    public function test_cda_questions_route_to_the_cda_intent(): void
    {
        foreach ([
            'When is the next CDA deadline?', 'What is our CDA readiness?', 'Are we ready for CDA?', 'CDA compliance status',
            'Kailan ang deadline sa CDA?', 'Handa na ba tayo sa CDA?', 'Kanus-a ang sunod nga deadline sa CDA?',
            'Andam na ba ta sa CDA?',
        ] as $question) {
            $this->assertSame(LindaIntent::CdaSummary, LindaMatcher::match($question)?->intent, $question);
        }
    }
}

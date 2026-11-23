<?php

namespace Tests\Feature\Linda;

use App\Models\Borrower;
use App\Models\CdaOfficer;
use App\Models\CdaOfficerTraining;
use App\Models\CdaReport;
use App\Models\CdaSetting;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\PeriodOpener;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaMatcher;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
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

    // -----------------------------------------------------------------
    // Round 4: the CDA task's sample questions (Phase 3 task 14zfbg08y7w)
    // -----------------------------------------------------------------

    /** Every CDA view key, with `branches:view_all`. */
    private function cdaHead(): User
    {
        return $this->headOffice([CdaAccess::VIEW, CdaAccess::VIEW_MEDIATION, CdaAccess::VIEW_AUDIT, CdaAccess::VIEW_TAX]);
    }

    /** A question as staff type it: the matcher must read it as CDA, so it never reaches the model. */
    private function askAs(User $user, string $question): LindaReply
    {
        $match = LindaMatcher::match($question);

        $this->assertNotNull($match, $question);
        $this->assertSame(LindaIntent::CdaSummary, $match->intent, $question);

        return $this->ask($user, $match->intent, $match->period, $match->branchName, $question);
    }

    /** A dashboard row's name, as the answers write it: "Mediation and Conciliation Report (first half, January–June)". */
    private static function nameOf(array $row): string
    {
        return $row['title'].($row['part'] === 'annual' ? '' : ' ('.lcfirst(str_replace([' (', ')'], [', ', ''], (string) $row['title_part'])).')');
    }

    /** @return list<array<string, mixed>> the applicable rows not filed and below 100% (or returned for correction) */
    private static function incompleteRows(array $data): array
    {
        return array_values(array_filter($data['reports'], fn (array $row) => $row['applicable']
            && ! in_array($row['status'], [CdaReport::STATUS_SUBMITTED, CdaReport::STATUS_ACCEPTED], true)
            && ($row['readiness'] < 100 || $row['status'] === CdaReport::STATUS_FOR_CORRECTION)));
    }

    /** @return list<array<string, mixed>> list rows of every list block */
    private function listRows(LindaReply $reply): array
    {
        $rows = [];
        foreach ($reply->blocks as $block) {
            foreach ($block['type'] === 'list' ? $block['items'] : [] as $item) {
                $rows[] = $item;
            }
        }

        return $rows;
    }

    /** CAPR approved with every check passing: a complete report. */
    private function completeCapr(): void
    {
        CdaReport::query()->where('report_type', 'capr')->firstOrFail()->forceFill([
            'status' => CdaReport::STATUS_APPROVED, 'readiness' => 100, 'issues' => [], 'critical_count' => 0, 'warning_count' => 0,
        ])->save();
    }

    public function test_what_cda_reports_are_incomplete(): void
    {
        $this->openYears(2026);
        $this->completeCapr();
        $head = $this->cdaHead();
        $data = $this->dashboard($head);
        $incomplete = self::incompleteRows($data);
        $applicable = $data['counts']['applicable'];

        $reply = $this->askAs($head, 'What CDA reports are incomplete?');

        $this->assertSame('answered', $reply->outcome);
        $this->assertNotEmpty($incomplete);
        $this->assertSame(array_map(self::nameOf(...), $incomplete), array_column($this->listRows($reply), 'title'));
        $this->assertStringStartsWith('For the 2026 CDA reporting year, '.count($incomplete).' of '.$applicable.' applicable reports are still incomplete: ', $reply->answer);
        foreach ($incomplete as $row) {
            $this->assertStringContainsString(self::nameOf($row).' ('.$row['readiness'].'%)', $reply->answer);
        }
        $this->assertStringNotContainsString('Cooperative Annual Progress Report (CAPR) (', $reply->answer);
        $this->assertStringContainsString('Complete: Cooperative Annual Progress Report (CAPR).', $reply->answer);
        $this->assertStringContainsString('Lendy CDA Readiness is '.$data['readiness'].'%.', $reply->answer);
        $this->assertSame(['/cda?year=2026'], $this->linkUrls($reply));
    }

    public function test_are_we_ready_for_capr(): void
    {
        $this->openYears(2026);
        $head = $this->cdaHead();
        $data = $this->dashboard($head);
        $row = collect($data['reports'])->firstWhere('report_type', 'capr');
        $issues = collect($data['issues'])->where('report_id', $row['id'])->whereIn('severity', ['critical', 'warning'])
            ->sortBy(fn (array $issue) => $issue['severity'] === 'critical' ? 0 : 1)->values();
        // The dashboard's status words (lendyph-frontend cda/_lib/labels.ts STATUS_META).
        $status = ['not_started' => 'Not started', 'draft' => 'Draft', 'validation_failed' => 'Has critical issues'][$row['display_status']];

        $reply = $this->askAs($head, 'Are we ready for CAPR?');
        $stats = $this->stats($reply);

        $this->assertSame('answered', $reply->outcome);
        $this->assertSame($row['readiness'], (int) $stats['Readiness']);
        $this->assertSame($row['critical'], $stats['Critical Issues']);
        $this->assertSame($row['warnings'], $stats['Warnings']);
        $this->assertSame($row['effective_deadline'], $stats['Deadline']);
        $this->assertStringStartsWith(
            'For the 2026 CDA reporting year, Cooperative Annual Progress Report (CAPR) is '.$row['readiness'].'% ready (status: '.$status.'). ',
            $reply->answer,
        );
        $this->assertStringContainsString(LindaWording::count($row['critical'], 'critical issue').' and '.LindaWording::count($row['warnings'], 'warning').' are open', $reply->answer);
        $this->assertStringContainsString('It is due on '.LindaWording::date($row['effective_deadline']).', in '.LindaWording::count($row['days_remaining'], 'day').'.', $reply->answer);
        $this->assertSame(array_slice($issues->pluck('message')->all(), 0, 10), array_column($this->listRows($reply), 'title'));
        $this->assertSame(['/cda/capr?year=2026', '/cda?year=2026'], $this->linkUrls($reply));
    }

    public function test_which_officer_trainings_are_missing(): void
    {
        $this->openYears(2026);
        CdaSetting::current()->update(['required_trainings' => ['chairperson' => ['fundamentals', 'governance'], 'treasurer' => ['basic_accounting']]]);
        $chair = CdaOfficer::factory()->create(['name' => 'Ana Reyes', 'position' => 'chairperson', 'term_start' => '2025-01-01']);
        CdaOfficerTraining::factory()->create(['officer_id' => $chair->id, 'requirement_key' => 'fundamentals', 'status' => 'completed', 'completed_on' => '2025-05-01']);
        $done = CdaOfficer::factory()->create(['name' => 'Ben Cruz', 'position' => 'treasurer', 'term_start' => '2025-01-01']);
        CdaOfficerTraining::factory()->create(['officer_id' => $done->id, 'requirement_key' => 'basic_accounting', 'status' => 'completed', 'completed_on' => '2025-06-01']);
        $member = Borrower::factory()->create(['first_name' => 'Cora', 'middle_name' => null, 'last_name' => 'Lim']);
        CdaOfficer::factory()->create(['borrower_id' => $member->id, 'name' => null, 'position' => 'treasurer', 'term_start' => '2025-06-01']);
        // No longer serving: on the register, never in the answer.
        CdaOfficer::factory()->create(['name' => 'Old Chair', 'position' => 'chairperson', 'status' => 'resigned', 'term_start' => '2020-01-01', 'term_end' => '2022-12-31']);
        $head = $this->cdaHead();

        $register = collect($this->actingAs($head)->getJson('/api/cda/officers')->assertOk()->json('data'))
            ->filter(fn (array $row) => $row['status'] === 'active' && $row['trainings']['status'] === 'incomplete');

        $reply = $this->askAs($head, 'Which officer trainings are missing?');
        $rows = $this->listRows($reply);

        $this->assertSame(['Ana Reyes', 'Cora Lim'], array_column($rows, 'title'));
        $this->assertSame($register->pluck('name')->sort()->values()->all(), collect($rows)->pluck('title')->sort()->values()->all());
        $this->assertSame(['Chairperson', 'Treasurer'], array_column($rows, 'subtitle'));
        $this->assertSame([['label' => 'Missing', 'value' => 'Governance', 'format' => 'text']], $rows[0]['fields']);
        $this->assertSame([['label' => 'Missing', 'value' => 'Basic Accounting', 'format' => 'text']], $rows[1]['fields']);
        $this->assertSame([$member->id], $reply->memberIds);
        $this->assertSame(
            'As of today, November 19, 2026, 2 of 3 officers serving now haven\'t completed the trainings their position requires. They are listed below with what is missing.',
            $reply->answer,
        );
        $this->assertSame(['Officers Serving' => 3, 'Officers Missing Trainings' => 2], $this->stats($reply));
        $this->assertSame(['/cda/officers?year=2026'], $this->linkUrls($reply));
    }

    public function test_what_is_our_enhanced_pesos_rating(): void
    {
        $this->openYears(2026);
        $head = $this->cdaHead();
        $pesos = $this->actingAs($head)->getJson('/api/cda/periods/2026/pesos')->assertOk()->json('data');
        $scored = count(array_filter($pesos['metrics'], fn (array $metric) => $metric['status'] === 'scored'));

        $reply = $this->askAs($head, 'What is our Enhanced PESOS rating?');
        $stats = $this->stats($reply);

        $this->assertSame('answered', $reply->outcome);
        $this->assertSame($scored, $stats['Indicators Scored']);
        $this->assertSame(count($pesos['metrics']) - $scored, $stats['Indicators Without a Score']);
        $this->assertSame($pesos['score'] ?? '—', $stats['Score']);
        $this->assertSame($pesos['rating'] ?? 'Not yet rated', $stats['Rating']);
        $this->assertFalse($pesos['verified']);
        $this->assertStringContainsString('provisional', $reply->answer);
        $this->assertStringContainsString('to verify', $reply->answer);
        $this->assertStringStartsWith('For the 2026 CDA reporting year, the Enhanced PESOS', $reply->answer);
        if ($pesos['rating'] === null) {
            $this->assertStringContainsString('has no rating yet', $reply->answer);
        }
        $this->assertSame(['/cda/pesos?year=2026'], $this->linkUrls($reply));
    }

    public function test_when_is_our_next_cda_deadline(): void
    {
        $this->openYears(2026);
        $head = $this->cdaHead();
        $data = $this->dashboard($head);

        $reply = $this->askAs($head, 'When is our next CDA deadline?');

        $this->assertSame($data['next_deadline']['effective_deadline'], $this->stats($reply)['Next Deadline']);
        $this->assertStringContainsString('The next deadline is Mediation and Conciliation Report (second half, July–December), due on January 30, 2027, in 72 days.', $reply->answer);
    }

    public function test_why_is_our_cda_readiness_only_what_it_is(): void
    {
        $this->openYears(2026);
        $this->completeCapr();
        $head = $this->cdaHead();
        $data = $this->dashboard($head);
        $below = array_values(array_filter($data['reports'], fn (array $row) => $row['applicable'] && $row['readiness'] < 100));

        $reply = $this->askAs($head, 'Why is our CDA readiness only '.$data['readiness'].'%?');
        $rows = $this->listRows($reply);

        $this->assertStringStartsWith(
            'Lendy CDA Readiness for the 2026 CDA reporting year is '.$data['readiness'].'%: the average of the '.$data['counts']['applicable'].' applicable reports\' own readiness. '
            .count($below).' reports are below 100%: ',
            $reply->answer,
        );
        $this->assertSame(array_map(self::nameOf(...), $below), array_column($rows, 'title'));
        foreach ($below as $at => $row) {
            $critical = collect($data['issues'])->where('report_id', $row['id'])->where('severity', 'critical')->pluck('message')->take(3)->values()->all();
            $this->assertSame($row['readiness'].'% ready', $rows[$at]['subtitle']);
            $this->assertSame($critical, array_column(array_slice($rows[$at]['fields'], 1), 'value'));
            $this->assertSame(['label' => 'Critical Issues', 'value' => $row['critical'], 'format' => 'number'], $rows[$at]['fields'][0]);
        }
        $this->assertStringNotContainsString('Cooperative Annual Progress Report', json_encode($rows));
    }

    public function test_which_documents_are_missing(): void
    {
        $this->openYears(2026);
        $head = $this->cdaHead();
        $data = $this->dashboard($head);
        $missing = array_values(array_filter($data['issues'], fn (array $issue) => $issue['category'] === 'compliance_document' && in_array($issue['severity'], ['critical', 'warning'], true)));

        $reply = $this->askAs($head, 'Which documents are missing?');
        $rows = $this->listRows($reply);

        $this->assertNotEmpty($missing);
        $listed = array_slice($missing, 0, 10);
        $this->assertSame(array_column($listed, 'message'), array_column($rows, 'title'));
        $names = collect($data['reports'])->keyBy('id');
        $this->assertSame(array_map(fn (array $issue) => self::nameOf($names[$issue['report_id']]), $listed), array_column($rows, 'subtitle'));
        $this->assertStringStartsWith('For the 2026 CDA reporting year, '.LindaWording::count(count($missing), 'document').' '.(count($missing) === 1 ? 'is' : 'are').' still missing', $reply->answer);
        $this->assertSame(['/cda/documents?year=2026', '/cda?year=2026'], $this->linkUrls($reply));
    }

    public function test_are_we_ready_to_submit_our_cda_reports(): void
    {
        $this->openYears(2026);
        $this->completeCapr();
        CdaSetting::current()->update(['required_trainings' => ['chairperson' => ['governance']]]);
        CdaOfficer::factory()->create(['name' => 'Ana Reyes', 'position' => 'chairperson', 'term_start' => '2025-01-01']);
        $head = $this->cdaHead();
        $data = $this->dashboard($head);
        $incomplete = self::incompleteRows($data);

        $reply = $this->askAs($head, 'Are we ready to submit our CDA reports?');

        $this->assertStringStartsWith(
            'For the 2026 CDA reporting year, Lendy CDA Readiness is '.$data['readiness'].'% across '.$data['counts']['applicable'].' applicable reports. '
            .'Complete: Cooperative Annual Progress Report (CAPR). Still to finish: ',
            $reply->answer,
        );
        foreach ($incomplete as $row) {
            $this->assertStringContainsString(self::nameOf($row).' ('.$row['readiness'].'%', $reply->answer);
        }
        $this->assertStringEndsWith('1 officer serving today hasn\'t completed the trainings their position requires.', $reply->answer);
        $this->assertEquals($data['readiness'], $this->stats($reply)['Lendy CDA Readiness']);
        $this->assertSame(array_map(self::nameOf(...), array_values(array_filter($data['reports'], fn (array $row) => $row['applicable']))), array_column($this->listRows($reply), 'title'));
    }

    // -----------------------------------------------------------------
    // Round 4: permissions
    // -----------------------------------------------------------------

    public function test_a_restricted_report_named_without_its_view_is_refused_in_words(): void
    {
        $this->openYears(2026);
        $viewer = $this->headOffice([CdaAccess::VIEW]);

        foreach ([
            'Are we ready for ATIR?' => 'Annual Tax Incentives Report (ATIR) is restricted: it needs the CDA tax reports view permission.',
            'Are we ready for the audited financial statements?' => 'Audited Financial Statements is restricted: it needs the CDA audit reports view permission.',
            'Is our mediation report ready?' => 'Mediation and Conciliation Report is restricted: it needs the CDA mediation view permission.',
        ] as $question => $refusal) {
            $reply = $this->askAs($viewer, $question);

            $this->assertSame('denied', $reply->outcome, $question);
            $this->assertSame($refusal, $reply->answer, $question);
            $this->assertSame([], $reply->blocks, $question);
            $this->assertSame([], $reply->links, $question);
        }

        // With the view key, the same question is answered.
        $this->assertSame('answered', $this->askAs($this->cdaHead(), 'Are we ready for the audited financial statements?')->outcome);
    }

    public function test_the_new_answers_leave_out_restricted_reports_as_the_dashboard_does(): void
    {
        $this->openYears(2026);
        $viewer = $this->headOffice([CdaAccess::VIEW]);
        $visible = array_map(self::nameOf(...), $this->dashboard($viewer)['reports']);
        $hidden = ['Audited Financial Statements', 'Performance Audit Report', 'Mediation and Conciliation Report (first half, January–June)', 'Mediation and Conciliation Report (second half, July–December)'];
        $this->assertSame([], array_intersect($hidden, $visible));

        // Reports are named by list titles (subtitles for documents) and in the
        // answer's own sentence; issue messages are the dashboard's own words.
        foreach (['What CDA reports are incomplete?' => 'title', 'Why is our CDA readiness only 60%?' => 'title', 'Which documents are missing?' => 'subtitle', 'Are we ready to submit our CDA reports?' => 'title'] as $question => $key) {
            $reply = $this->askAs($viewer, $question);

            $this->assertSame([], array_diff(array_column($this->listRows($reply), $key), $visible), $question);
            foreach ($hidden as $title) {
                $this->assertStringNotContainsString($title.' (', $reply->answer, $question);
            }
        }

        $data = $this->dashboard($this->cdaHead());
        $fs = collect($data['reports'])->firstWhere('report_type', 'financial_statements');
        $this->assertTrue($fs['applicable']);
        $this->assertLessThan(100, $fs['readiness']);
        $this->assertContains('Audited Financial Statements', array_column($this->listRows($this->askAs($this->cdaHead(), 'What CDA reports are incomplete?')), 'title'));
        $this->assertNotContains('Audited Financial Statements', array_column($this->listRows($this->askAs($viewer, 'What CDA reports are incomplete?')), 'title'));
    }

    public function test_the_new_answers_need_cda_view_and_every_branch(): void
    {
        $this->openYears(2026);

        foreach (['What CDA reports are incomplete?', 'Are we ready for CAPR?', 'Which officer trainings are missing?', 'What is our Enhanced PESOS rating?', 'Which documents are missing?', 'Why is our CDA readiness only 50%?'] as $question) {
            foreach ([$this->branchUser([CdaAccess::VIEW]), $this->headOffice()] as $user) {
                $reply = $this->askAs($user, $question);

                $this->assertSame('denied', $reply->outcome, $question);
                $this->assertSame([], $reply->blocks, $question);
                $this->assertSame([], $reply->memberIds, $question);
            }
        }
    }

    public function test_the_sample_questions_are_understood_in_tagalog_and_bisaya(): void
    {
        foreach ([
            'Handa na ba tayo sa CAPR?', 'Andam na ba ta sa CAPR?', 'Anong mga CDA report ang hindi pa kumpleto?',
            'Unsa nga mga CDA report ang dili pa kompleto?', 'Sino sa mga opisyal ang kulang ang training?',
            'Kinsa nga mga opisyal ang kulang og training?', 'Pila ang PESOS rating nato?', 'Bakit 82% lang ang CDA readiness natin?',
            'Nganong 82% ra ang atong CDA readiness?', 'Anong mga dokumento ang kulang?', 'Unsa nga mga dokumento ang kulang?',
            'Which officers have missing trainings?', 'What is our PESOS score?', 'Are the social audit and sworn statement ready?',
        ] as $question) {
            $this->assertSame(LindaIntent::CdaSummary, LindaMatcher::match($question)?->intent, $question);
        }

        // Words that only make sense in a CDA question don't pull others in.
        foreach (['How much have we collected in pesos?', 'Which loan documents are missing?', 'Which officer collected the most?'] as $question) {
            $this->assertNotSame(LindaIntent::CdaSummary, LindaMatcher::match($question)?->intent, $question);
        }
    }
}

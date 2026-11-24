<?php

namespace Tests\Feature\Linda;

use App\Models\Borrower;
use App\Models\CreditPolicyFlag;
use App\Models\CreditScoreMember;
use App\Models\LindaQuestion;
use App\Models\User;
use App\Services\CreditScoring\Api\ScorecardEditor;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaFacts;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaMatcher;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaWording;
use App\Services\Linda\Model\FakeModel;
use App\Services\Linda\Model\LindaModelRequest;
use App\Services\Linda\Model\LindaPrompt;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * Linda answers credit scoring summaries (finish run round 2, decision 12):
 * members by risk level, members due for review (the dashboard's "Scores Due
 * for Review" rule) and the score bands, from the Credit Scoring dashboard's
 * own figures (CreditScope, Portfolio::dueForReview(), the scorecard's
 * bands). It needs `credit_scoring:view`, follows branch scope and never
 * shows, logs or sends a member's name, code or score.
 */
class LindaCreditScoringAnswerTest extends TestCase
{
    use CreditApiFixtures;

    /** Each member's own score: never one of the band edges, so it can't be read as one. */
    private const SCORES = ['92', '84', '47', '72', '33'];

    /** @var array<string, Borrower> */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->pinToday();

        // Main: very low, low, high (fell from low: due), moderate (an open
        // hard flag: due with borrowers:view) and one with too little history.
        // Far: very high.
        $this->members['ana'] = $this->scoredAs('Ana', 'Reyes', 'very_low', 92);
        $this->members['ben'] = $this->scoredAs('Ben', 'Cruz', 'low', 84);
        $this->members['cara'] = $this->scoredAs('Cara', 'Santos', 'high', 47, 85);
        $this->members['dino'] = $this->scoredAs('Dino', 'Lopez', 'moderate', 72, 72);
        $this->members['ella'] = $this->member(['branch_id' => $this->mainBranch()->id, 'first_name' => 'Ella', 'last_name' => 'Ramos']);
        $this->scoreNow($this->members['ella']);
        $this->members['fe'] = $this->scoredAs('Fe', 'Garcia', 'very_high', 33, 33, $this->farBranch()->id);

        CreditPolicyFlag::query()->create([
            'borrower_id' => $this->members['dino']->id, 'rule_key' => 'existing_default', 'type' => CreditPolicyFlag::HARD,
            'label' => 'Defaulted loan', 'detail' => 'The member has a defaulted loan.', 'first_triggered_at' => now()->subDays(40), 'last_seen_at' => now(),
        ]);
    }

    private function scoredAs(string $first, string $last, string $level, int $score, ?int $previous = null, ?int $branchId = null): Borrower
    {
        $member = $this->member(['branch_id' => $branchId ?? $this->mainBranch()->id, 'first_name' => $first, 'last_name' => $last, 'monthly_income' => 40000]);
        $this->paidMonthly($this->releasedLoan($member), '2026-06-01', 3);
        $this->scoreNow($member);
        CreditScoreMember::query()->where('borrower_id', $member->id)->update(['risk_level' => $level, 'score' => $score, 'previous_score' => $previous]);

        return $member;
    }

    private function ask(User $user, ?string $question = null, ?string $branch = null, ?LindaPeriod $period = null): LindaReply
    {
        return app(LindaAnswerer::class)->answer($user, LindaIntent::CreditScoringSummary, $period, $branch, $question);
    }

    /** @return array<string, mixed> */
    private function stats(LindaReply $reply): array
    {
        $values = [];
        foreach ($reply->blocks as $block) {
            foreach ($block['type'] === 'stats' ? $block['items'] : [] as $item) {
                $values[$item['label']] = $item['value'];
            }
        }

        return $values;
    }

    /** @return array<string, list<array<string, mixed>>> each list block's rows, by block title */
    private function lists(LindaReply $reply): array
    {
        $lists = [];
        foreach ($reply->blocks as $block) {
            if ($block['type'] === 'list') {
                $lists[$block['title']] = $block['items'];
            }
        }

        return $lists;
    }

    /** @return array<string, int> the dashboard's figures, under Linda's labels */
    private function dashboardFigures(User $user): array
    {
        $data = $this->actingAs($user)->getJson('/api/credit-scoring/dashboard')->assertOk()->json('data');
        $levels = collect($data['risk_distribution'])->pluck('count', 'risk_level');

        return [
            'Scored Members' => $data['total_scored_borrowers'],
            'Very Low Risk' => $levels['very_low'],
            'Low Risk' => $levels['low'],
            'Moderate Risk' => $levels['moderate'],
            'Elevated Risk' => $levels['elevated'],
            'High Risk' => $levels['high'],
            'Very High Risk' => $levels['very_high'],
            'Insufficient Data' => $data['insufficient_data_count'],
            'Scores Due for Review' => $data['scores_due_for_review'],
        ];
    }

    private function assertNothingPersonal(string $json, string $case): void
    {
        foreach ($this->members as $member) {
            $this->assertStringNotContainsString($member->first_name, $json, $case);
            $this->assertStringNotContainsString($member->last_name, $json, $case);
            $this->assertStringNotContainsString((string) $member->borrower_code, $json, $case);
        }

        foreach (self::SCORES as $score) {
            $this->assertDoesNotMatchRegularExpression('/(?<!\d)'.$score.'(?!\d)/', $json, $case);
        }
    }

    public function test_the_counts_equal_the_credit_scoring_dashboard(): void
    {
        $cases = [
            'head office' => $this->headOffice(),
            'Main Branch' => $this->branchAnalyst(),
            // Flags are member details: without borrowers:view only band drops count, as on the dashboard.
            'no member details' => $this->caller(['credit_scoring:view']),
        ];

        foreach ($cases as $case => $user) {
            $reply = $this->ask($user, 'How many members are high risk?');

            $this->assertSame('answered', $reply->outcome, $case);
            $this->assertSame('credit_scoring_summary', $reply->intent, $case);
            $this->assertSame('CreditScoringSummaryAnswer', $reply->answerFunction, $case);
            $this->assertSame($this->dashboardFigures($user), $this->stats($reply), $case);
            $this->assertSame([], $reply->memberIds, $case);
            $this->assertSame([], $reply->loanIds, $case);
        }

        $this->assertSame(2, $this->stats($this->ask($cases['head office']))['Scores Due for Review']);
        $this->assertSame(1, $this->stats($this->ask($cases['no member details']))['Scores Due for Review']);
    }

    public function test_the_answer_reads_the_counts_in_words(): void
    {
        $reply = $this->ask($this->headOffice(), 'How many members are high risk?');

        $this->assertSame(
            LindaWording::asOfToday().', 5 members have a credit score across all branches: 1 Very Low Risk, 1 Low Risk, '
            .'1 Moderate Risk, 0 Elevated Risk, 1 High Risk and 1 Very High Risk. 1 more member has too little history to score. '
            .'2 members are due for review: an open hard flag, or a score at least one band below the previous one. '
            .'Each branch\'s counts are listed below.',
            $reply->answer,
        );
    }

    public function test_without_member_details_the_answer_says_flags_are_left_out(): void
    {
        $reply = $this->ask($this->caller(['credit_scoring:view']), 'Which members are due for review?');

        $this->assertStringStartsWith('As of today, '.LindaWording::today().', 1 member is due for review', $reply->answer);
        $this->assertStringContainsString('Open hard flags aren\'t counted, since they are member details you can\'t view.', $reply->answer);
    }

    public function test_the_score_bands_are_the_scorecards_own(): void
    {
        $reply = $this->ask($this->headOffice(), 'What are the score bands?');
        $bands = app(ScorecardEditor::class)->config()['risk_bands'];

        $rows = $this->lists($reply)['Score bands'];
        $this->assertSame(array_column($bands, 'label'), array_column($rows, 'title'));
        $this->assertSame(['90 to 100', '80 to 89', '70 to 79', '60 to 69', '40 to 59', '0 to 39'], array_column($rows, 'subtitle'));
        $this->assertStringStartsWith(
            'The score bands are Very Low Risk 90 to 100, Low Risk 80 to 89, Moderate Risk 70 to 79, Elevated Risk 60 to 69, High Risk 40 to 59 and Very High Risk 0 to 39.',
            $reply->answer,
        );
    }

    public function test_the_score_bands_need_the_scorecard_permission_too(): void
    {
        // The scorecard API needs credit_scoring:settings; a view-only user gets the counts only.
        $viewOnly = $this->caller(['credit_scoring:view', 'borrowers:view']);
        $reply = $this->ask($viewOnly, 'What are the score bands?');

        $this->assertSame('answered', $reply->outcome);
        $this->assertArrayNotHasKey('Score bands', $this->lists($reply));
        $this->assertStringNotContainsString('score bands', $reply->answer);
        $this->assertStringNotContainsString(' to 100', json_encode($reply->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertStringStartsWith('As of today, '.LindaWording::today().', 4 members have a credit score for '.$this->mainBranch()->name, $reply->answer);
        $this->assertSame($this->dashboardFigures($viewOnly), $this->stats($reply));

        $this->assertArrayHasKey('Score bands', $this->lists($this->ask($this->caller(['credit_scoring:view', 'credit_scoring:settings']), 'What are the score bands?')));
    }

    public function test_scored_members_counts_every_scored_row_as_the_dashboard_does(): void
    {
        // A scored row whose level is none of the six still counts, on the dashboard and here.
        CreditScoreMember::query()->where('borrower_id', $this->members['ben']->id)->update(['risk_level' => null]);
        $head = $this->headOffice();
        $stats = $this->stats($this->ask($head));

        $this->assertSame(5, $stats['Scored Members']);
        $this->assertSame($this->dashboardFigures($head)['Scored Members'], $stats['Scored Members']);
        $this->assertSame(0, $stats['Low Risk']);
    }

    public function test_a_named_branch_and_a_branch_user_narrow_it(): void
    {
        $head = $this->headOffice();
        $far = $this->ask($head, 'Risk levels in Far branch', $this->farBranch()->name);
        $row = collect($this->actingAs($head)->getJson('/api/credit-scoring/dashboard')->json('data.branch_risk'))->firstWhere('branch_id', $this->farBranch()->id);
        $levels = collect($row['risk_distribution'])->pluck('count', 'risk_level');

        $this->assertSame($row['members_scored'], $this->stats($far)['Scored Members']);
        $this->assertSame($levels['very_high'], $this->stats($far)['Very High Risk']);
        $this->assertSame(1, $this->stats($far)['Very High Risk']);
        $this->assertSame(0, $this->stats($far)['Scores Due for Review']);
        $this->assertStringContainsString('for '.$this->farBranch()->name, $far->answer);
        $this->assertArrayNotHasKey('By branch', $this->lists($far));

        $main = $this->ask($head, 'Risk levels in Main', $this->mainBranch()->name);
        $this->assertSame(4, $this->stats($main)['Scored Members']);
        $this->assertSame(1, $this->stats($main)['Insufficient Data']);
        $this->assertSame(2, $this->stats($main)['Scores Due for Review']);

        // A branch user never reaches another branch: it reads as unknown.
        $branchUser = $this->branchAnalyst();
        $this->assertSame('no_branch', $this->ask($branchUser, 'Risk levels in Far branch', $this->farBranch()->name)->outcome);
        $own = $this->ask($branchUser);
        $this->assertSame(0, $this->stats($own)['Very High Risk']);
        $this->assertArrayNotHasKey('By branch', $this->lists($own));
    }

    public function test_head_office_gets_each_branchs_counts_and_no_names(): void
    {
        $rows = $this->lists($this->ask($this->headOffice(), 'Which branch has the most high risk members?'))['By branch'];

        $this->assertSame([$this->farBranch()->name, $this->mainBranch()->name], array_column($rows, 'title'));
        $far = array_column($rows[0]['fields'], 'value', 'label');
        $this->assertSame(1, $far['Scored Members']);
        $this->assertSame(1, $far['Very High Risk']);
        $this->assertSame(0, $far['High Risk']);
    }

    public function test_it_needs_credit_scoring_view(): void
    {
        $denied = $this->ask($this->caller(['loans:view', 'borrowers:view', 'branches:view_all']), 'How many members are high risk?');

        $this->assertSame('denied', $denied->outcome);
        $this->assertSame('credit_scoring_summary', $denied->intent);
        $this->assertSame([], $denied->blocks);
        $this->assertSame([], $denied->links);
        $this->assertDoesNotMatchRegularExpression('/\d/', $denied->answer);
    }

    public function test_it_links_to_the_credit_scoring_dashboard(): void
    {
        $this->assertSame([['label' => 'Open Credit Scoring Dashboard', 'url' => '/credit-scoring']], $this->ask($this->headOffice())->links);
    }

    public function test_a_period_is_answered_as_of_today(): void
    {
        $reply = $this->ask($this->headOffice(), 'High risk members last month', null, LindaPeriod::named('last_month'));

        $this->assertStringEndsWith(LindaWording::todayOnly(), $reply->answer);
    }

    public function test_no_name_code_or_score_reaches_the_reply_or_the_log(): void
    {
        foreach (['How many members are high risk?', 'Which members are due for review?', 'What are the score bands?'] as $question) {
            $this->assertNothingPersonal(json_encode($this->ask($this->headOffice(), $question)->toArray(), JSON_UNESCAPED_UNICODE), $question);
        }

        config()->set('linda.enabled', true);
        $user = $this->headOffice();
        $user->givePermissionTo('linda:use');

        $reply = $this->actingAs($user)->postJson('/api/linda/chat', ['message' => 'Which members are due for review?'])->assertOk()->json('data');

        $this->assertSame('credit_scoring_summary', $reply['intent']);
        $log = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame(LindaQuestion::SOURCE_MATCHER, $log->source);
        $this->assertSame('credit_scoring_summary', $log->intent);
        $this->assertSame('CreditScoringSummaryAnswer', $log->answer_function);
        $this->assertEmpty($log->member_ids);
        // Ids, times and durations are bookkeeping numbers, never a member's score.
        $logged = json_encode(Arr::except($log->getAttributes(), ['id', 'user_id', 'asked_at', 'duration_ms', 'created_at', 'updated_at']), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('due for review', $logged);
        $this->assertNothingPersonal($logged, 'the log');
    }

    public function test_the_open_question_pack_carries_the_counts_only(): void
    {
        $pack = app(LindaFacts::class)->build($this->headOffice(), LindaPeriod::named('this_month'), null, [LindaIntent::CreditScoringSummary]);
        $topics = $pack['facts']['topics'];

        $this->assertSame(['credit_scoring_summary'], array_column($topics, 'topic'));
        $this->assertSame(array_keys($this->dashboardFigures($this->headOffice())), array_column($topics[0]['figures'], 'label'));
        $json = json_encode($pack['facts'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(' to 100', $json);
        $this->assertNothingPersonal($json, 'the pack');

        config()->set('linda.open_questions', true);
        $this->assertContains('credit_scoring_summary', LindaPrompt::schema()['properties']['topics']['items']['enum']);
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function questions(): array
    {
        return [
            'en high risk' => ['How many members are high risk?', null],
            'en by risk level' => ['Show the members by risk level', null],
            'en due for review' => ['Which members are due for review?', null],
            'en scores due' => ['How many scores are due for review?', null],
            'en bands' => ['What are the score bands?', null],
            'en band range' => ['What is the credit score range for high risk?', null],
            'en summary in Main' => ['Credit scoring summary in Main branch', 'main'],
            'en which branch' => ['Which branch has the most high risk members?', null],
            'en today' => ['Credit risk levels today', null],
            'tl high risk' => ['Ilan ang mga miyembro na high risk?', null],
            'tl mataas ang risk' => ['Ilang miyembro ang mataas ang risk sa Main?', 'main'],
            'tl i-review' => ['Ilang miyembro ang kailangang i-review?', null],
            'tl score band' => ['Ano ang mga score band?', null],
            'ceb taas og risk' => ['Pila ka miyembro ang taas og risk?', null],
            'ceb kinahanglan i-review' => ['Pila ang kinahanglan i-review nga score?', null],
            'ceb risk level sa Main' => ['Unsa ang risk level sa mga miyembro sa Main?', 'main'],
            'ceb asa nga branch' => ['Asa nga branch ang daghan og high risk?', null],
        ];
    }

    #[DataProvider('questions')]
    public function test_credit_scoring_questions_route_to_the_credit_scoring_intent(string $question, ?string $branch): void
    {
        $match = LindaMatcher::match($question);

        $this->assertNotNull($match, $question);
        $this->assertSame(LindaIntent::CreditScoringSummary, $match->intent, $question);
        $this->assertNull($match->period, $question);
        $this->assertSame($branch, $match->branchName, $question);
    }

    public function test_other_questions_keep_their_own_routes(): void
    {
        // A page, a PAR figure, a loan review and a bare "risk" are not this topic.
        $this->assertNull(LindaMatcher::match('Where is credit scoring?'));
        $this->assertSame(LindaIntent::PortfolioSummary, LindaMatcher::match('What is our portfolio at risk?')?->intent);
        $this->assertNull(LindaMatcher::match('How many loans need review?'));
        $this->assertNull(LindaMatcher::match('How many loans are at risk?'));
        $this->assertSame(LindaIntent::BorrowerSummary, LindaMatcher::match('How many active members do we have?')?->intent);

        // One member's score is a lookup, never this topic.
        $this->assertNull(LindaMatcher::match('What is the credit score of Juan dela Cruz?'));
        $this->assertNull(LindaMatcher::match('Credit score of BRW-000123'));
    }

    public function test_the_classifier_learns_the_intent(): void
    {
        $this->assertContains(LindaIntent::CreditScoringSummary, LindaIntent::offered());
        $this->assertStringContainsString('- credit_scoring_summary: ', LindaPrompt::system());
        $this->assertContains('credit_scoring_summary', LindaPrompt::schema()['properties']['intent']['enum']);
        $this->assertFalse(LindaIntent::CreditScoringSummary->takesPeriod());

        foreach (['Bakit maraming high risk na members?' => LindaIntent::CreditScoringSummary, 'Kumusta ang credit score sa atong mga sakop?' => LindaIntent::CreditScoringSummary] as $question => $intent) {
            $this->assertSame($intent, (new FakeModel)->understand(new LindaModelRequest($question, [], 'claude-haiku-5-5'))->intent, $question);
        }
    }
}

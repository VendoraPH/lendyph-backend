<?php

namespace Tests\Feature\Phase6\Amla\Screening;

use App\Models\AmlaScreeningMatch;
use App\Models\CoMaker;
use App\Models\GCashNonMember;
use App\Services\Amla\Screening\Matcher;
use App\Services\Amla\Screening\NameNormaliser;
use App\Services\Amla\Screening\Screener;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The matcher and the screening runs (AMLA design §4.8, §4.11; task §11).
 * Names are compared after the walk-in normalisation plus accents and
 * punctuation, in any order, exactly or within one letter on longer words. A
 * different birth year lowers the score; a similar name alone never decides.
 * A match is stored only at or above the threshold, with its score and
 * reason, and is never confirmed by the system. Members, walk-ins and
 * co-makers are screened; a new list version rescreens everyone, and new or
 * changed subjects are screened within a minute, while monitoring is on.
 */
class ScreeningMatcherTest extends TestCase
{
    use ScreeningTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        config(['amla.monitoring_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_names_are_normalised_like_walk_ins_plus_accents_and_punctuation(): void
    {
        $this->assertSame('jose dela pena', NameNormaliser::normalise('  JOSÉ  dela  Peña. '));
        $this->assertSame(['dela', 'jose', 'pena'], NameNormaliser::tokens('Peña, José DELA'));
        $this->assertSame('o brien', NameNormaliser::normalise("O'Brien"));
    }

    public function test_the_score_is_100_for_the_same_name_in_any_order_and_drops_below_the_threshold_for_part_of_it(): void
    {
        $matcher = app(Matcher::class);

        $this->assertSame(100, $matcher->score(['Juan Dela Cruz'], null, ['JUAN DELA CRUZ'], [])['score']);
        $this->assertSame(100, $matcher->score(['Cruz, Juan Dela'], null, ['JUAN DELA CRUZ'], [])['score']);
        $this->assertSame(67, $matcher->score(['Juan Cruz'], null, ['JUAN DELA CRUZ'], [])['score']);

        $nearly = $matcher->score(['Mohammed Rahman Khan'], null, ['MUHAMMED RAHMAN KHAN'], []);
        $this->assertSame(Matcher::THRESHOLD, 85);
        $this->assertGreaterThanOrEqual(85, $nearly['score'], 'one letter off on a long word still matches');
        $this->assertContains('"mohammed" is one letter from "muhammed"', $nearly['reasons']);
    }

    public function test_a_different_birth_year_lowers_the_score_and_the_same_one_is_said(): void
    {
        $matcher = app(Matcher::class);

        $different = $matcher->score(['Juan Dela Cruz'], 1990, ['JUAN DELA CRUZ'], [1975]);
        $this->assertSame(70, $different['score']);
        $this->assertContains('Birth year differs (1990 against 1975)', $different['reasons']);

        $same = $matcher->score(['Juan Dela Cruz'], 1975, ['JUAN DELA CRUZ'], [1975]);
        $this->assertSame(100, $same['score']);
        $this->assertContains('Birth year matches (1975)', $same['reasons']);
    }

    public function test_one_letter_off_on_every_word_lands_exactly_on_the_threshold(): void
    {
        $matcher = app(Matcher::class);

        $this->assertSame(85, $matcher->score(['Mohammed Rahmann'], null, ['MUHAMMED RAHMAN'], [])['score']);
        $this->assertSame(67, $matcher->score(['Ana Cruz'], null, ['ANA DELA CRUZ'], [])['score']);
        $this->assertSame(0, $matcher->score(['Ana'], null, ['ANNA'], [])['score'], 'a short word is never matched one letter off');
        $this->assertSame(67, $matcher->score(['Jose Rizal'], null, ['J. MERCADO', 'JOSE RIZAL MERCADO'], [])['score'], 'the best of the listed names counts');
    }

    public function test_matches_are_kept_at_the_threshold_and_above_never_below_and_never_confirmed(): void
    {
        $at = $this->member(['first_name' => 'Mohammed', 'middle_name' => null, 'last_name' => 'Rahmann']);
        $above = $this->member(['first_name' => 'Juan', 'middle_name' => 'Dela', 'last_name' => 'Cruz', 'birthdate' => '1975-03-04']);
        $below = $this->member(['first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Cruz']);
        $screener = $this->screener();
        $before = $this->outsideAmlaCounts();

        $this->upload($screener, 'un', $this->unXml([
            ['ref' => 'QDi.001', 'first' => 'JUAN', 'second' => 'DELA', 'third' => 'CRUZ', 'dob' => '1975-03-04'],
            ['ref' => 'QDi.009', 'first' => 'MUHAMMED', 'second' => 'RAHMAN'],
        ]), 'list.xml')->assertCreated();
        Artisan::call('amla:screen');

        $matches = AmlaScreeningMatch::query()->get()->keyBy('subject_id');
        $this->assertSame(100, $matches[$above->id]->score);
        $this->assertSame('pending', $matches[$above->id]->status, 'nothing is confirmed by the system');
        $this->assertContains('Birth year matches (1975)', $matches[$above->id]->reasons);
        $this->assertSame(85, $matches[$above->id]->threshold);
        $this->assertSame(85, $matches[$at->id]->score, 'exactly at the threshold is kept');
        $this->assertFalse($matches->has($below->id), '67 is below the threshold');
        $this->assertSame(['pending'], AmlaScreeningMatch::query()->distinct()->pluck('status')->all());
        $this->assertNothingWrittenOutsideAmla($before);
    }

    public function test_walk_ins_and_co_makers_are_screened_too(): void
    {
        $walkIn = GCashNonMember::factory()->create(['full_name' => 'Pedro Penduko']);
        $member = $this->member();
        $coMaker = CoMaker::query()->create([
            'borrower_id' => $member->id, 'first_name' => 'Pedro', 'last_name' => 'Penduko', 'relationship_to_borrower' => 'sibling', 'status' => 'active',
        ]);

        $this->upload($this->screener(), 'amlc', $this->csvList([['AMLC-1', 'individual', 'Penduko Pedro', '', '', '']]), 'a.csv')->assertCreated();
        Artisan::call('amla:screen');

        $this->assertSame(1, AmlaScreeningMatch::query()->where('subject_type', 'walk_in')->where('subject_id', $walkIn->id)->count());
        $this->assertSame(1, AmlaScreeningMatch::query()->where('subject_type', 'co_maker')->where('subject_id', $coMaker->id)->count());
    }

    public function test_a_new_version_rescreens_everyone_without_repeating_a_match_for_the_same_listing(): void
    {
        $member = $this->member(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes']);
        $screener = $this->screener();

        $this->upload($screener, 'internal', $this->csvList([['I-1', 'individual', 'Ana Reyes', '', '', '']]), 'v1.csv')->assertCreated();
        Artisan::call('amla:screen');
        $this->assertSame(1, AmlaScreeningMatch::query()->where('subject_id', $member->id)->count());

        $this->upload($screener, 'internal', $this->csvList([['I-1', 'individual', 'Ana Reyes', '', '', ''], ['I-2', 'individual', 'Reyes Ana', 'Annie R', '', '']]), 'v2.csv')->assertCreated();
        Artisan::call('amla:screen');

        $this->assertSame(2, AmlaScreeningMatch::query()->where('subject_id', $member->id)->count(), 'I-1 is the same listing; I-2 is new');
        $this->assertFalse(app(Screener::class)->pendingRescreens()->isNotEmpty(), 'each version is rescreened once');
    }

    public function test_a_new_or_changed_subject_is_screened_on_the_next_minute(): void
    {
        $this->upload($this->screener(), 'internal', $this->csvList([['I-1', 'individual', 'Ana Reyes', '', '', '']]), 'v1.csv')->assertCreated();
        Carbon::setTestNow('2026-11-21 09:00:00');
        Artisan::call('amla:screen');

        Carbon::setTestNow('2026-11-21 09:00:30');
        $member = $this->member(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes']);
        $other = $this->member(['first_name' => 'Ben', 'middle_name' => null, 'last_name' => 'Torres']);

        Carbon::setTestNow('2026-11-21 09:01:10');
        Artisan::call('amla:screen');
        $this->assertSame(1, AmlaScreeningMatch::query()->where('subject_id', $member->id)->count());

        Carbon::setTestNow('2026-11-21 09:01:40');
        $other->forceFill(['first_name' => 'Ana', 'last_name' => 'Reyes'])->save();
        Carbon::setTestNow('2026-11-21 09:02:20');
        Artisan::call('amla:screen');
        $this->assertSame(1, AmlaScreeningMatch::query()->where('subject_id', $other->id)->count());
    }

    public function test_nothing_is_screened_while_monitoring_is_off(): void
    {
        config(['amla.monitoring_enabled' => false]);
        $this->member(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes']);
        $this->upload($this->screener(), 'internal', $this->csvList([['I-1', 'individual', 'Ana Reyes', '', '', '']]), 'v1.csv')->assertCreated();

        Artisan::call('amla:screen');

        $this->assertSame(0, AmlaScreeningMatch::query()->count());
        $this->assertTrue(app(Screener::class)->pendingRescreens()->isNotEmpty(), 'the rescreen waits for monitoring');
    }
}

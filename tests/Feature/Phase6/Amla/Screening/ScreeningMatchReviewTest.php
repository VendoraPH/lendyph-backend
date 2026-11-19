<?php

namespace Tests\Feature\Phase6\Amla\Screening;

use App\Exceptions\AmlaRecordIsAppendOnlyException;
use App\Models\AmlaLogEntry;
use App\Models\AmlaScreeningMatch;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Reviewing a potential match (task §11): a person confirms it or clears it
 * as a false positive, with a reason, once. Every list and view of a match is
 * written to the AMLA log, by id only. Needs `amla:screening` and head-office
 * access; branch users never see a match.
 */
class ScreeningMatchReviewTest extends TestCase
{
    use ScreeningTestHelpers, SetupLendyPH;

    private AmlaScreeningMatch $match;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        config(['amla.monitoring_enabled' => true]);
        $this->member(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes', 'birthdate' => '1980-02-02']);
        $this->upload($this->screener(), 'internal', $this->csvList([['I-1', 'individual', 'Ana Reyes', '', '1980', '']]), 'v1.csv')->assertCreated();
        Artisan::call('amla:screen');
        $this->match = AmlaScreeningMatch::query()->sole();
    }

    public function test_the_list_and_each_view_are_logged_by_id_only(): void
    {
        $screener = $this->screener();
        $before = $this->outsideAmlaCounts();

        $rows = $this->actingAs($screener)->getJson('/api/amla/screening/matches?status=pending')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Ana Reyes', $rows[0]['subject']['name']);
        $this->assertSame('Pending review', $rows[0]['status_words']);

        $match = $this->actingAs($screener)->getJson("/api/amla/screening/matches/{$this->match->id}")->assertOk()->json('data');
        $this->assertSame(100, $match['score']);
        $this->assertSame('Internal list', $match['entry']['source_words']);
        $this->assertSame('I-1', $match['entry']['reference']);
        $this->assertContains('Birth year matches (1980)', $match['reasons']);

        $viewed = AmlaLogEntry::query()->where('action', 'screening_match_viewed')->sole();
        $this->assertSame('screening_match', $viewed->subject_type);
        $this->assertSame($this->match->id, $viewed->subject_id);
        $this->assertStringNotContainsString('Reyes', json_encode([$viewed->details, AmlaLogEntry::query()->where('action', 'screening_matches_listed')->sole()->details]));
        $this->assertNothingWrittenOutsideAmla($before);
    }

    public function test_a_person_confirms_or_clears_a_match_with_a_reason_once(): void
    {
        $screener = $this->screener();

        $this->actingAs($screener)->postJson("/api/amla/screening/matches/{$this->match->id}/clear")->assertUnprocessable()->assertJsonValidationErrors(['reason']);

        $cleared = $this->actingAs($screener)->postJson("/api/amla/screening/matches/{$this->match->id}/clear", ['reason' => 'Different person: listed one is from Mindanao, born 1980 abroad.'])
            ->assertOk()->json('data');
        $this->assertSame('cleared', $cleared['status']);
        $this->assertSame('Cleared as a false positive', $cleared['status_words']);
        $this->assertSame($screener->id, $cleared['decided_by']['id']);
        $this->assertNotSame('Different person: listed one is from Mindanao, born 1980 abroad.', $this->match->fresh()->getRawOriginal('decision_reason'));

        $this->actingAs($screener)->postJson("/api/amla/screening/matches/{$this->match->id}/confirm", ['reason' => 'Changed my mind'])
            ->assertConflict()->assertJsonPath('message', 'This match is already cleared as a false positive.');

        $logged = AmlaLogEntry::query()->where('action', 'screening_match_cleared')->sole();
        $this->assertEqualsCanonicalizing(['match_id'], array_keys($logged->details));
    }

    public function test_a_decided_match_is_kept_as_written(): void
    {
        $this->actingAs($this->screener())->postJson("/api/amla/screening/matches/{$this->match->id}/confirm", ['reason' => 'Same person: birth date and ID match.'])
            ->assertOk()->assertJsonPath('data.status', 'confirmed');

        foreach ([
            fn () => $this->match->fresh()->forceFill(['status' => 'pending'])->save(),
            fn () => $this->match->fresh()->delete(),
            fn () => AmlaScreeningMatch::query()->update(['status' => 'cleared']),
        ] as $write) {
            try {
                $write();
                $this->fail('A decided match was changed.');
            } catch (AmlaRecordIsAppendOnlyException) {
                $this->assertSame('confirmed', $this->match->fresh()->status);
            }
        }
    }

    public function test_matches_need_the_screening_key_and_head_office_access_before_any_lookup(): void
    {
        foreach ([$this->amlaUser(['amla:screening']), $this->amlaUser(['amla:cdd', 'amla:sanctions', 'branches:view_all'])] as $user) {
            $this->actingAs($user)->getJson('/api/amla/screening/matches')->assertForbidden();

            foreach ([$this->match->id, 999999] as $id) {
                $this->actingAs($user)->getJson("/api/amla/screening/matches/{$id}")->assertForbidden();
                $this->actingAs($user)->postJson("/api/amla/screening/matches/{$id}/confirm", ['reason' => 'x'])->assertForbidden();
            }
        }

        $this->actingAs($this->screener())->getJson('/api/amla/screening/matches/999999')->assertNotFound();
        $this->actingAs($this->screener())->getJson('/api/amla/screening/matches/1234567890123456789')->assertNotFound();
    }
}

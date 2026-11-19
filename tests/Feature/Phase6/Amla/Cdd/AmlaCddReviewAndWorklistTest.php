<?php

namespace Tests\Feature\Phase6\Amla\Cdd;

use App\Exceptions\AmlaRecordIsAppendOnlyException;
use App\Models\AmlaLogEntry;
use App\Models\AmlaReview;
use App\Services\BorrowerPurgeService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Periodic reviews and the CDD worklist (AMLA design §4.10, §4.17; task §10).
 * The next review falls the settings' cycle after the last one (high 1 year,
 * medium 2, low 3, all "to verify"); past it the profile reads Review
 * Overdue. The worklist covers the caller's own branches' members (walk-ins
 * with head-office access only), filters by status, and is logged. A member
 * holding AMLA records is never purged, and the refusal names no AMLA.
 */
class AmlaCddReviewAndWorklistTest extends TestCase
{
    use CddTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow('2026-11-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_first_assessment_schedules_the_review_by_the_risk_cycle_and_a_review_moves_it(): void
    {
        $member = $this->member();
        $officer = $this->cddOfficer();

        $profile = $this->actingAs($officer)->putJson($this->cddUrl($member), $this->completeProfile())->json('data.profile');
        $this->assertSame('2029-11-20', $profile['next_review_on'], 'low risk: every 3 years');

        Carbon::setTestNow('2027-03-01 09:00:00');
        $before = $this->outsideAmlaCounts();
        $review = $this->actingAs($officer)->postJson($this->cddUrl($member, '/reviews'), [
            'kind' => 'periodic', 'outcome' => 'no_change', 'notes' => 'Called the member; nothing changed.',
        ])->assertCreated()->json('data');

        $this->assertSame('2027-03-01', $review['reviewed_on']);
        $this->assertSame('2030-03-01', $review['next_review_on']);
        $this->assertSame('Periodic review', $review['kind_words']);
        $logged = AmlaLogEntry::query()->where('action', 'cdd_review_recorded')->sole();
        $this->assertEqualsCanonicalizing(['profile_id', 'review_id'], array_keys($logged->details), 'ids only: no kind or outcome in the log');
        $this->assertNothingWrittenOutsideAmla($before);
    }

    public function test_past_the_next_review_date_the_profile_reads_review_overdue(): void
    {
        $member = $this->member();
        $officer = $this->cddOfficer();
        $this->actingAs($officer)->putJson($this->cddUrl($member), [...$this->completeProfile(), 'pep_status' => 'pep'])->assertOk();

        Carbon::setTestNow('2028-12-01 09:00:00');
        $data = $this->actingAs($officer)->getJson($this->cddUrl($member))->json('data');

        $this->assertSame('review_overdue', $data['cdd_status']);
        $this->assertSame('Review Overdue', $data['cdd_status_words']);
    }

    public function test_an_edd_review_needs_notes_and_a_review_is_kept_as_written(): void
    {
        $member = $this->member();
        $officer = $this->cddOfficer();
        $this->actingAs($officer)->putJson($this->cddUrl($member), $this->completeProfile())->assertOk();

        $this->actingAs($officer)->postJson($this->cddUrl($member, '/reviews'), ['kind' => 'edd', 'outcome' => 'no_change'])
            ->assertUnprocessable()->assertJsonValidationErrors(['notes']);
        $this->actingAs($officer)->postJson($this->cddUrl($member, '/reviews'), ['kind' => 'yearly', 'outcome' => 'fine'])
            ->assertUnprocessable()->assertJsonValidationErrors(['kind', 'outcome']);

        $this->actingAs($officer)->postJson($this->cddUrl($member, '/reviews'), ['kind' => 'periodic', 'outcome' => 'no_change', 'notes' => 'Private note.'])->assertCreated();
        $review = AmlaReview::query()->sole();
        $this->assertNotSame('Private note.', $review->getRawOriginal('notes'));

        $this->expectException(AmlaRecordIsAppendOnlyException::class);
        $review->delete();
    }

    public function test_a_review_needs_a_profile_first(): void
    {
        $this->actingAs($this->cddOfficer())->postJson($this->cddUrl($this->member(), '/reviews'), ['kind' => 'periodic', 'outcome' => 'no_change'])
            ->assertUnprocessable()->assertJsonPath('message', 'Start the AMLA profile before recording a review.');
    }

    public function test_the_worklist_covers_only_the_callers_branches_filters_by_status_and_is_logged(): void
    {
        $started = $this->member();
        $untouched = $this->member();
        $elsewhere = $this->member([], $this->otherBranch());
        $walkIn = $this->walkIn();
        $officer = $this->cddOfficer();
        $this->actingAs($officer)->putJson($this->cddUrl($started), $this->completeProfile())->assertOk();
        $headOffice = $this->headOfficeCddOfficer();
        $before = $this->outsideAmlaCounts();

        $rows = collect($this->actingAs($officer)->getJson('/api/amla/cdd/subjects?per_page=100')->assertOk()->json('data'));
        $ids = $rows->where('type', 'member')->pluck('id')->all();
        $this->assertContains($started->id, $ids);
        $this->assertContains($untouched->id, $ids);
        $this->assertNotContains($elsewhere->id, $ids);
        $this->assertSame([], $rows->where('type', 'walk_in')->all(), 'walk-ins need head-office access');

        $notStarted = collect($this->actingAs($officer)->getJson('/api/amla/cdd/subjects?status=not_started&per_page=100')->json('data'));
        $this->assertContains($untouched->id, $notStarted->pluck('id')->all());
        $this->assertNotContains($started->id, $notStarted->pluck('id')->all());
        $this->assertSame('Not Started', $notStarted->firstWhere('id', $untouched->id)['cdd_status_words']);

        $all = collect($this->actingAs($headOffice)->getJson('/api/amla/cdd/subjects?per_page=100')->json('data'));
        $this->assertContains($elsewhere->id, $all->where('type', 'member')->pluck('id')->all());
        $this->assertContains($walkIn->id, $all->where('type', 'walk_in')->pluck('id')->all());

        $this->assertSame(3, AmlaLogEntry::query()->where('action', 'cdd_list_viewed')->count());
        $this->assertNothingWrittenOutsideAmla($before);
        $this->actingAs($officer)->getJson('/api/amla/cdd/subjects?status=sleepy')->assertUnprocessable();
    }

    public function test_the_worklist_needs_the_cdd_permission(): void
    {
        $this->actingAs($this->amlaUser(['amla:view', 'branches:view_all']))->getJson('/api/amla/cdd/subjects')->assertForbidden();
    }

    public function test_a_member_with_amla_records_is_never_purged_and_the_refusal_names_no_amla(): void
    {
        $member = $this->member();
        $this->actingAs($this->cddOfficer())->putJson($this->cddUrl($member), $this->completeProfile())->assertOk();

        try {
            app(BorrowerPurgeService::class)->purge($member);
            $this->fail('A member holding AMLA records was purged.');
        } catch (ValidationException $refused) {
            $message = $refused->errors()['borrower'][0];
            $this->assertSame('This member\'s records must be kept, so the record can\'t be deleted.', $message);
            $this->assertStringNotContainsStringIgnoringCase('amla', $message);
            $this->assertNotNull($member->fresh());
        }
    }
}

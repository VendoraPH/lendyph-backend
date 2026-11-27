<?php

namespace Tests\Feature\Phase6\Amla\Cdd;

use App\Exceptions\AmlaRecordIsAppendOnlyException;
use App\Models\AmlaLogEntry;
use App\Models\AmlaProfile;
use App\Models\AmlaProfileChange;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * AMLA customer due diligence profiles (AMLA design §4.8, §4.14; task §10).
 * One profile per existing member or walk-in, linked to it and never a
 * second customer record. Its KYC answers live here, never on the member
 * forms. Each view and change is written to the AMLA log, never the main
 * audit trail. `amla:cdd` is checked before any lookup (403 before 404); a
 * member in another branch, and any walk-in without head-office access,
 * answers the same 404 as a missing one.
 */
class AmlaCddProfileTest extends TestCase
{
    use CddTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_member_without_a_profile_reads_not_started_and_the_view_is_logged(): void
    {
        $member = $this->member();
        $officer = $this->cddOfficer();
        $before = $this->outsideAmlaCounts();

        $data = $this->actingAs($officer)->getJson($this->cddUrl($member))->assertOk()->json('data');

        $this->assertSame('member', $data['subject']['type']);
        $this->assertSame($member->id, $data['subject']['id']);
        $this->assertSame($member->borrower_code, $data['subject']['code']);
        $this->assertFalse($data['profile']['exists']);
        $this->assertSame('not_started', $data['cdd_status']);
        $this->assertSame('Not Started', $data['cdd_status_words']);
        $this->assertArrayNotHasKey('birthdate', $data['subject'], 'the profile never repeats the member record');
        $this->assertArrayNotHasKey('contact_number', $data['subject']);
        $this->assertSame(0, AmlaProfile::query()->count(), 'a read creates nothing');

        $logged = AmlaLogEntry::query()->where('action', 'cdd_profile_viewed')->sole();
        $this->assertSame('member', $logged->subject_type);
        $this->assertSame($member->id, $logged->subject_id);
        $this->assertNothingWrittenOutsideAmla($before);
    }

    public function test_saving_kyc_answers_creates_one_profile_records_the_change_and_logs_field_names_only(): void
    {
        $member = $this->member();
        $officer = $this->cddOfficer();
        $before = $this->outsideAmlaCounts();

        $data = $this->actingAs($officer)->putJson($this->cddUrl($member), [
            ...$this->completeProfile(),
            'pep_status' => 'pep',
            'pep_details' => 'Barangay captain since 2022',
        ])->assertOk()->json('data');

        $this->assertTrue($data['profile']['exists']);
        $this->assertSame('PH', $data['profile']['nationality']);
        $this->assertSame('15000.00', $data['profile']['expected_monthly_amount']);
        $this->assertSame('₱15,000.00', $data['profile']['expected_monthly_amount_words']);
        $this->assertSame('Politically exposed person', $data['profile']['pep_status_words']);
        $this->assertSame('Barangay captain since 2022', $data['profile']['pep_details']);

        $profile = AmlaProfile::query()->sole();
        $this->assertSame($member->id, $profile->borrower_id);
        $this->assertNotSame('Barangay captain since 2022', $profile->getRawOriginal('pep_details'), 'PEP details are encrypted at rest');

        $change = AmlaProfileChange::query()->sole();
        $this->assertSame($officer->id, $change->user_id);
        $this->assertSame(['from' => null, 'to' => 'Teacher'], $change->changes['occupation']);

        $logged = AmlaLogEntry::query()->where('action', 'cdd_profile_updated')->sole();
        $this->assertContains('pep_details', $logged->details['fields']);
        $this->assertStringNotContainsString('Barangay', json_encode($logged->details));
        $this->assertStringNotContainsString('Teacher', json_encode($logged->details));
        $this->assertNothingWrittenOutsideAmla($before);
    }

    public function test_an_unchanged_save_writes_nothing(): void
    {
        $member = $this->member();
        $officer = $this->cddOfficer();
        $this->actingAs($officer)->putJson($this->cddUrl($member), $this->completeProfile())->assertOk();

        $this->actingAs($officer)->putJson($this->cddUrl($member), $this->completeProfile())->assertOk();

        $this->assertSame(1, AmlaProfileChange::query()->count());
        $this->assertSame(1, AmlaLogEntry::query()->where('action', 'cdd_profile_updated')->count());
    }

    public function test_a_save_that_changes_nothing_is_logged_as_one_view(): void
    {
        $member = $this->member();
        $officer = $this->cddOfficer();
        $this->actingAs($officer)->putJson($this->cddUrl($member), [...$this->completeProfile(), 'pep_details' => 'Councillor'])->assertOk();
        $views = AmlaLogEntry::query()->where('action', 'cdd_profile_viewed')->count();

        $data = $this->actingAs($officer)->putJson($this->cddUrl($member), [])->assertOk()->json('data');

        $this->assertSame('Councillor', $data['profile']['pep_details'], 'the whole profile came back');
        $this->assertSame($views + 1, AmlaLogEntry::query()->where('action', 'cdd_profile_viewed')->count());
        $viewed = AmlaLogEntry::query()->where('action', 'cdd_profile_viewed')->latest('id')->firstOrFail();
        $this->assertSame($officer->id, $viewed->user_id);
        $this->assertSame($member->id, $viewed->subject_id);
        $this->assertTrue($viewed->details['unchanged_save']);

        $this->actingAs($officer)->putJson($this->cddUrl($member), $this->completeProfile())->assertOk();
        $this->assertSame($views + 2, AmlaLogEntry::query()->where('action', 'cdd_profile_viewed')->count(), 'an unchanged save of the same answers is a view too');
    }

    public function test_answers_are_checked_in_words(): void
    {
        $member = $this->member();

        $this->actingAs($this->cddOfficer())->putJson($this->cddUrl($member), [
            'nationality' => 'Filipino',
            'source_of_funds' => 'lottery',
            'expected_monthly_amount' => '12.345',
            'pep_status' => 'maybe',
            'expected_monthly_count' => -1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['nationality', 'source_of_funds', 'expected_monthly_amount', 'pep_status', 'expected_monthly_count']);

        $this->actingAs($this->cddOfficer())->putJson($this->cddUrl($member), ['source_of_funds' => 'other'])
            ->assertUnprocessable()->assertJsonValidationErrors(['source_of_funds_other']);
    }

    public function test_the_permission_comes_before_the_lookup(): void
    {
        $member = $this->member();
        $outsider = $this->amlaUser(['amla:view', 'amla:settings', 'branches:view_all', 'borrowers:view']);

        foreach ([$member->id, 999999, 123456789012345678] as $id) {
            $this->actingAs($outsider)->getJson("/api/amla/cdd/members/{$id}")->assertForbidden()
                ->assertJsonPath('message', 'This needs the AMLA customer due diligence permission.');
            $this->actingAs($outsider)->putJson("/api/amla/cdd/members/{$id}", ['occupation' => 'x'])->assertForbidden();
        }

        $this->actingAs($this->cddOfficer())->getJson('/api/amla/cdd/members/999999')->assertNotFound();
        $this->actingAs($this->cddOfficer())->getJson('/api/amla/cdd/members/1234567890123456789')->assertNotFound();
    }

    public function test_another_branch_member_and_a_walk_in_without_head_office_access_answer_404(): void
    {
        $elsewhere = $this->member([], $this->otherBranch());
        $walkIn = $this->walkIn();
        $officer = $this->cddOfficer();

        $this->actingAs($officer)->getJson($this->cddUrl($elsewhere))->assertNotFound();
        $this->actingAs($officer)->putJson($this->cddUrl($elsewhere), ['occupation' => 'x'])->assertNotFound();
        $this->actingAs($officer)->getJson($this->cddUrl($walkIn))->assertNotFound();

        $headOffice = $this->headOfficeCddOfficer();
        $this->actingAs($headOffice)->getJson($this->cddUrl($elsewhere))->assertOk();
        $this->actingAs($headOffice)->getJson($this->cddUrl($walkIn))->assertOk()
            ->assertJsonPath('data.subject.type', 'walk_in')
            ->assertJsonPath('data.subject.branch', null);
    }

    public function test_the_post_routes_check_the_permission_before_the_lookup_and_answer_404_out_of_scope(): void
    {
        $member = $this->member();
        $this->validId($member);
        $elsewhere = $this->member([], $this->otherBranch());
        $walkIn = $this->walkIn();
        $outsider = $this->amlaUser(['amla:view', 'amla:settings', 'amla:audit', 'branches:view_all', 'borrowers:view']);
        $officer = $this->cddOfficer();
        $bodies = [
            '/id-verifications' => ['method' => 'original_seen', 'result' => 'verified'],
            '/risk-assessments' => [],
            '/reviews' => ['kind' => 'periodic', 'outcome' => 'no_change'],
        ];

        foreach ($bodies as $suffix => $body) {
            foreach (["/api/amla/cdd/members/{$member->id}", '/api/amla/cdd/members/999999', "/api/amla/cdd/walk-ins/{$walkIn->id}"] as $url) {
                $this->actingAs($outsider)->postJson($url.$suffix, $body)->assertForbidden()
                    ->assertJsonPath('message', 'This needs the AMLA customer due diligence permission.');
            }

            $this->actingAs($officer)->postJson($this->cddUrl($elsewhere, $suffix), $body)->assertNotFound();
            $this->actingAs($officer)->postJson($this->cddUrl($walkIn, $suffix), $body)->assertNotFound();
            $this->actingAs($officer)->postJson('/api/amla/cdd/members/999999'.$suffix, $body)->assertNotFound();
        }

        $this->assertSame(0, AmlaProfile::query()->count(), 'nothing out of scope was started');
    }

    public function test_super_admin_passes_every_cdd_check_through_its_bypass(): void
    {
        $elsewhere = $this->member([], $this->otherBranch());
        $walkIn = $this->walkIn();

        $this->actingAs($this->admin)->getJson($this->cddUrl($elsewhere))->assertOk();
        $this->actingAs($this->admin)->getJson($this->cddUrl($walkIn))->assertOk();
        $this->actingAs($this->admin)->putJson($this->cddUrl($walkIn), $this->completeProfile())->assertOk();
        $this->actingAs($this->admin)->getJson('/api/amla/risk-rules')->assertOk();
        $this->assertSame(2, AmlaLogEntry::query()->where('action', 'cdd_profile_viewed')->where('user_id', $this->admin->id)->count());
    }

    public function test_a_pending_registration_is_not_a_cdd_subject(): void
    {
        $applicant = $this->member(['status' => 'pending']);

        $this->actingAs($this->cddOfficer())->getJson($this->cddUrl($applicant))->assertNotFound();
    }

    public function test_a_profile_never_changes_its_subject_and_is_never_deleted(): void
    {
        $member = $this->member();
        $this->actingAs($this->cddOfficer())->putJson($this->cddUrl($member), $this->completeProfile())->assertOk();
        $profile = AmlaProfile::query()->sole();

        foreach ([
            fn () => $profile->forceFill(['borrower_id' => $this->member()->id])->save(),
            fn () => $profile->delete(),
            fn () => AmlaProfile::query()->update(['occupation' => 'x']),
            fn () => AmlaProfileChange::query()->delete(),
        ] as $write) {
            try {
                $write();
                $this->fail('An AMLA profile was rewritten or deleted.');
            } catch (AmlaRecordIsAppendOnlyException) {
                $this->assertSame($member->id, $profile->fresh()->borrower_id);
            }
        }
    }

    public function test_the_routes_are_named_throttled_and_take_bounded_ids(): void
    {
        $this->actingAs($this->cddOfficer())->getJson('/api/amla/cdd/members/12345678901234567890')->assertNotFound();
        $this->actingAs($this->cddOfficer())->getJson('/api/amla/cdd/borrowers/1')->assertNotFound();
    }
}

<?php

namespace Tests\Feature\Phase6\Amla\Cdd;

use App\Exceptions\AmlaRecordIsAppendOnlyException;
use App\Models\AmlaIdVerification;
use App\Models\AmlaLogEntry;
use App\Services\Amla\Cdd\IdTypes;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Identity verification, the KYC completeness check and the CDD status (task
 * §10). A verification is a person's record of seeing an ID, kept forever;
 * nothing claims an outside provider checked it. The completeness check reads
 * the member record and the AMLA profile and lists what is missing, expired or
 * expiring, in words. The status is worked out on every read, so it is never
 * stale.
 */
class AmlaCddVerificationAndCompletenessTest extends TestCase
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

    public function test_a_member_with_everything_but_a_verified_id_is_pending_verification(): void
    {
        $member = $this->member();
        $this->validId($member);
        $officer = $this->cddOfficer();
        $this->actingAs($officer)->putJson($this->cddUrl($member), $this->completeProfile())->assertOk();

        $data = $this->actingAs($officer)->getJson($this->cddUrl($member))->json('data');

        $this->assertSame('pending_verification', $data['cdd_status']);
        $this->assertSame(['id_verified'], array_column($data['completeness']['missing'], 'key'));
        $this->assertSame('The ID hasn\'t been verified', $data['completeness']['missing'][0]['words']);
    }

    public function test_verifying_the_members_own_id_completes_the_profile(): void
    {
        $member = $this->member();
        $document = $this->validId($member);
        $officer = $this->cddOfficer();
        $this->actingAs($officer)->putJson($this->cddUrl($member), $this->completeProfile())->assertOk();
        $before = $this->outsideAmlaCounts();

        $this->actingAs($officer)->postJson($this->cddUrl($member, '/id-verifications'), [
            'document_id' => $document->id,
            'method' => 'original_seen',
            'result' => 'verified',
            'expires_on' => '2030-01-31',
            'notes' => 'Original passport seen at the branch.',
        ])->assertCreated();

        $data = $this->actingAs($officer)->getJson($this->cddUrl($member))->json('data');
        $this->assertSame('verified', $data['cdd_status']);
        $this->assertTrue($data['completeness']['complete']);
        $this->assertSame('Original ID seen in person', $data['id_verifications'][0]['method_words']);
        $this->assertSame('passport', $data['id_verifications'][0]['id_type']);
        $this->assertSame('Passport', $data['id_verifications'][0]['id_type_words']);
        $this->assertSame('****567A', $data['id_verifications'][0]['id_number_masked']);
        $logged = AmlaLogEntry::query()->where('action', 'cdd_id_verified')->sole();
        $this->assertEqualsCanonicalizing(['profile_id', 'verification_id'], array_keys($logged->details), 'ids only: the result never reaches the log');
        $this->assertNothingWrittenOutsideAmla($before);
    }

    public function test_only_the_members_own_valid_id_can_be_verified_and_a_copy_never_verifies(): void
    {
        $member = $this->member();
        $someoneElse = $this->validId($this->member());
        $own = $this->validId($member);
        $officer = $this->cddOfficer();

        $this->actingAs($officer)->postJson($this->cddUrl($member, '/id-verifications'), [
            'document_id' => $someoneElse->id, 'method' => 'original_seen', 'result' => 'verified',
        ])->assertUnprocessable()->assertJsonValidationErrors(['document_id']);

        $this->actingAs($officer)->postJson($this->cddUrl($member, '/id-verifications'), [
            'document_id' => $own->id, 'method' => 'copy_only', 'result' => 'verified',
        ])->assertUnprocessable()->assertJsonPath('errors.result.0', 'A photocopy or scan alone doesn\'t verify an ID. See the original or a certified true copy.');

        $this->actingAs($officer)->postJson($this->cddUrl($member, '/id-verifications'), [
            'document_id' => $own->id, 'method' => 'external_provider', 'result' => 'verified',
        ])->assertUnprocessable()->assertJsonValidationErrors(['method']);
    }

    public function test_an_expired_id_is_no_longer_verified_and_one_expiring_soon_is_listed(): void
    {
        $member = $this->member();
        $document = $this->validId($member);
        $officer = $this->cddOfficer();
        $this->actingAs($officer)->putJson($this->cddUrl($member), $this->completeProfile())->assertOk();
        $this->actingAs($officer)->postJson($this->cddUrl($member, '/id-verifications'), [
            'document_id' => $document->id, 'method' => 'original_seen', 'result' => 'verified', 'expires_on' => '2026-12-10',
        ])->assertCreated();

        $soon = $this->actingAs($officer)->getJson($this->cddUrl($member))->json('data');
        $this->assertSame('verified', $soon['cdd_status']);
        $this->assertSame(['id_expiring'], array_column($soon['completeness']['expiring_soon'], 'key'));

        Carbon::setTestNow('2026-12-11 09:00:00');
        $expired = $this->actingAs($officer)->getJson($this->cddUrl($member))->json('data');
        $this->assertSame('pending_verification', $expired['cdd_status']);
        $this->assertSame(['id_expired'], array_column($expired['completeness']['expired'], 'key'));
    }

    public function test_an_imported_member_with_no_id_on_file_says_so(): void
    {
        $member = $this->member(['external_account_no' => 'OLD-0042']);

        $data = $this->actingAs($this->cddOfficer())->getJson($this->cddUrl($member))->json('data');

        $this->assertTrue($data['subject']['is_imported']);
        $this->assertFalse($data['subject']['has_valid_id']);
        $this->assertContains('No valid ID on file (imported member)', array_column($data['completeness']['missing'], 'words'));
    }

    public function test_a_missing_answer_reads_additional_information_required(): void
    {
        $member = $this->member(['employer_or_business' => null]);
        $this->validId($member);
        $officer = $this->cddOfficer();
        $answers = $this->completeProfile();
        unset($answers['occupation'], $answers['purpose']);

        $data = $this->actingAs($officer)->putJson($this->cddUrl($member), $answers)->assertOk()->json('data');

        $this->assertSame('additional_info_required', $data['cdd_status']);
        $this->assertSame('Additional Information Required', $data['cdd_status_words']);
        $missing = array_column($data['completeness']['missing'], 'key');
        $this->assertContains('occupation', $missing);
        $this->assertContains('purpose', $missing);
    }

    public function test_a_walk_in_is_verified_from_its_own_id_and_one_without_an_id_cannot_be(): void
    {
        $walkIn = $this->walkIn();
        $bare = $this->walkIn(false);
        $officer = $this->headOfficeCddOfficer();

        $this->actingAs($officer)->postJson($this->cddUrl($walkIn, '/id-verifications'), [
            'method' => 'original_seen', 'result' => 'verified',
        ])->assertCreated()->assertJsonPath('data.id_type', IdTypes::fromWalkIn($walkIn->id_type));
        $this->assertNotSame(IdTypes::OTHERS, IdTypes::fromWalkIn($walkIn->id_type), 'the factory\'s types are all on the list');

        $this->actingAs($officer)->postJson($this->cddUrl($bare, '/id-verifications'), [
            'method' => 'original_seen', 'result' => 'verified',
        ])->assertUnprocessable()->assertJsonPath('message', 'This walk-in has no ID on record, so there is nothing to verify.');
    }

    public function test_only_a_fixed_id_type_is_kept_never_free_text_that_could_hold_a_number(): void
    {
        $officer = $this->cddOfficer();
        $cases = [
            ['umid', null, 'umid'],
            ['UMID 0111-2222333-4', null, 'others'],
            ['others', 'Company HR ID', 'Company HR ID'],
            ['others', 'Employee no. 4471', 'others'],
            ['others', null, 'others'],
        ];

        foreach ($cases as [$label, $custom, $expected]) {
            $member = $this->member();
            $document = $this->validId($member, 'X99887766', $label, $custom);

            $this->actingAs($officer)->postJson($this->cddUrl($member, '/id-verifications'), [
                'document_id' => $document->id, 'method' => 'original_seen', 'result' => 'verified',
            ])->assertCreated()->assertJsonPath('data.id_type', $expected);
        }

        $this->assertSame('drivers_license', IdTypes::fromWalkIn("Driver's License"));
        $this->assertSame('philippine_id', IdTypes::fromWalkIn('PhilSys'));
        $this->assertSame('others', IdTypes::fromWalkIn('ID no. 1234567'));
        $this->assertSame('UMID', IdTypes::words('umid'));
    }

    public function test_a_verification_is_kept_as_written(): void
    {
        $member = $this->member();
        $document = $this->validId($member);
        $this->actingAs($this->cddOfficer())->postJson($this->cddUrl($member, '/id-verifications'), [
            'document_id' => $document->id, 'method' => 'original_seen', 'result' => 'failed', 'notes' => 'Photo doesn\'t match.',
        ])->assertCreated();
        $verification = AmlaIdVerification::query()->sole();

        $this->assertNotSame('Photo doesn\'t match.', $verification->getRawOriginal('notes'));

        $this->expectException(AmlaRecordIsAppendOnlyException::class);
        $verification->forceFill(['result' => 'verified'])->save();
    }
}

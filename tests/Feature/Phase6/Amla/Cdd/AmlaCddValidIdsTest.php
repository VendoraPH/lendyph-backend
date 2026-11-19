<?php

namespace Tests\Feature\Phase6\Amla\Cdd;

use App\Models\AmlaLogEntry;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The CDD ID picker's own list (AMLA-B5, from the AMLA-F3a report): a
 * member's valid IDs as their type and last four characters only, so the
 * screen never loads a full ID number or needs `borrowers:view`. Needs
 * `amla:cdd`, checked before the lookup; another branch's member answers
 * 404; each read is written to the AMLA log. One entry per ID, whatever
 * sides were uploaded.
 */
class AmlaCddValidIdsTest extends TestCase
{
    use CddTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_members_ids_read_as_type_and_last_four_only_and_the_read_is_logged(): void
    {
        $member = $this->member();
        $front = $this->validId($member, 'P1234-567A');
        $back = $this->validId($member, 'P1234-567A');
        $back->forceFill(['side' => 'back'])->save();
        $this->validId($member, 'N-01-23-456789', 'drivers_license');
        $officer = $this->cddOfficer();
        $before = $this->outsideAmlaCounts();

        $response = $this->actingAs($officer)->getJson($this->cddUrl($member, '/valid-ids'))->assertOk();
        $ids = $response->json('data');

        $this->assertCount(2, $ids, 'one entry per ID, not per side');
        $this->assertSame(['document_id' => $front->id, 'id_type' => 'passport', 'id_type_words' => 'Passport', 'id_number_masked' => '****567A'], $ids[0]);
        $this->assertSame('****6789', $ids[1]['id_number_masked']);
        $this->assertStringNotContainsString('P1234', $response->getContent());
        $this->assertStringNotContainsString('456789', $response->getContent());

        $logged = AmlaLogEntry::query()->where('action', 'cdd_valid_ids_viewed')->sole();
        $this->assertSame($member->id, $logged->subject_id);
        $this->assertNothingWrittenOutsideAmla($before);
    }

    public function test_a_walk_ins_id_on_record_reads_the_same_way(): void
    {
        $walkIn = $this->walkIn();

        $ids = $this->actingAs($this->headOfficeCddOfficer())->getJson($this->cddUrl($walkIn, '/valid-ids'))->assertOk()->json('data');

        $this->assertCount(1, $ids);
        $this->assertNull($ids[0]['document_id']);
        $this->assertStringStartsWith('****', $ids[0]['id_number_masked']);
        $this->assertSame([], $this->actingAs($this->headOfficeCddOfficer())->getJson($this->cddUrl($this->walkIn(false), '/valid-ids'))->json('data'));
    }

    public function test_it_needs_amla_cdd_before_the_lookup_and_hides_other_branches(): void
    {
        $member = $this->member();
        $elsewhere = $this->member([], $this->otherBranch());

        foreach ([$member->id, 999999] as $id) {
            $this->actingAs($this->amlaUser(['amla:view', 'borrowers:view', 'branches:view_all']))->getJson("/api/amla/cdd/members/{$id}/valid-ids")
                ->assertForbidden()->assertJsonPath('message', 'This needs the AMLA customer due diligence permission.');
        }

        $this->actingAs($this->cddOfficer())->getJson($this->cddUrl($elsewhere, '/valid-ids'))->assertNotFound();
        $this->actingAs($this->cddOfficer())->getJson($this->cddUrl($this->walkIn(), '/valid-ids'))->assertNotFound();
        $this->actingAs($this->headOfficeCddOfficer())->getJson($this->cddUrl($elsewhere, '/valid-ids'))->assertOk();
    }
}

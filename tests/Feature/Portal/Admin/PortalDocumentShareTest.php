<?php

namespace Tests\Feature\Portal\Admin;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CoMaker;
use App\Models\Document;
use App\Models\Loan;
use App\Models\Role;
use App\Models\User;
use App\Services\ValidIdService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Portal\Read\PortalReadFixtures;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * "Share with member" (portal design §1, §3, §4): staff holding
 * member_portal:manage and borrowers:view share a member's or a loan's file
 * with the member, in their own branches only. Co-maker files and valid IDs
 * are never shared.
 */
class PortalDocumentShareTest extends TestCase
{
    use PortalReadFixtures, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_sharing_and_unsharing_a_members_file_is_audited_once(): void
    {
        $member = $this->portalMember(['branch_id' => $this->branch->id]);
        $document = $this->memberDocument($member->borrower, [], shared: false);
        $staff = $this->staffWith(['member_portal:manage', 'borrowers:view']);

        $this->actingAs($staff)->postJson($this->shareUri($document), ['document_id' => $document->id])
            ->assertOk()
            ->assertJsonPath('data.shared', true);
        $this->assertNotNull($document->fresh()->portal_shared_at);
        $this->assertSame($staff->id, (int) $document->fresh()->portal_shared_by);

        $this->actingAs($staff)->postJson($this->shareUri($document), ['document_id' => $document->id])->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'portal_document_shared')->count(), 'Sharing again changes nothing.');

        $row = AuditLog::query()->where('action', 'portal_document_shared')->firstOrFail();
        $this->assertSame($staff->id, (int) $row->user_id);
        $this->assertNull($row->actor_type);

        $this->actingAs($staff)->postJson($this->shareUri($document, 'unshare'), ['document_id' => $document->id])
            ->assertOk()
            ->assertJsonPath('data.shared', false);
        $this->assertNull($document->fresh()->portal_shared_at);
        $this->assertNull($document->fresh()->portal_shared_by);
        $this->assertSame(1, AuditLog::query()->where('action', 'portal_document_unshared')->count());
    }

    public function test_a_loans_file_can_be_shared(): void
    {
        $member = $this->portalMember(['branch_id' => $this->branch->id]);
        $loan = $this->releasedLoanFor($member);
        $document = $this->memberDocument($loan, [], shared: false);

        $this->actingAs($this->admin)->postJson($this->shareUri($document), ['document_id' => $document->id])->assertOk();
    }

    public function test_co_maker_files_and_valid_ids_are_refused_in_words(): void
    {
        $member = $this->portalMember(['branch_id' => $this->branch->id]);
        $coMaker = CoMaker::create([
            'borrower_id' => $member->borrower_id, 'first_name' => 'Co', 'last_name' => 'Maker',
            'relationship_to_borrower' => 'friend', 'status' => 'active',
        ]);
        $coMakerFile = $this->memberDocument($coMaker, [], shared: false);
        $validId = $this->memberDocument($member->borrower, ['type' => ValidIdService::DOCUMENT_TYPE, 'label' => 'umid', 'id_number' => '1234'], shared: false);

        foreach ([$coMakerFile, $validId] as $document) {
            $response = $this->actingAs($this->admin)->postJson($this->shareUri($document), ['document_id' => $document->id]);
            $response->assertUnprocessable();
            $this->assertIsString($response->json('message'));
            $this->assertNull($document->fresh()->portal_shared_at);
        }
    }

    public function test_it_needs_both_permissions(): void
    {
        $member = $this->portalMember(['branch_id' => $this->branch->id]);
        $document = $this->memberDocument($member->borrower, [], shared: false);

        $this->actingAs($this->staffWith(['member_portal:manage']))->postJson($this->shareUri($document), ['document_id' => $document->id])->assertForbidden();
        $this->actingAs($this->staffWith(['borrowers:view']))->postJson($this->shareUri($document), ['document_id' => $document->id])->assertForbidden();
        $this->actingAs($this->staffWith(['borrowers:view']))->postJson($this->shareUri($document, 'unshare'), ['document_id' => $document->id])->assertForbidden();
        $this->assertNull($document->fresh()->portal_shared_at);
    }

    public function test_another_branchs_file_answers_404(): void
    {
        $far = $this->portalMember(['branch_id' => Branch::factory()->create()->id]);
        $document = $this->memberDocument($far->borrower, [], shared: false);
        $staff = $this->staffWith(['member_portal:manage', 'borrowers:view']);

        $this->actingAs($staff)->postJson($this->shareUri($document), ['document_id' => $document->id])->assertNotFound();
        $this->actingAs($staff)->postJson($this->shareUri($document, 'unshare'), ['document_id' => $document->id])->assertNotFound();
        $this->assertNull($document->fresh()->portal_shared_at);
    }

    public function test_another_members_file_answers_404_under_this_member(): void
    {
        $member = $this->portalMember(['branch_id' => $this->branch->id]);
        $other = $this->portalMember(['branch_id' => $this->branch->id]);
        $document = $this->memberDocument($other->borrower, [], shared: false);

        $this->actingAs($this->admin)
            ->postJson("/api/portal-admin/members/{$member->borrower_id}/documents/share", ['document_id' => $document->id])
            ->assertNotFound();
        $this->assertNull($document->fresh()->portal_shared_at);
    }

    public function test_a_loan_file_in_a_branch_the_caller_cant_see_answers_404(): void
    {
        $member = $this->portalMember(['branch_id' => $this->branch->id]);
        $loan = $this->releasedLoanFor($member);
        Loan::query()->whereKey($loan->id)->update(['branch_id' => Branch::factory()->create()->id]);
        $document = $this->memberDocument($loan->fresh(), [], shared: false);
        $staff = $this->staffWith(['member_portal:manage', 'borrowers:view']);

        $this->actingAs($staff)->postJson("/api/portal-admin/members/{$member->borrower_id}/documents/share", ['document_id' => $document->id])->assertNotFound();
        $this->actingAs($staff)->postJson("/api/portal-admin/members/{$member->borrower_id}/documents/unshare", ['document_id' => $document->id])->assertNotFound();
        $this->assertNull($document->fresh()->portal_shared_at);
    }

    public function test_another_members_loan_file_and_a_drafts_file_answer_404(): void
    {
        $member = $this->portalMember(['branch_id' => $this->branch->id]);
        $other = $this->portalMember(['branch_id' => $this->branch->id]);
        $othersLoanFile = $this->memberDocument($this->releasedLoanFor($other), [], shared: false);
        $draftFile = $this->memberDocument($this->draftLoanFor($member), [], shared: false);

        foreach ([$othersLoanFile, $draftFile] as $document) {
            $this->actingAs($this->admin)
                ->postJson("/api/portal-admin/members/{$member->borrower_id}/documents/share", ['document_id' => $document->id])
                ->assertNotFound();
            $this->assertNull($document->fresh()->portal_shared_at);
        }
    }

    public function test_a_valid_id_spelled_another_way_is_refused(): void
    {
        $member = $this->portalMember(['branch_id' => $this->branch->id]);

        foreach (['Valid ID', 'valid-id', 'VALID_ID'] as $type) {
            $document = $this->memberDocument($member->borrower, ['type' => $type], shared: false);
            $this->actingAs($this->admin)->postJson($this->shareUri($document), ['document_id' => $document->id])->assertUnprocessable();
            $this->assertNull($document->fresh()->portal_shared_at, $type);
        }
    }

    public function test_a_valid_id_typed_with_any_separator_or_inside_a_longer_type_is_refused(): void
    {
        $member = $this->portalMember(['branch_id' => $this->branch->id]);

        foreach (['Valid.ID', 'valid/id', 'Valid ID (back)', 'secondary-valid-id', 'VALİD ID'] as $type) {
            $document = $this->memberDocument($member->borrower, ['type' => $type], shared: false);
            $this->actingAs($this->admin)->postJson($this->shareUri($document), ['document_id' => $document->id])
                ->assertUnprocessable()
                ->assertJsonPath('errors.document.0', 'A valid ID is never shared in the Member Portal.');
            $this->assertNull($document->fresh()->portal_shared_at, $type);
        }
    }

    public function test_the_staff_documents_list_shows_when_each_file_was_shared_to_staff_who_can_share(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:15:00', 'Asia/Manila'));
        $member = $this->portalMember(['branch_id' => $this->branch->id]);
        $shared = $this->memberDocument($member->borrower);
        $notShared = $this->memberDocument($member->borrower, ['label' => 'Not shared'], shared: false);
        $staff = $this->staffWith(['member_portal:manage', 'borrowers:view']);

        $rows = collect($this->actingAs($staff)->getJson("/api/borrowers/{$member->borrower_id}/documents")->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('2026-11-10T10:15:00+08:00', $rows[$shared->id]['portal_shared_at']);
        $this->assertArrayHasKey('portal_shared_at', $rows[$notShared->id]);
        $this->assertNull($rows[$notShared->id]['portal_shared_at']);
        Carbon::setTestNow();
    }

    public function test_staff_who_cannot_share_never_see_the_shared_state(): void
    {
        $member = $this->portalMember(['branch_id' => $this->branch->id]);
        $this->memberDocument($member->borrower);
        $staff = $this->staffWith(['borrowers:view', 'member_portal:view']);

        $rows = $this->actingAs($staff)->getJson("/api/borrowers/{$member->borrower_id}/documents")->assertOk()->json('data');

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('portal_shared_at', $row);
        }
    }

    /** The share route under the file's own member (the loan's member for a loan's file, the co-maker's member for a co-maker's). */
    private function shareUri(Document $document, string $action = 'share'): string
    {
        $owner = $document->documentable;
        $memberId = $owner instanceof Borrower ? $owner->id : $owner->borrower_id;

        return "/api/portal-admin/members/{$memberId}/documents/{$action}";
    }

    /** @param  list<string>  $permissions */
    private function staffWith(array $permissions): User
    {
        $role = Role::create(['name' => 'portal_share_'.Str::random(8), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}

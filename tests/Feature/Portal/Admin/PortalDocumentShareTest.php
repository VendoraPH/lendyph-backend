<?php

namespace Tests\Feature\Portal\Admin;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CoMaker;
use App\Models\Document;
use App\Models\Role;
use App\Models\User;
use App\Services\ValidIdService;
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

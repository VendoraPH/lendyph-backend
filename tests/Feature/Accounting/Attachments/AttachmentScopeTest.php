<?php

namespace Tests\Feature\Accounting\Attachments;

use App\Models\Branch;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Branch scope on attachments: an owner the caller can't see answers 404, the
 * same as its own show route, and the generic /documents routes never reach
 * an accounting attachment.
 */
class AttachmentScopeTest extends TestCase
{
    use AttachmentFixtures, PostsJournals, SetupLendyPH;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        Storage::fake('private');
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
    }

    /** @return list<string> */
    private function everything(): array
    {
        return ['journals:view', 'journals:create', 'expenses:view', 'expenses:create', 'accounting:settings'];
    }

    public function test_a_branch_user_cannot_list_upload_to_or_remove_from_another_branchs_journal_or_expense(): void
    {
        $owners = [$this->draftOn($this->far), $this->postedOn($this->far), $this->expenseOn($this->far)];
        $attachments = array_map(fn ($owner) => $this->attachmentOn($owner), $owners);

        $this->actingAs($this->branchUser($this->everything()));

        foreach ($owners as $i => $owner) {
            $this->getJson($this->ownerPath($owner))->assertNotFound();
            $this->upload($owner)->assertNotFound();
            $this->deleteJson($this->ownerPath($owner)."/{$attachments[$i]->id}", ['reason' => 'x'])->assertNotFound();
            $this->assertModelExists($attachments[$i]);
        }

        $own = $this->postedOn($this->branch);
        $this->getJson($this->ownerPath($own))->assertOk();
        $this->upload($own)->assertCreated();
    }

    public function test_a_no_branch_journals_attachments_need_view_all(): void
    {
        $journal = $this->postedOn(null);
        $expense = $this->expenseOn(null);
        $this->attachmentOn($journal);

        $this->actingAs($this->branchUser($this->everything()));
        $this->getJson($this->ownerPath($journal))->assertNotFound();
        $this->getJson($this->ownerPath($expense))->assertNotFound();
        $this->upload($journal)->assertNotFound();

        $this->actingAs($this->admin);
        $this->assertCount(1, $this->getJson($this->ownerPath($journal))->assertOk()->json('data'));
    }

    public function test_the_generic_document_routes_answer_404_for_an_accounting_attachment(): void
    {
        $attachment = $this->attachmentOn($this->postedOn($this->branch));

        // The super admin holds borrowers:view and borrowers:delete.
        $this->getJson("/api/documents/{$attachment->id}")->assertNotFound();
        $this->deleteJson("/api/documents/{$attachment->id}")->assertNotFound();

        $this->assertModelExists($attachment);
        Storage::disk('private')->assertExists($attachment->file_path);
    }

    public function test_a_missing_owner_or_a_non_numeric_id_answers_404(): void
    {
        $this->getJson('/api/accounting/journals/999999/attachments')->assertNotFound();
        $this->getJson('/api/accounting/expenses/999999/attachments')->assertNotFound();
        $this->getJson('/api/accounting/journals/abc/attachments')->assertNotFound();
        $this->deleteJson('/api/accounting/journals/1/attachments/abc')->assertNotFound();
    }
}

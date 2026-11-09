<?php

namespace Tests\Feature\Accounting\Attachments;

use App\Models\AccountingExpense;
use App\Models\AuditLog;
use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Removing an attachment: a draft journal's with `journals:create`; a posted
 * or reversed journal's, or an expense's, only with `accounting:settings` and
 * a reason, audited.
 */
class AttachmentRemovalTest extends TestCase
{
    use AttachmentFixtures, PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        Storage::fake('private');
    }

    private function remove(Model $owner, Document $attachment, array $body = []): TestResponse
    {
        return $this->deleteJson($this->ownerPath($owner)."/{$attachment->id}", $body);
    }

    private function assertGone(Document $attachment): void
    {
        $this->assertModelMissing($attachment);
        Storage::disk('private')->assertMissing($attachment->file_path);
    }

    private function removalRow(Model $owner): AuditLog
    {
        return AuditLog::query()
            ->where('auditable_type', $owner::class)
            ->where('auditable_id', $owner->id)
            ->where('action', 'attachment_removed')
            ->sole();
    }

    public function test_a_drafts_attachment_goes_with_journals_create_and_no_reason(): void
    {
        $journal = $this->draftOn($this->branch);
        $attachment = $this->attachmentOn($journal, 'draft-slip.pdf');

        $this->actingAs($this->userWithRole('general_bookkeeper'));
        $this->assertTrue($this->getJson($this->ownerPath($journal))->json('data.0.can_remove'));

        $this->remove($journal, $attachment)->assertOk()->assertJsonStructure(['message']);

        $this->assertGone($attachment);
        $row = $this->removalRow($journal);
        $this->assertStringContainsString('draft-slip.pdf', json_encode([$row->old_values, $row->description]));
    }

    public function test_a_drafts_attachment_needs_journals_create(): void
    {
        $journal = $this->draftOn($this->branch);
        $attachment = $this->attachmentOn($journal);

        $this->actingAs($this->branchUser(['journals:view']));
        $this->assertFalse($this->getJson($this->ownerPath($journal))->json('data.0.can_remove'));
        $this->remove($journal, $attachment)->assertForbidden();

        $this->assertModelExists($attachment);
    }

    public function test_a_posted_journals_attachment_needs_accounting_settings(): void
    {
        $journal = $this->postedOn($this->branch);
        $attachment = $this->attachmentOn($journal);

        $this->actingAs($this->userWithRole('general_bookkeeper'));
        $this->assertFalse($this->getJson($this->ownerPath($journal))->json('data.0.can_remove'));
        $this->remove($journal, $attachment, ['reason' => 'Wrong file'])->assertForbidden();

        $this->assertModelExists($attachment);
        Storage::disk('private')->assertExists($attachment->file_path);
    }

    public function test_a_posted_journals_attachment_needs_a_reason_and_the_reason_is_audited(): void
    {
        $journal = $this->postedOn($this->branch);
        $attachment = $this->attachmentOn($journal, 'duplicate.pdf');

        $this->assertTrue($this->getJson($this->ownerPath($journal))->json('data.0.can_remove'));

        $this->remove($journal, $attachment)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->remove($journal, $attachment, ['reason' => str_repeat('a', 501)])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertModelExists($attachment);

        $this->remove($journal, $attachment, ['reason' => 'Uploaded twice'])->assertOk();

        $this->assertGone($attachment);
        $row = $this->removalRow($journal);
        $text = json_encode([$row->old_values, $row->new_values, $row->description]);
        $this->assertStringContainsString('Uploaded twice', $text);
        $this->assertStringContainsString('duplicate.pdf', $text);
        $this->assertStringContainsString('Receipt', $text);
        $this->assertStringNotContainsString($attachment->file_path, $text);
        $this->assertSame($this->admin->id, $row->user_id);
    }

    public function test_a_reversed_journals_attachment_follows_the_posted_rule(): void
    {
        $journal = $this->reversed();
        $this->assertSame('reversed', $journal->status);
        $attachment = $this->attachmentOn($journal);

        $this->actingAs($this->userWithRole('general_bookkeeper'));
        $this->remove($journal, $attachment, ['reason' => 'x'])->assertForbidden();

        $this->actingAs($this->admin);
        $this->remove($journal, $attachment)->assertUnprocessable();
        $this->remove($journal, $attachment, ['reason' => 'Not this entry'])->assertOk();
        $this->assertGone($attachment);
    }

    public function test_an_expenses_attachment_needs_accounting_settings_and_a_reason(): void
    {
        $expense = $this->expenseOn($this->branch);
        $attachment = $this->attachmentOn($expense);

        $this->actingAs($this->userWithRole('general_bookkeeper'));
        $this->assertFalse($this->getJson($this->ownerPath($expense))->json('data.0.can_remove'));
        $this->remove($expense, $attachment, ['reason' => 'Wrong'])->assertForbidden();

        $this->actingAs($this->admin);
        $this->remove($expense, $attachment)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->remove($expense, $attachment, ['reason' => 'Wrong supplier'])->assertOk();

        $this->assertGone($attachment);
        $this->assertStringContainsString('Wrong supplier', json_encode($this->removalRow($expense)->new_values));
    }

    public function test_a_caller_who_can_remove_nothing_gets_403_before_any_lookup(): void
    {
        $journal = $this->draftOn($this->branch);
        $expense = $this->expenseOn($this->branch);
        $journalAttachment = $this->attachmentOn($journal);
        $expenseAttachment = $this->attachmentOn($expense);

        $this->actingAs($this->branchUser(['journals:view', 'expenses:view', 'expenses:create']));

        $this->remove($journal, $journalAttachment, ['reason' => 'x'])->assertForbidden();
        $this->remove($expense, $expenseAttachment, ['reason' => 'x'])->assertForbidden();
        $this->deleteJson('/api/accounting/journals/999999/attachments/999999', ['reason' => 'x'])->assertForbidden();
        $this->deleteJson('/api/accounting/expenses/999999/attachments/999999', ['reason' => 'x'])->assertForbidden();

        $this->assertModelExists($journalAttachment);
        $this->assertModelExists($expenseAttachment);
    }

    public function test_an_attachment_of_another_owner_answers_404(): void
    {
        $journal = $this->draftOn($this->branch);
        $other = $this->draftOn($this->branch);
        $expense = $this->expenseOn($this->branch);
        $attachment = $this->attachmentOn($other);

        $this->remove($journal, $attachment)->assertNotFound();
        $this->deleteJson("/api/accounting/expenses/{$expense->id}/attachments/{$attachment->id}", ['reason' => 'x'])->assertNotFound();

        $this->assertModelExists($attachment);
    }

    public function test_an_expenses_attachment_whose_owner_id_matches_the_journal_is_not_the_journals(): void
    {
        $journal = $this->draftOn($this->branch);
        $expenses = Document::create([
            'documentable_type' => AccountingExpense::class,
            'documentable_id' => $journal->id,
            'type' => 'accounting_attachment',
            'file_path' => 'documents/x/1.pdf',
            'original_filename' => 'x.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
        ]);

        // The same owner id on the other owner type: not this journal's attachment.
        $this->remove($journal, $expenses)->assertNotFound();
        $this->assertSame([], $this->getJson($this->ownerPath($journal))->json('data'));
    }
}

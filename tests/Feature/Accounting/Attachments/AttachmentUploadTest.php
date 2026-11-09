<?php

namespace Tests\Feature\Accounting\Attachments;

use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\AuditLog;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Attachments on journals and expenses (Phase 2, accounting round C6): the
 * member document limits, at most 20 per owner, private files served only
 * through signed links.
 */
class AttachmentUploadTest extends TestCase
{
    use AttachmentFixtures, PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        Storage::fake('private');
    }

    public function test_a_pdf_uploads_to_a_draft_and_to_a_posted_journal_and_lists_with_a_working_signed_link(): void
    {
        foreach ([$this->draftOn($this->branch), $this->postedOn($this->branch)] as $journal) {
            $body = $this->upload($journal, $this->pdf('or-0001.pdf'), ['label' => 'Official receipt'])
                ->assertCreated()
                ->json('data');

            $this->assertSame('Official receipt', $body['label']);
            $this->assertSame('or-0001.pdf', $body['original_filename']);
            $this->assertSame('application/pdf', $body['mime_type']);
            $this->assertSame(['id' => $this->admin->id, 'name' => $this->admin->full_name], $body['uploaded_by']);

            $document = Document::findOrFail($body['id']);
            $this->assertSame(AccountingJournal::class, $document->documentable_type);
            $this->assertSame($journal->id, $document->documentable_id);
            $this->assertSame('accounting_attachment', $document->type);
            $this->assertSame($this->admin->id, $document->uploaded_by);
            $this->assertStringStartsWith("documents/accounting_attachment/journal/{$journal->id}/", $document->file_path);
            Storage::disk('private')->assertExists($document->file_path);

            $listed = $this->getJson($this->ownerPath($journal))->assertOk()->json('data');
            $this->assertSame([$document->id], array_column($listed, 'id'));

            $this->openAsBrowser($listed[0]['url'])->assertOk();
            $this->actingAs($this->admin);
        }
    }

    public function test_an_expense_takes_an_attachment_on_its_own_path(): void
    {
        $expense = $this->expenseOn($this->branch);

        $id = $this->upload($expense)->assertCreated()->json('data.id');

        $document = Document::findOrFail($id);
        $this->assertSame(AccountingExpense::class, $document->documentable_type);
        $this->assertStringStartsWith("documents/accounting_attachment/expense/{$expense->id}/", $document->file_path);
        $this->assertSame([$id], array_column($this->getJson($this->ownerPath($expense))->assertOk()->json('data'), 'id'));
    }

    public function test_the_list_is_oldest_first_and_never_returns_the_stored_path(): void
    {
        $journal = $this->postedOn($this->branch);
        $first = $this->attachmentOn($journal, 'first.pdf');
        $this->travel(1)->minutes();
        $second = $this->attachmentOn($journal, 'second.pdf');

        $rows = $this->getJson($this->ownerPath($journal))->assertOk()->json('data');

        $this->assertSame([$first->id, $second->id], array_column($rows, 'id'));
        $this->assertSame(
            ['id', 'label', 'original_filename', 'mime_type', 'file_size', 'url', 'uploaded_by', 'created_at', 'can_remove'],
            array_keys($rows[0]),
        );
        $this->assertStringNotContainsString($first->file_path, json_encode($rows));
    }

    public function test_an_expired_or_altered_link_is_refused(): void
    {
        $journal = $this->postedOn($this->branch);
        $this->attachmentOn($journal);
        $url = $this->getJson($this->ownerPath($journal))->json('data.0.url');

        $this->openAsBrowser($url.'x')->assertForbidden();

        $this->travel(31)->minutes();
        $this->openAsBrowser($url)->assertForbidden();
    }

    public function test_the_upload_writes_an_audit_row_without_the_path_or_link(): void
    {
        $journal = $this->postedOn($this->branch);

        $id = $this->upload($journal, $this->pdf('bank-slip.pdf'), ['label' => 'Bank slip'])->assertCreated()->json('data.id');

        $row = AuditLog::query()
            ->where('auditable_type', AccountingJournal::class)
            ->where('auditable_id', $journal->id)
            ->where('action', 'uploaded')
            ->sole();

        $text = json_encode([$row->old_values, $row->new_values, $row->description]);
        $this->assertStringContainsString('bank-slip.pdf', $text);
        $this->assertStringContainsString('Bank slip', $text);
        $this->assertStringNotContainsString(Document::findOrFail($id)->file_path, $text);
        $this->assertStringNotContainsString('signature', $text);
    }

    public function test_a_file_over_10_mb_is_refused(): void
    {
        $this->upload($this->postedOn($this->branch), UploadedFile::fake()->create('big.pdf', 10343, 'application/pdf'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_an_executable_renamed_pdf_is_refused_by_its_content(): void
    {
        $exe = "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\x00", 48)."PE\x00\x00";

        $this->upload($this->postedOn($this->branch), $this->realFile('invoice.pdf', $exe))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_an_svg_is_refused(): void
    {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $this->upload($this->postedOn($this->branch), $this->realFile('logo.svg', $svg))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_a_label_over_255_characters_is_refused(): void
    {
        $this->upload($this->postedOn($this->branch), null, ['label' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label');
    }

    public function test_the_21st_attachment_on_a_journal_or_an_expense_is_refused(): void
    {
        $journal = $this->postedOn($this->branch);
        $expense = $this->expenseOn($this->branch);

        foreach (range(1, 20) as $n) {
            $this->attachmentOn($journal, "j{$n}.pdf");
            $this->attachmentOn($expense, "e{$n}.pdf");
        }

        $this->upload($journal)->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'A journal can hold at most 20 attachments.');
        $this->upload($expense)->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'An expense can hold at most 20 attachments.');

        $this->assertSame(20, Document::query()->where('documentable_type', AccountingJournal::class)->where('documentable_id', $journal->id)->count());
        $this->assertCount(40, Storage::disk('private')->allFiles());
    }

    public function test_listing_needs_the_owners_view_permission_and_uploading_its_create_permission(): void
    {
        $journal = $this->postedOn($this->branch);
        $expense = $this->expenseOn($this->branch);

        $this->actingAs($this->branchUser([]));
        $this->getJson($this->ownerPath($journal))->assertForbidden();
        $this->getJson($this->ownerPath($expense))->assertForbidden();

        $this->actingAs($this->branchUser(['journals:view', 'expenses:view']));
        $this->getJson($this->ownerPath($journal))->assertOk();
        $this->getJson($this->ownerPath($expense))->assertOk();
        $this->upload($journal)->assertForbidden();
        $this->upload($expense)->assertForbidden();

        $this->actingAs($this->branchUser(['journals:view', 'journals:create', 'expenses:view', 'expenses:create']));
        $this->upload($journal)->assertCreated();
        $this->upload($expense)->assertCreated();
    }
}

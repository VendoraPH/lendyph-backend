<?php

namespace Tests\Feature\Accounting\Attachments;

use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\Branch;
use App\Models\Document;
use App\Models\User;
use App\Services\Accounting\JournalPoster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Journals, expenses and files for the attachment tests.
 *
 * Uploads that must be judged by their CONTENT (a PDF, an executable renamed
 * `.pdf`, an SVG) are real files on disk, so the `mimes` rule reads their
 * bytes. A faked upload reports whatever its name says.
 */
trait AttachmentFixtures
{
    protected function draftOn(?Branch $branch): AccountingJournal
    {
        return $this->draftJournal($this->lines(1000), ['branch_id' => $branch?->id]);
    }

    protected function postedOn(?Branch $branch): AccountingJournal
    {
        return $this->postSimpleJournal('5030', '1010', 1000, ['branch_id' => $branch?->id]);
    }

    protected function reversed(): AccountingJournal
    {
        $journal = $this->postedOn($this->branch);
        app(JournalPoster::class)->reverse($journal, '2026-09-20', 'Wrong', $this->admin->id);

        return $journal->refresh();
    }

    protected function expenseOn(?Branch $branch): AccountingExpense
    {
        return AccountingExpense::factory()->create(['branch_id' => $branch?->id]);
    }

    /**
     * @return list<array{account_id:int, debit:int, credit:int}>
     */
    protected function lines(int $centavos): array
    {
        return [
            ['account_id' => $this->account('5030'), 'debit' => $centavos, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => $centavos],
        ];
    }

    protected function pdf(string $name = 'receipt.pdf'): UploadedFile
    {
        return $this->realFile($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    protected function realFile(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function ownerPath(Model $owner): string
    {
        return $owner instanceof AccountingJournal
            ? "/api/accounting/journals/{$owner->id}/attachments"
            : "/api/accounting/expenses/{$owner->id}/attachments";
    }

    protected function upload(Model $owner, ?UploadedFile $file = null, array $extra = []): TestResponse
    {
        return $this->post($this->ownerPath($owner), ['file' => $file ?? $this->pdf()] + $extra, ['Accept' => 'application/json']);
    }

    /** An attachment row and its file, written directly, without the endpoint. */
    protected function attachmentOn(Model $owner, string $name = 'old.pdf'): Document
    {
        $kind = $owner instanceof AccountingJournal ? 'journal' : 'expense';
        $path = "documents/accounting_attachment/{$kind}/{$owner->id}/".uniqid().'.pdf';
        Storage::disk('private')->put($path, 'bytes');

        return Document::create([
            'documentable_type' => $owner::class,
            'documentable_id' => $owner->id,
            'type' => 'accounting_attachment',
            'label' => 'Receipt',
            'file_path' => $path,
            'original_filename' => $name,
            'mime_type' => 'application/pdf',
            'file_size' => 5,
            'uploaded_by' => $this->admin->id,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function branchUser(array $permissions): User
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    /** Signed links are read with no signed-in user, as an <img src> sends them. */
    protected function openAsBrowser(string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->get($url);
    }
}

<?php

namespace App\Http\Requests\Accounting;

use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Services\Accounting\AttachmentScope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Uploading an attachment to a journal (`journals:create`) or an expense
 * (`expenses:create`), any status.
 *
 * The permission is checked first, then the owner is resolved with its own
 * branch rule (a hidden one answers 404), and only then is the file
 * validated. The limits are the member document limits (StoreDocumentRequest):
 * 10 MB, and jpg, jpeg, png, pdf, doc or docx, judged by the file's content
 * AND its name's extension: the name becomes the download name, so a PDF
 * named `.bat` is refused too.
 * The 20-per-owner limit is checked under the owner's lock in the controller.
 */
class StoreAttachmentRequest extends FormRequest
{
    private AccountingJournal|AccountingExpense|null $owner = null;

    public function authorize(): bool
    {
        $user = $this->user();
        $journalId = $this->route('journal');

        if (! $user->can($journalId !== null ? 'journals:create' : 'expenses:create')) {
            return false;
        }

        $scope = app(AttachmentScope::class);
        $this->owner = $journalId !== null
            ? $scope->journal($user, (int) $journalId)
            : $scope->expense($user, (int) $this->route('expense'));

        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,doc,docx', 'extensions:jpg,jpeg,png,pdf,doc,docx'],
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Every refusal in words, so no Laravel default ("The file field must…")
     * reaches the screen. The 20-per-owner count is refused in words by the
     * controller.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a file to attach.',
            'file.file' => 'The file didn\'t upload. Try again with a file of at most 10 MB.',
            'file.uploaded' => 'The file didn\'t upload. Try again with a file of at most 10 MB.',
            'file.max' => 'The file can be at most 10 MB.',
            'file.mimes' => 'The file must be a picture (JPG or PNG), a PDF or a Word document.',
            'file.extensions' => 'The file\'s name must end in .jpg, .jpeg, .png, .pdf, .doc or .docx.',
            'label.string' => 'The label must be text.',
            'label.max' => 'The label can be at most 255 characters.',
        ];
    }

    /** The journal or expense, resolved in authorize(). */
    public function owner(): AccountingJournal|AccountingExpense
    {
        return $this->owner;
    }
}

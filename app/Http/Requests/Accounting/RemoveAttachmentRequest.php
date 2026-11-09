<?php

namespace App\Http\Requests\Accounting;

use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\Document;
use App\Services\Accounting\AttachmentScope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Removing an attachment.
 *
 * A caller who could remove nothing on this route is refused first (403), so
 * 404 and 403 don't tell them which records exist: a journal's attachments
 * need `journals:create` or `accounting:settings`, an expense's
 * `accounting:settings`. Then the owner is resolved with its own branch rule
 * and the attachment inside it, so a hidden owner or another owner's
 * attachment answers 404. Then the per-status rule
 * (AttachmentScope::canRemove()): a draft journal's needs
 * `journals:create` and the reason is optional; a posted or reversed
 * journal's, or an expense's, needs `accounting:settings` and a reason.
 */
class RemoveAttachmentRequest extends FormRequest
{
    private AccountingJournal|AccountingExpense|null $owner = null;

    private ?Document $attachment = null;

    public function authorize(): bool
    {
        $user = $this->user();
        $scope = app(AttachmentScope::class);
        $journalId = $this->route('journal');

        $couldRemoveAny = $journalId !== null
            ? $user->can('journals:create') || $user->can('accounting:settings')
            : $user->can('accounting:settings');

        if (! $couldRemoveAny) {
            return false;
        }

        $this->owner = $journalId !== null
            ? $scope->journal($user, (int) $journalId)
            : $scope->expense($user, (int) $this->route('expense'));
        $this->attachment = $scope->attachment($this->owner, (int) $this->route('attachment'));

        return $scope->canRemove($user, $this->owner);
    }

    public function rules(): array
    {
        $controlled = app(AttachmentScope::class)->removalIsControlled($this->owner);

        return [
            'reason' => [$controlled ? 'required' : 'nullable', 'string', 'max:500'],
        ];
    }

    /** The journal or expense as resolved in authorize(), before any lock. */
    public function owner(): AccountingJournal|AccountingExpense
    {
        return $this->owner;
    }

    public function attachment(): Document
    {
        return $this->attachment;
    }
}

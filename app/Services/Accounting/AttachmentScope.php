<?php

namespace App\Services\Accounting;

use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\Document;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Who may reach a journal's or an expense's attachments, and who may remove
 * one (Phase 2, accounting round C6).
 *
 * The owner is resolved with its own show route's rule (BranchAccess: the
 * caller's branches, and a row with no branch for `branches:view_all` only),
 * so a hidden owner answers the same 404 as a missing one. An attachment is
 * looked up inside its owner, never through Document's generic route binding,
 * which stays member, co-maker and loan only: `/documents/{document}` keeps
 * answering 404 for an accounting attachment.
 *
 * Removal: while a journal is a draft, `journals:create`; on a posted or
 * reversed journal, or any expense (which posts when recorded), only
 * `accounting:settings`, with a reason.
 */
class AttachmentScope
{
    /** The `documents.type` every accounting attachment carries. */
    public const TYPE = 'accounting_attachment';

    /** The most attachments one journal or expense holds. */
    public const MAX_PER_OWNER = 20;

    public function __construct(private BranchAccess $branchAccess) {}

    /**
     * @throws ModelNotFoundException a journal that is missing or hidden from $user
     */
    public function journal(User $user, int $journalId, bool $lock = false): AccountingJournal
    {
        return $this->visible(AccountingJournal::query(), $user, $lock)->findOrFail($journalId);
    }

    /**
     * @throws ModelNotFoundException an expense that is missing or hidden from $user
     */
    public function expense(User $user, int $expenseId, bool $lock = false): AccountingExpense
    {
        return $this->visible(AccountingExpense::query(), $user, $lock)->findOrFail($expenseId);
    }

    /**
     * The owner's attachments, oldest first.
     *
     * @return Builder<Document>
     */
    public function attachmentsOf(AccountingJournal|AccountingExpense $owner): Builder
    {
        return Document::query()
            ->where('documentable_type', $owner::class)
            ->where('documentable_id', $owner->getKey())
            ->where('type', self::TYPE)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * @throws ModelNotFoundException an attachment that isn't this owner's
     */
    public function attachment(AccountingJournal|AccountingExpense $owner, int $attachmentId): Document
    {
        return $this->attachmentsOf($owner)->whereKey($attachmentId)->firstOrFail();
    }

    /** Whether removing one of the owner's attachments needs `accounting:settings` and a reason. */
    public function removalIsControlled(AccountingJournal|AccountingExpense $owner): bool
    {
        return ! ($owner instanceof AccountingJournal && $owner->status === 'draft');
    }

    public function canRemove(User $user, AccountingJournal|AccountingExpense $owner): bool
    {
        return $user->can($this->removalIsControlled($owner) ? 'accounting:settings' : 'journals:create');
    }

    /** "journal" or "expense": the stored path's folder and the wording of messages. */
    public function kind(AccountingJournal|AccountingExpense $owner): string
    {
        return $owner instanceof AccountingJournal ? 'journal' : 'expense';
    }

    /** How the owner reads in an audit description. */
    public function ownerName(AccountingJournal|AccountingExpense $owner): string
    {
        if ($owner instanceof AccountingJournal) {
            return $owner->journal_no ? "journal {$owner->journal_no}" : "draft journal #{$owner->id}";
        }

        return "expense #{$owner->id}";
    }

    /**
     * @template TModel of AccountingJournal|AccountingExpense
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function visible(Builder $query, User $user, bool $lock): Builder
    {
        $query = $this->branchAccess->constrain($query, $user, $query->qualifyColumn('branch_id'));

        return $lock ? $query->lockForUpdate() : $query;
    }
}

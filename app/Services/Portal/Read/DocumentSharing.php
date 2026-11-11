<?php

namespace App\Services\Portal\Read;

use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\Document;
use App\Models\Loan;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ValidIdService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Share with member" (portal design §1): a staff user marks a member's or a
 * loan's stored file as visible in that member's portal, or takes it back.
 * Co-maker files and valid IDs are never shared (422 in words). Sharing an
 * already shared file, or unsharing one that isn't, changes nothing and
 * writes no audit row.
 */
final class DocumentSharing
{
    public const VALID_ID = 'A valid ID is never shared in the Member Portal.';

    public const CO_MAKER = 'A co-maker\'s files are never shared in the Member Portal.';

    /**
     * The file with this id among the member's own files, their loans' files
     * and their co-makers' files (refused later, in words); otherwise 404.
     */
    public function find(Borrower $member, int $documentId): Document
    {
        return Document::query()
            ->where(fn (Builder $owner) => $owner
                ->where(fn (Builder $q) => $q->where('documentable_type', $member->getMorphClass())->where('documentable_id', $member->getKey()))
                ->orWhere(fn (Builder $q) => $q->where('documentable_type', (new Loan)->getMorphClass())
                    ->whereIn('documentable_id', Loan::query()->where('borrower_id', $member->getKey())->select('id')))
                ->orWhere(fn (Builder $q) => $q->where('documentable_type', (new CoMaker)->getMorphClass())
                    ->whereIn('documentable_id', CoMaker::query()->where('borrower_id', $member->getKey())->select('id'))))
            ->findOrFail($documentId);
    }

    /** @return array{id: int, shared: bool, portal_shared_at: mixed} */
    public function share(Document $document, User $staff): array
    {
        $this->assertShareable($document);

        return DB::transaction(function () use ($document, $staff): array {
            $locked = Document::query()->lockForUpdate()->findOrFail($document->getKey());

            if ($locked->getAttribute('portal_shared_at') === null) {
                $locked->forceFill(['portal_shared_at' => now(), 'portal_shared_by' => $staff->getKey()])->save();

                AuditLogService::log(
                    'portal_document_shared',
                    $locked,
                    ['portal_shared' => false],
                    ['portal_shared' => true],
                    'Shared a document with the member in the Member Portal ('.($locked->label ?: $locked->type).')',
                );
            }

            return $this->answer($locked->refresh());
        });
    }

    /** @return array{id: int, shared: bool, portal_shared_at: mixed} */
    public function unshare(Document $document): array
    {
        return DB::transaction(function () use ($document): array {
            $locked = Document::query()->lockForUpdate()->findOrFail($document->getKey());

            if ($locked->getAttribute('portal_shared_at') !== null) {
                $locked->forceFill(['portal_shared_at' => null, 'portal_shared_by' => null])->save();

                AuditLogService::log(
                    'portal_document_unshared',
                    $locked,
                    ['portal_shared' => true],
                    ['portal_shared' => false],
                    'Stopped sharing a document with the member in the Member Portal ('.($locked->label ?: $locked->type).')',
                );
            }

            return $this->answer($locked->refresh());
        });
    }

    /** @throws ValidationException */
    private function assertShareable(Document $document): void
    {
        $message = match (true) {
            $document->type === ValidIdService::DOCUMENT_TYPE => self::VALID_ID,
            $document->documentable_type === (new CoMaker)->getMorphClass() => self::CO_MAKER,
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['document' => $message]);
        }
    }

    /** @return array{id: int, shared: bool, portal_shared_at: mixed} */
    private function answer(Document $document): array
    {
        $sharedAt = $document->getAttribute('portal_shared_at');

        return ['id' => (int) $document->getKey(), 'shared' => $sharedAt !== null, 'portal_shared_at' => $sharedAt];
    }
}

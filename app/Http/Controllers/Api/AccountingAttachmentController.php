<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\RemoveAttachmentRequest;
use App\Http\Requests\Accounting\StoreAttachmentRequest;
use App\Http\Resources\AccountingAttachmentResource;
use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\Document;
use App\Models\User;
use App\Services\Accounting\AttachmentScope;
use App\Services\AuditLogService;
use App\Services\LoanWriteTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Throwable;

/**
 * Attachments on journals and expenses (Phase 2, accounting round C6): the
 * receipts, invoices and bank slips behind an entry.
 *
 * Stored as Document rows (`type` accounting_attachment) on the private disk
 * and served only through the signed `files.document` link. Each owner is
 * resolved with its own show route's branch rule (AttachmentScope), so a
 * hidden journal or expense answers 404 here too.
 *
 * Upload and removal lock the owner row, so the 20-per-owner limit holds
 * against simultaneous uploads and a removal sees the status the rule is
 * decided on. A collision answers 409, never 500.
 */
class AccountingAttachmentController extends Controller
{
    private const CONFLICT = 'Another change to this record was saved at the same time. Reload and try again.';

    public function __construct(private AttachmentScope $scope) {}

    #[OA\Get(
        path: '/api/accounting/journals/{id}/attachments',
        summary: "A journal's attachments, oldest first",
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: '{data: AccountingAttachment[]}'),
            new OA\Response(response: 403, description: 'Missing journals:view'),
            new OA\Response(response: 404, description: 'No such journal, or not in your branches'),
        ],
    )]
    public function journalIndex(Request $request, int $journal): JsonResponse
    {
        $this->authorize('journals:view');

        return $this->list($request->user(), $this->scope->journal($request->user(), $journal));
    }

    #[OA\Get(
        path: '/api/accounting/expenses/{id}/attachments',
        summary: "An expense's attachments, oldest first",
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: '{data: AccountingAttachment[]}'),
            new OA\Response(response: 403, description: 'Missing expenses:view'),
            new OA\Response(response: 404, description: 'No such expense, or not in your branches'),
        ],
    )]
    public function expenseIndex(Request $request, int $expense): JsonResponse
    {
        $this->authorize('expenses:view');

        return $this->list($request->user(), $this->scope->expense($request->user(), $expense));
    }

    #[OA\Post(
        path: '/api/accounting/journals/{id}/attachments',
        summary: 'Attach a file to a journal',
        description: 'Multipart `file` (max 10 MB; jpg, jpeg, png, pdf, doc or docx, judged by content) and optional `label` (max 255). Any status. At most 20 per journal.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 201, description: '{data: AccountingAttachment}'),
            new OA\Response(response: 403, description: 'Missing journals:create'),
            new OA\Response(response: 404, description: 'No such journal, or not in your branches'),
            new OA\Response(response: 422, description: 'File refused, or the journal already holds 20 attachments'),
        ],
    )]
    #[OA\Post(
        path: '/api/accounting/expenses/{id}/attachments',
        summary: 'Attach a file to an expense',
        description: 'As the journal upload, on `expenses:create`. At most 20 per expense.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 201, description: '{data: AccountingAttachment}'),
            new OA\Response(response: 403, description: 'Missing expenses:create'),
            new OA\Response(response: 404, description: 'No such expense, or not in your branches'),
            new OA\Response(response: 422, description: 'File refused, or the expense already holds 20 attachments'),
        ],
    )]
    public function store(StoreAttachmentRequest $request): JsonResponse
    {
        $user = $request->user();
        $owner = $request->owner();
        $kind = $this->scope->kind($owner);
        $file = $request->file('file');

        $path = $file->store('documents/'.AttachmentScope::TYPE."/{$kind}/{$owner->getKey()}", 'private');

        try {
            $document = LoanWriteTransaction::run(function () use ($user, $owner, $kind, $file, $path, $request): Document {
                $locked = $this->lockOwner($user, $owner);

                if ($this->scope->attachmentsOf($locked)->count() >= AttachmentScope::MAX_PER_OWNER) {
                    throw ValidationException::withMessages([
                        'file' => sprintf('%s can hold at most %d attachments.', $kind === 'journal' ? 'A journal' : 'An expense', AttachmentScope::MAX_PER_OWNER),
                    ]);
                }

                $document = Document::create([
                    'documentable_type' => $locked::class,
                    'documentable_id' => $locked->getKey(),
                    'type' => AttachmentScope::TYPE,
                    'label' => $request->input('label'),
                    'file_path' => $path,
                    'original_filename' => Document::cleanOriginalFilename($file->getClientOriginalName()),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'uploaded_by' => $user->id,
                ]);

                AuditLogService::log(
                    'uploaded',
                    $locked,
                    newValues: ['original_filename' => $document->original_filename, 'label' => $document->label],
                    description: "Attachment '{$document->original_filename}' uploaded to {$this->scope->ownerName($locked)}",
                );

                return $document;
            }, conflictMessage: self::CONFLICT);
        } catch (Throwable $e) {
            // No row points at the stored file, so nothing would ever remove it.
            Storage::disk('private')->delete($path);

            throw $e;
        }

        return (new AccountingAttachmentResource($document->load('uploader'), $this->scope->canRemove($user, $owner)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Delete(
        path: '/api/accounting/journals/{id}/attachments/{attachment}',
        summary: "Remove a journal's attachment",
        description: 'Body `{reason}`. A draft journal: `journals:create`, reason optional. A posted or reversed journal: `accounting:settings`, reason required (max 500). Deletes the row and the file, and writes an audit row.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'attachment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: '{message}'),
            new OA\Response(response: 403, description: 'Missing the permission the journal status needs'),
            new OA\Response(response: 404, description: 'No such journal or attachment, or not in your branches'),
            new OA\Response(response: 409, description: 'The journal changed status meanwhile'),
            new OA\Response(response: 422, description: 'Reason missing or too long'),
        ],
    )]
    #[OA\Delete(
        path: '/api/accounting/expenses/{id}/attachments/{attachment}',
        summary: "Remove an expense's attachment",
        description: 'Body `{reason}`, required (max 500). Needs `accounting:settings`: an expense posts when recorded.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'attachment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: '{message}'),
            new OA\Response(response: 403, description: 'Missing accounting:settings'),
            new OA\Response(response: 404, description: 'No such expense or attachment, or not in your branches'),
            new OA\Response(response: 422, description: 'Reason missing or too long'),
        ],
    )]
    public function destroy(RemoveAttachmentRequest $request): JsonResponse
    {
        $user = $request->user();
        $owner = $request->owner();
        $attachmentId = (int) $request->attachment()->getKey();
        $reason = $request->validated('reason');

        $path = LoanWriteTransaction::run(function () use ($user, $owner, $attachmentId, $reason): string {
            $locked = $this->lockOwner($user, $owner);

            // The rule was decided on the status read before the lock. A
            // draft posted in between needs the other permission and a reason.
            if ($this->scope->removalIsControlled($locked) !== $this->scope->removalIsControlled($owner)) {
                throw LoanWriteTransaction::conflict('This journal was posted while the attachment was being removed. Reload and try again.');
            }

            $attachment = $this->scope->attachmentsOf($locked)->whereKey($attachmentId)->lockForUpdate()->firstOrFail();

            AuditLogService::log(
                'attachment_removed',
                $locked,
                oldValues: ['original_filename' => $attachment->original_filename, 'label' => $attachment->label],
                newValues: ['reason' => $reason],
                description: "Attachment '{$attachment->original_filename}' removed from {$this->scope->ownerName($locked)}"
                    .($reason !== null ? ": {$reason}" : ''),
            );

            $attachment->delete();

            return (string) $attachment->file_path;
        }, conflictMessage: self::CONFLICT);

        if (! Storage::disk('private')->delete($path)) {
            Log::warning('An accounting attachment row was removed but its file could not be deleted.', ['document_id' => $attachmentId]);
        }

        return response()->json(['message' => 'Attachment removed.']);
    }

    private function list(User $user, AccountingJournal|AccountingExpense $owner): JsonResponse
    {
        $canRemove = $this->scope->canRemove($user, $owner);

        return response()->json([
            'data' => $this->scope->attachmentsOf($owner)
                ->with('uploader')
                ->get()
                ->map(fn (Document $document): array => (new AccountingAttachmentResource($document, $canRemove))->resolve())
                ->all(),
        ]);
    }

    /** The owner again, under a row lock and its branch rule. */
    private function lockOwner(User $user, AccountingJournal|AccountingExpense $owner): AccountingJournal|AccountingExpense
    {
        return $owner instanceof AccountingJournal
            ? $this->scope->journal($user, (int) $owner->getKey(), lock: true)
            : $this->scope->expense($user, (int) $owner->getKey(), lock: true);
    }
}

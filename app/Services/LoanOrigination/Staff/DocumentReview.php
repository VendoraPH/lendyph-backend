<?php

namespace App\Services\LoanOrigination\Staff;

use App\Models\OriginationApplication;
use App\Models\OriginationDocument;
use App\Models\OriginationDocumentVersion;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\LoanWriteTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff review of an application's documents (LO-B3): verify, reject or ask
 * for a replacement, and download.
 *
 * - Decisions are made only while the application is under review
 *   (submitted, under document verification, waiting for information, under
 *   assessment), on a document with a stored file, from the statuses the
 *   decision makes sense from. The document and its application are locked
 *   and re-read first, so two reviewers can't decide on stale reads.
 * - Each decision moves the application's version on, so a hand-off decided
 *   on what the documents were before is caught (409).
 * - A replacement request logs a message for the applicant, never sent.
 * - A download streams the stored file as an attachment, never cached and
 *   never sniffed, under its stored name. Every one is audited by ids only.
 */
final class DocumentReview
{
    public const REVIEW_STATUSES = ['submitted', 'document_verification', 'additional_info_required', 'under_assessment'];

    private const FROM = [
        'verified' => ['uploaded', 'pending_verification'],
        'rejected' => ['uploaded', 'pending_verification', 'verified'],
        'replacement_requested' => ['uploaded', 'pending_verification', 'verified', 'rejected'],
    ];

    private const ACTIONS = [
        'verified' => 'origination_document_verified',
        'rejected' => 'origination_document_rejected',
        'replacement_requested' => 'origination_document_replacement_requested',
    ];

    public function __construct(
        private readonly ApplicantMessages $messages,
        private readonly DocumentPresenter $presenter,
    ) {}

    /**
     * Documents $viewer may see: their application is in the caller's
     * branches.
     *
     * @return Builder<OriginationDocument>
     */
    public static function visibleTo(User $viewer): Builder
    {
        return OriginationDocument::query()->whereHas('application', fn (Builder $q) => $q->visibleTo($viewer));
    }

    public function decide(OriginationDocument $document, string $to, User $user, ?string $text): OriginationDocument
    {
        return LoanWriteTransaction::run(function () use ($document, $to, $user, $text): OriginationDocument {
            // The document first, then its application: the order the file
            // store takes them in on an upload, so the two never deadlock.
            /** @var OriginationDocument $locked */
            $locked = OriginationDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            /** @var OriginationApplication $application */
            $application = OriginationApplication::query()->whereKey($document->application_id)->lockForUpdate()->firstOrFail();

            if (! in_array($application->status, self::REVIEW_STATUSES, true)) {
                throw ValidationException::withMessages(['document' => 'Documents can be reviewed only while the application is under review.']);
            }

            if ($locked->current_version_id === null || ! in_array($locked->status, self::FROM[$to], true)) {
                throw ValidationException::withMessages(['document' => sprintf(
                    'A document that reads %s can\'t be marked %s.',
                    OriginationDocument::STATUS_LABELS[$locked->current_version_id === null ? 'not_submitted' : $locked->status] ?? $locked->status,
                    strtolower(OriginationDocument::STATUS_LABELS[$to]),
                )]);
            }

            $from = $locked->status;

            $locked->forceFill(match ($to) {
                'verified' => ['status' => 'verified', 'verified_by' => $user->id, 'verified_at' => now(), 'notes' => $text, 'rejection_reason' => null],
                'rejected' => ['status' => 'rejected', 'verified_by' => null, 'verified_at' => null, 'rejection_reason' => $text],
                default => ['status' => 'replacement_requested', 'verified_by' => null, 'verified_at' => null, 'rejection_reason' => $text],
            })->save();

            OriginationApplication::query()->whereKey($application->id)->update(['updated_at' => now()]);

            AuditLogService::log(self::ACTIONS[$to], $locked, ['status' => $from], ['status' => $to, 'application_id' => $application->id], 'Reviewed a document of an online application.');

            if ($to === 'replacement_requested') {
                $this->messages->log(
                    $application,
                    'document_request',
                    sprintf('Please upload a new %s. %s', strtolower($this->presenter->label($locked->type)), (string) $text),
                    $user,
                    [$locked->type],
                );
            }

            return $locked->fresh(['currentVersion', 'versions', 'verifier']);
        });
    }

    /**
     * The document's current file, or one of its versions by public id.
     */
    public function download(OriginationDocument $document, ?string $versionId): ?StreamedResponse
    {
        /** @var OriginationDocumentVersion|null $version */
        $version = $versionId === null
            ? $document->currentVersion
            : $document->versions()->where('public_id', $versionId)->first();

        if ($version === null || ! Storage::disk($version->disk)->exists($version->path)) {
            return null;
        }

        AuditLogService::log('origination_document_downloaded', $document, null, ['version_id' => $version->id, 'application_id' => $document->application_id], 'Downloaded a document of an online application.');

        return Storage::disk($version->disk)->download($version->path, $version->file_name, [
            'Content-Type' => $version->mime_type,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

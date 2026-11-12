<?php

namespace App\Services\Cda\Registers;

use App\Models\CdaDocument;
use App\Models\CdaDocumentVersion;
use App\Models\CdaMediationCase;
use App\Models\CdaOfficerTraining;
use App\Models\CdaReport;
use App\Models\CdaSocialProgram;
use App\Models\CdaSubmission;
use App\Models\Document;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\ReportTypes;
use App\Services\LoanWriteTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The compliance document repository (CDA design §1, §3, §6).
 *
 * - Every upload is a new version (`version` = the highest + 1, under the
 *   document's row lock); a version is never overwritten or deleted.
 * - One version per document is the final one: `final_key` (unique) holds
 *   the document id on that version only, so marking a new one final clears
 *   the old one first.
 * - Withdrawing keeps every file; a withdrawn document takes no new version.
 * - The file is a `documents` row on the private disk, served only by the
 *   signed `files.document` link.
 * - Audit, tax and mediation documents need their view permission: by
 *   category, by the report they belong to, or by their subject (a mediation
 *   case, or a submission of a restricted report).
 */
final class DocumentRepository
{
    public const FILE_TYPE = 'cda_document';

    private const DISK = 'private';

    private const CONFLICT = 'Another change to this document was saved at the same time. Reload and try again.';

    /**
     * A new document and its first version.
     *
     * @param  array{period_id: int|null, category: string, title: string, report_id?: int|null, subject_type?: string|null, subject_id?: int|null, description?: string|null, expires_on?: string|null}  $attributes
     */
    public function create(array $attributes, UploadedFile $file, User $user, ?string $versionLabel = null, ?string $notes = null, bool $final = false): CdaDocument
    {
        $stored = $this->store($file);

        try {
            return DB::transaction(function () use ($attributes, $stored, $user, $versionLabel, $notes, $final): CdaDocument {
                $document = CdaDocument::query()->create([...$attributes, 'status' => 'active', 'created_by' => $user->id]);
                $this->writeVersion($document, $stored, $user, $versionLabel, $notes, $final);

                return $document;
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($stored['path']);

            throw $e;
        }
    }

    /** A new version of an active document: 409 once it is withdrawn. */
    public function addVersion(CdaDocument $document, UploadedFile $file, User $user, ?string $versionLabel = null, ?string $notes = null, bool $final = false): CdaDocumentVersion
    {
        $stored = $this->store($file);

        try {
            return LoanWriteTransaction::run(function () use ($document, $stored, $user, $versionLabel, $notes, $final): CdaDocumentVersion {
                $locked = CdaDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === 'withdrawn') {
                    throw LoanWriteTransaction::conflict('This document was withdrawn, so it takes no new versions.');
                }

                return $this->writeVersion($locked, $stored, $user, $versionLabel, $notes, $final);
            }, conflictMessage: self::CONFLICT);
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($stored['path']);

            throw $e;
        }
    }

    /** Withdraws a document, keeping every version and file: 409 when it already is. */
    public function withdraw(CdaDocument $document, string $reason): CdaDocument
    {
        return LoanWriteTransaction::run(function () use ($document, $reason): CdaDocument {
            $locked = CdaDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'withdrawn') {
                throw LoanWriteTransaction::conflict('This document is already withdrawn.');
            }

            $locked->update(['status' => 'withdrawn', 'withdraw_reason' => $reason]);

            return $locked;
        }, conflictMessage: self::CONFLICT);
    }

    /**
     * The view permissions a document needs on top of `cda:view`. A report
     * export with no report could be of any type, so it needs all three.
     *
     * @return list<string>
     */
    public function restrictionsFor(CdaDocument $document): array
    {
        $needs = [DocumentCategories::restriction($document->category)];

        if ($document->report_id !== null) {
            $type = CdaReport::query()->whereKey($document->report_id)->value('report_type');
            $needs[] = $type === null ? null : ReportTypes::restriction($type);
        }

        if ($document->category === DocumentCategories::REPORT_EXPORT && $document->report_id === null) {
            array_push($needs, ...CdaAccess::RESTRICTED_VIEWS);
        }

        $needs[] = match ($document->subject_type) {
            'case' => CdaAccess::VIEW_MEDIATION,
            'submission' => $this->submissionRestriction((int) $document->subject_id),
            default => null,
        };

        return array_values(array_unique(array_filter($needs)));
    }

    public function canSee(User $user, CdaDocument $document): bool
    {
        foreach ($this->restrictionsFor($document) as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }

    /** 403, in words, unless the user may see the document. */
    public function authorizeSee(User $user, CdaDocument $document): void
    {
        foreach ($this->restrictionsFor($document) as $permission) {
            abort_unless($user->can($permission), 403, 'This document is restricted: it needs '.CdaAccess::words($permission).'.');
        }
    }

    /**
     * The permission a change to the document needs on top of `cda:upload`,
     * when it belongs to a register: a proof to the submission
     * (`cda:submit`), a certificate or a training's file to the officers
     * register (`cda:manage_officers`), a mediation paper or a case's file to
     * the mediation register (`cda:manage_mediation`). Null for the rest.
     */
    public static function writePermissionFor(?string $category, ?string $subjectType): ?string
    {
        return match (true) {
            $category === DocumentCategories::SUBMISSION_PROOF || $subjectType === 'submission' => CdaAccess::SUBMIT,
            $category === DocumentCategories::CERTIFICATES || $subjectType === 'training' => CdaAccess::MANAGE_OFFICERS,
            $category === 'mediation' || $subjectType === 'case' => CdaAccess::MANAGE_MEDIATION,
            default => null,
        };
    }

    /** 403, in words, unless the user may change a document of this kind. */
    public function authorizeWrite(User $user, ?string $category, ?string $subjectType): void
    {
        $permission = self::writePermissionFor($category, $subjectType);

        abort_unless($permission === null || $user->can($permission), 403, 'This document belongs to its register: changing it needs '.CdaAccess::words((string) $permission).'.');
    }

    /**
     * 403, in words, for an upload the user can't make, judged only on what
     * was sent (the category and the kind of subject), before any record is
     * looked up, so a refusal never tells whether a case or report exists.
     */
    public function authorizeUploadOf(User $user, mixed $category, mixed $subjectType): void
    {
        $category = is_string($category) ? $category : null;
        $subjectType = is_string($subjectType) ? $subjectType : null;
        $needs = [$category === null ? null : DocumentCategories::restriction($category), $subjectType === 'case' ? CdaAccess::VIEW_MEDIATION : null];

        foreach (array_filter($needs) as $permission) {
            abort_unless($user->can($permission), 403, 'This document is restricted: it needs '.CdaAccess::words($permission).'.');
        }

        $this->authorizeWrite($user, $category, $subjectType);
    }

    /**
     * Adds `documents_count` to presented register rows (gap round B7): the
     * active documents attached to each row (training, program or case) that
     * the user may see under scopeVisible(). Withdrawn ones stay listed but
     * aren't counted. One query for the whole page.
     *
     * @param  list<array<string, mixed>>  $rows  each with its `id`
     * @return list<array<string, mixed>>
     */
    public function withDocumentsCount(array $rows, string $subjectType, User $user): array
    {
        $query = CdaDocument::query()
            ->where('subject_type', $subjectType)
            ->whereIn('subject_id', array_column($rows, 'id'))
            ->where('status', 'active');
        $this->scopeVisible($query, $user);
        $counts = $query->groupBy('subject_id')->selectRaw('subject_id, COUNT(*) AS aggregate')->pluck('aggregate', 'subject_id');

        return array_map(fn (array $row) => [...$row, 'documents_count' => (int) ($counts[$row['id']] ?? 0)], $rows);
    }

    /**
     * Narrows a document query to what the user may see, in SQL so a list
     * pages correctly.
     *
     * @param  Builder<CdaDocument>  $query
     */
    public function scopeVisible(Builder $query, User $user): void
    {
        $hidden = array_values(array_filter(
            DocumentCategories::keys(),
            fn (string $category) => ($permission = DocumentCategories::restriction($category)) !== null && ! $user->can($permission),
        ));
        $hiddenTypes = array_values(array_filter(
            ReportTypes::ALL,
            fn (string $type) => ($permission = ReportTypes::restriction($type)) !== null && ! $user->can($permission),
        ));

        $query->whereNotIn('category', $hidden);

        if (collect(CdaAccess::RESTRICTED_VIEWS)->contains(fn (string $permission) => ! $user->can($permission))) {
            $query->where(fn (Builder $q) => $q->where('category', '!=', DocumentCategories::REPORT_EXPORT)->orWhereNotNull('report_id'));
        }

        if ($hiddenTypes !== []) {
            $query->where(fn (Builder $q) => $q->whereNull('report_id')
                ->orWhereNotIn('report_id', CdaReport::query()->select('id')->whereIn('report_type', $hiddenTypes)));
            $query->where(fn (Builder $q) => $q->where('subject_type', '!=', 'submission')->orWhereNull('subject_type')
                ->orWhereNotIn('subject_id', CdaSubmission::query()->select('cda_submissions.id')
                    ->join('cda_reports', 'cda_reports.id', '=', 'cda_submissions.report_id')
                    ->whereIn('cda_reports.report_type', $hiddenTypes)));
        }

        if (! $user->can(CdaAccess::VIEW_MEDIATION)) {
            $query->where(fn (Builder $q) => $q->where('subject_type', '!=', 'case')->orWhereNull('subject_type'));
        }
    }

    /**
     * The ids of the `documents` file rows the signed-in user may download:
     * every version file of a CDA document they may see (their own uploads
     * and the server's exports alike), when they hold `cda:view` and
     * `branches:view_all`; none otherwise. A subquery, for
     * Document::resolveRouteBindingQuery().
     *
     * @return Builder<CdaDocumentVersion>
     */
    public function downloadableFileIds(User $user): Builder
    {
        $documents = CdaDocument::query()->select('cda_documents.id');

        if (app(CdaAccess::class)->allows($user, CdaAccess::VIEW)) {
            $this->scopeVisible($documents, $user);
        } else {
            $documents->whereRaw('1 = 0');
        }

        return CdaDocumentVersion::query()->select('document_id')->whereNotNull('document_id')->whereIn('cda_document_id', $documents);
    }

    /** Whether the subject named on an upload exists. */
    public function subjectExists(string $type, int $id): bool
    {
        return match ($type) {
            'training' => CdaOfficerTraining::query()->whereKey($id)->exists(),
            'program' => CdaSocialProgram::query()->whereKey($id)->exists(),
            'case' => CdaMediationCase::query()->whereKey($id)->exists(),
            'submission' => CdaSubmission::query()->whereKey($id)->exists(),
            default => false,
        };
    }

    /**
     * Writes the file's `documents` row and the version, under the
     * document's lock (or inside the document's own create).
     *
     * @param  array{path: string, name: string, mime: string|null, size: int|false, sha256: string, format: string}  $stored
     */
    private function writeVersion(CdaDocument $document, array $stored, User $user, ?string $versionLabel, ?string $notes, bool $final): CdaDocumentVersion
    {
        $file = Document::query()->create([
            'documentable_type' => CdaDocument::class,
            'documentable_id' => $document->id,
            'type' => self::FILE_TYPE,
            'label' => Str::limit($document->title, 250, ''),
            'file_path' => $stored['path'],
            'original_filename' => $stored['name'],
            'mime_type' => $stored['mime'],
            'file_size' => $stored['size'] === false ? null : $stored['size'],
            'uploaded_by' => $user->id,
        ]);

        if ($final) {
            CdaDocumentVersion::query()->where('cda_document_id', $document->id)->whereNotNull('final_key')->get()
                ->each(fn (CdaDocumentVersion $previous) => $previous->update(['final_key' => null]));
        }

        return CdaDocumentVersion::query()->create([
            'cda_document_id' => $document->id,
            'version' => (int) CdaDocumentVersion::query()->where('cda_document_id', $document->id)->max('version') + 1,
            'version_label' => $versionLabel,
            'final_key' => $final ? (string) $document->id : null,
            'origin' => 'uploaded',
            'format' => $stored['format'],
            'is_draft' => false,
            'document_id' => $file->id,
            'sha256' => $stored['sha256'],
            'notes' => $notes,
            'uploaded_by' => $user->id,
        ]);
    }

    /**
     * Puts the upload on the private disk under a random name with the
     * extension its bytes read as (the name's own when they read as nothing
     * known).
     *
     * @return array{path: string, name: string, mime: string|null, size: int|false, sha256: string, format: string}
     */
    private function store(UploadedFile $file): array
    {
        $format = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));
        $sha256 = (string) hash_file('sha256', $file->getRealPath());
        $path = $file->storeAs('cda/documents', Str::uuid()->toString().'.'.$format, self::DISK);

        return [
            'path' => (string) $path,
            'name' => Document::cleanOriginalFilename($file->getClientOriginalName()),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'sha256' => $sha256,
            'format' => $format,
        ];
    }

    private function submissionRestriction(int $submissionId): ?string
    {
        $type = CdaSubmission::query()->join('cda_reports', 'cda_reports.id', '=', 'cda_submissions.report_id')
            ->where('cda_submissions.id', $submissionId)->value('cda_reports.report_type');

        return $type === null ? null : ReportTypes::restriction($type);
    }
}

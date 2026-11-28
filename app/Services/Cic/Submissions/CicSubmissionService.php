<?php

namespace App\Services\Cic\Submissions;

use App\Models\CicCapture;
use App\Models\CicPublicKey;
use App\Models\CicSubmission;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Cic\CicCache;
use App\Services\Cic\CicValidator;
use App\Services\Cic\Contracts\ValidatesCicCapture;
use App\Services\Cic\File\CicEncryptionFailed;
use App\Services\Cic\File\CicEncryptor;
use App\Services\Cic\File\CicFile;
use App\Services\Cic\File\CicFileBuilder;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A CIC file's life (prompt §11–§15, the build plan's decisions 7, 9 and 10):
 * generated from a complete capture, encrypted to CIC's active key after the
 * pre-encryption review, downloaded (encrypted only), marked submitted, and
 * CIC's answer recorded by someone other than its generator.
 *
 * - Only the `.zip.gpg` is kept, on the private disk under
 *   `cic/submissions/{id}/`. The TXT and ZIP are rebuilt in a temporary
 *   folder when encrypting, proven against `txt_sha256`, and deleted.
 * - Each step locks the submission's row and re-checks its status, and writes
 *   one audit row naming ids, counts, filenames, fingerprints and statuses,
 *   never a member's values.
 * - Refusals read in words (CicSubmissionRefusal); nothing is written.
 * - Generating and encrypting re-check the capture's checksum (and the file
 *   builder each record's), so a changed capture never reaches a file.
 * - A month's family is its month-end capture and every correction capture of
 *   it. A standard file may come from a correction capture of a month-end
 *   while no file of that family has gone to CIC, and only one standard file
 *   per family is ever marked submitted (review M-H1).
 */
final class CicSubmissionService
{
    public const DISK = 'private';

    /** @var list<string> the statuses whose encrypted file can be downloaded */
    public const DOWNLOADABLE = [
        CicSubmission::STATUS_ENCRYPTED, CicSubmission::STATUS_SUBMITTED,
        CicSubmission::STATUS_ACCEPTED, CicSubmission::STATUS_REJECTED,
    ];

    /** @var list<string> the statuses a correction may correct */
    public const CORRECTABLE = [CicSubmission::STATUS_SUBMITTED, CicSubmission::STATUS_ACCEPTED, CicSubmission::STATUS_REJECTED];

    /** @var list<string> a file's statuses once it has gone to CIC */
    public const FILED = self::CORRECTABLE;

    /** How long the file screens keep a capture's validation (captures never change; generate and encrypt re-validate). */
    public const VALIDATION_CACHE_SECONDS = 600;

    public function __construct(
        private readonly CicFileBuilder $builder,
        private readonly CicEncryptor $encryptor,
        private readonly ValidatesCicCapture $validator,
    ) {}

    /**
     * Generate a file: validated, refused while any error remains or while
     * warnings aren't confirmed.
     *
     * @param  array{capture_id: int, submission_type: int, comments?: string|null, confirm_warnings?: bool|int|string, corrects_submission_id?: int|null, correction_reason?: string|null}  $input
     * @return array{submission: CicSubmission, affected: list<array{record_type: string, record_key: string, change: string}>, validation: array<string, mixed>}
     */
    public function generate(User $user, array $input): array
    {
        $type = (int) $input['submission_type'];
        $comments = isset($input['comments']) && trim((string) $input['comments']) !== '' ? trim((string) $input['comments']) : null;

        /** @var CicCapture $capture */
        $capture = CicCapture::query()->findOrFail($input['capture_id']);

        if (! $capture->isComplete()) {
            throw CicSubmissionRefusal::unprocessable('This capture isn\'t complete, so no file can be made from it.', 'capture_incomplete');
        }

        [$original, $affected] = $type === CicSubmission::TYPE_CORRECTION
            ? $this->correctionTarget($capture, $input)
            : $this->standardTarget($capture);

        $this->builder->providerCode();
        $this->assertCaptureIntact($capture);

        $validation = $this->validate($capture);

        if ($validation['errors'] > 0) {
            throw CicSubmissionRefusal::unprocessable(
                "The file still has {$validation['errors']} ".str('error')->plural($validation['errors']).', so it can\'t be generated. Fix them on the records screens, take a new capture, then generate again.',
                'validation_errors',
                ['validation' => $validation],
            );
        }

        if ($validation['warnings'] > 0 && ! filter_var($input['confirm_warnings'] ?? false, FILTER_VALIDATE_BOOL)) {
            throw CicSubmissionRefusal::unprocessable(
                "The file has {$validation['warnings']} ".str('warning')->plural($validation['warnings']).'. Review them and confirm to generate it anyway.',
                'warnings_unconfirmed',
                ['validation' => $validation],
            );
        }

        $generatedAt = now()->startOfSecond();
        $file = $this->builder->build($capture, $type, $comments, $generatedAt);

        try {
            $submission = DB::transaction(fn (): CicSubmission => $this->store($user, $capture, $type, $comments, $original, $input, $file, $generatedAt, $validation, $affected));
        } catch (UniqueConstraintViolationException) {
            throw self::filenameTaken();
        }

        return ['submission' => $submission, 'affected' => $affected, 'validation' => $validation];
    }

    /**
     * Save the generated file and its audit row, inside generate()'s
     * transaction. A standard file from a correction capture locks its
     * month-end capture and re-checks that nothing of the month was filed.
     *
     * @param  array<string, mixed>  $input
     * @param  array{errors: int, warnings: int, top_issues: list<array<string, mixed>>}  $validation
     * @param  list<array{record_type: string, record_key: string, change: string}>  $affected
     */
    private function store(User $user, CicCapture $capture, int $type, ?string $comments, ?CicSubmission $original, array $input, CicFile $file, CarbonInterface $generatedAt, array $validation, array $affected): CicSubmission
    {
        if ($original === null && $capture->kind === CicCapture::KIND_CORRECTION) {
            $this->assertMonthNotFiled($this->lockedFamilyRoot($capture));
        }

        if (CicSubmission::query()->where('filename', $file->filename)->exists()) {
            throw self::filenameTaken();
        }

        $submission = new CicSubmission;
        $submission->forceFill([
            'capture_id' => $capture->id,
            'reference_date' => $capture->reference_date->toDateString(),
            'submission_type' => $type,
            'corrects_submission_id' => $original?->id,
            'correction_reason' => $original === null ? null : trim((string) $input['correction_reason']),
            'affected_records' => $original === null ? null : $affected,
            'filename' => $file->filename,
            'record_count' => $file->recordCount,
            'txt_sha256' => $file->sha256(),
            'status' => CicSubmission::STATUS_GENERATED,
            'comments' => $comments,
            'generated_by' => $user->id,
            'generated_at' => $generatedAt,
        ])->save();

        $counts = self::countsInWords($file);
        $description = $original === null
            ? "CIC file {$file->filename} generated from ".($capture->kind === CicCapture::KIND_CORRECTION ? 'correction ' : '')."capture #{$capture->id} (reference date {$capture->reference_date->toDateString()}, standard reporting): {$file->recordCount} records ({$counts})"
            : "CIC correction file {$file->filename} generated from capture #{$capture->id}, correcting submission #{$original->id} ({$original->filename}): {$file->recordCount} records ({$counts}), ".count($affected).' affected';

        AuditLogService::log('cic_file_generated', $submission, null, [
            'capture_id' => $capture->id,
            'capture_kind' => $capture->kind,
            'reference_date' => $capture->reference_date->toDateString(),
            'submission_type' => $type,
            'corrects_submission_id' => $original?->id,
            'filename' => $file->filename,
            'record_count' => $file->recordCount,
            'counts_by_type' => $file->countsByType,
            'txt_sha256' => $file->sha256(),
            'errors' => $validation['errors'],
            'warnings' => $validation['warnings'],
            'warnings_confirmed' => $validation['warnings'] > 0,
            'affected_count' => $original === null ? null : count($affected),
        ], $description);

        return $submission;
    }

    /** Encrypt a generated file to the active, verified, unexpired key; keep only the `.zip.gpg`. */
    public function encrypt(User $user, int $submissionId): CicSubmission
    {
        return DB::transaction(function () use ($user, $submissionId): CicSubmission {
            $submission = $this->locked($submissionId);

            if ($submission->status !== CicSubmission::STATUS_GENERATED) {
                throw CicSubmissionRefusal::unprocessable('Only a generated file can be encrypted; this one is already '.CicSubmissionPresenter::statusWords($submission->status).'.', 'not_generated');
            }

            $key = CicPublicKey::activeKey();
            $unusable = self::keyProblem($key);

            if ($unusable !== null) {
                throw CicSubmissionRefusal::unprocessable($unusable, 'key_unusable');
            }

            /** @var CicCapture $capture */
            $capture = $submission->capture()->firstOrFail();
            $this->assertCaptureIntact($capture);
            $validation = $this->validate($capture);

            if ($validation['errors'] > 0) {
                throw CicSubmissionRefusal::unprocessable('The file\'s capture now has '.$validation['errors'].' '.str('error')->plural($validation['errors']).', so it can\'t be encrypted. Take a new capture and generate a new file.', 'validation_errors', ['validation' => $validation]);
            }

            $file = $this->builder->forSubmission($submission);

            if ($file->filename !== $submission->filename || $file->sha256() !== $submission->txt_sha256) {
                throw CicSubmissionRefusal::conflict('The file rebuilt from its capture no longer matches the generated file (its checksum differs), so it wasn\'t encrypted. Generate a new file.', 'file_changed');
            }

            try {
                $encrypted = $this->encryptor->encrypt($file, $key);
            } catch (CicEncryptionFailed $failed) {
                throw CicSubmissionRefusal::unprocessable($failed->getMessage(), 'encryption_failed');
            }

            $path = "cic/submissions/{$submission->id}/{$file->encryptedName()}";
            $disk = Storage::disk(self::DISK);

            if (! $disk->put($path, $encrypted->contents)) {
                throw CicSubmissionRefusal::unprocessable('The encrypted file could not be stored. Nothing was saved.', 'storage_failed');
            }

            try {
                $submission->forceFill([
                    'status' => CicSubmission::STATUS_ENCRYPTED,
                    'zip_sha256' => $encrypted->zipSha256,
                    'gpg_sha256' => $encrypted->sha256(),
                    'gpg_path' => $path,
                    'key_fingerprint' => $key->fingerprint,
                    'encrypted_by' => $user->id,
                    'encrypted_at' => now(),
                ])->save();

                AuditLogService::log('cic_file_encrypted', $submission, ['status' => CicSubmission::STATUS_GENERATED], [
                    'status' => CicSubmission::STATUS_ENCRYPTED,
                    'encrypted_filename' => $file->encryptedName(),
                    'zip_sha256' => $encrypted->zipSha256,
                    'gpg_sha256' => $encrypted->sha256(),
                    'key_id' => $key->id,
                    'key_fingerprint' => $key->fingerprint,
                ], "CIC file {$submission->filename} encrypted as {$file->encryptedName()} with key {$key->fingerprint}");
            } catch (Throwable $e) {
                $disk->delete($path);

                throw $e;
            }

            return $submission;
        });
    }

    /**
     * Count and audit one download, then give the stored path and the name it
     * downloads as. Only the encrypted file is ever served.
     *
     * @return array{path: string, name: string}
     */
    public function recordDownload(int $submissionId): array
    {
        return DB::transaction(function () use ($submissionId): array {
            $submission = $this->locked($submissionId);

            if (! in_array($submission->status, self::DOWNLOADABLE, true) || $submission->gpg_path === null) {
                throw CicSubmissionRefusal::unprocessable('This file isn\'t encrypted yet, so there is nothing to download. Only the encrypted file is ever downloaded.', 'not_encrypted');
            }

            $disk = Storage::disk(self::DISK);

            if (! $disk->exists($submission->gpg_path)) {
                throw CicSubmissionRefusal::conflict('The encrypted file is missing from storage. Generate and encrypt a new file.', 'file_missing');
            }

            if (hash('sha256', (string) $disk->get($submission->gpg_path)) !== $submission->gpg_sha256) {
                throw CicSubmissionRefusal::conflict('The stored encrypted file doesn\'t match its checksum, so it wasn\'t served. Generate and encrypt a new file.', 'file_changed');
            }

            $count = $submission->download_count + 1;
            $submission->forceFill(['download_count' => $count])->save();
            $name = CicFile::baseOf($submission->filename).'.zip.gpg';

            AuditLogService::log('cic_file_downloaded', $submission, null, [
                'encrypted_filename' => $name,
                'download_count' => $count,
                'status' => $submission->status,
            ], "CIC encrypted file {$name} downloaded (download {$count})");

            return ['path' => $submission->gpg_path, 'name' => $name];
        });
    }

    public function markSubmitted(User $user, int $submissionId): CicSubmission
    {
        return DB::transaction(function () use ($user, $submissionId): CicSubmission {
            // The month's capture is locked before the file, as generate()
            // locks it, so two standard files of one month are never both
            // marked submitted.
            $captureId = CicSubmission::query()->whereKey($submissionId)->value('capture_id');
            $capture = $captureId === null ? null : CicCapture::query()->find($captureId);
            $root = $capture === null ? null : $this->lockedFamilyRoot($capture);
            $submission = $this->locked($submissionId);

            if ($submission->status !== CicSubmission::STATUS_ENCRYPTED) {
                throw CicSubmissionRefusal::unprocessable('Only an encrypted file can be marked submitted; this one is '.CicSubmissionPresenter::statusWords($submission->status).'.', 'not_encrypted');
            }

            if (! $submission->isCorrection() && $root !== null) {
                $this->assertMonthNotFiled($root, standardOnly: true);
            }

            $submission->forceFill([
                'status' => CicSubmission::STATUS_SUBMITTED,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
            ])->save();

            AuditLogService::log('cic_file_submitted', $submission, ['status' => CicSubmission::STATUS_ENCRYPTED], [
                'status' => CicSubmission::STATUS_SUBMITTED,
            ], "CIC file {$submission->filename} marked submitted to CIC");

            return $submission;
        });
    }

    public function acknowledge(User $user, int $submissionId, string $status, ?string $notes, ?string $cicReferenceNo = null): CicSubmission
    {
        return DB::transaction(function () use ($user, $submissionId, $status, $notes, $cicReferenceNo): CicSubmission {
            $submission = $this->locked($submissionId);

            if ($submission->status !== CicSubmission::STATUS_SUBMITTED) {
                throw CicSubmissionRefusal::unprocessable('CIC\'s answer can be recorded only for a file marked submitted; this one is '.CicSubmissionPresenter::statusWords($submission->status).'.', 'not_submitted');
            }

            if ($submission->generated_by === $user->id) {
                throw CicSubmissionRefusal::forbidden('The person who generated a file can\'t record CIC\'s answer to it. Ask another reviewer.', 'generator_cannot_review');
            }

            $submission->forceFill([
                'status' => $status,
                'acknowledged_by' => $user->id,
                'acknowledged_at' => now(),
                'acknowledgement_notes' => $notes,
                'cic_reference_no' => $cicReferenceNo,
            ])->save();

            AuditLogService::log('cic_file_acknowledged', $submission, ['status' => CicSubmission::STATUS_SUBMITTED], [
                'status' => $status,
                'notes_length' => $notes === null ? 0 : mb_strlen($notes),
                'cic_reference_no' => $cicReferenceNo,
            ], "CIC's answer recorded for {$submission->filename}: ".CicSubmissionPresenter::statusWords($status)
                .($cicReferenceNo === null ? '' : " (CIC reference {$cicReferenceNo})"));

            return $submission;
        });
    }

    /**
     * Why the key can't be used to encrypt, in words, or null when it can.
     */
    public static function keyProblem(?CicPublicKey $key): ?string
    {
        return match (true) {
            $key === null => 'No CIC public key is active. Upload CIC\'s key and have it verified in CIC Settings first.',
            ! $key->isActive() || $key->verified_at === null || $key->verified_by === null => 'The CIC public key hasn\'t been verified, so it can\'t be used. Have a second administrator verify its fingerprint with CIC.',
            $key->isExpired() => 'The active CIC public key has expired. Upload CIC\'s current key and have it verified.',
            default => null,
        };
    }

    /**
     * The capture's validation, with the values that would break the file.
     *
     * @return array{errors: int, warnings: int, top_issues: list<array<string, mixed>>}
     */
    public function validate(CicCapture $capture): array
    {
        return CicIssueSummary::of([...$this->validator->issues($capture), ...$this->builder->unsafeValues($capture)]);
    }

    /**
     * validate() for the file screens (the history and a file's page), kept
     * for a while by capture id and checksum: a complete capture never
     * changes, so a page view never re-validates the whole capture. Generate
     * and encrypt always validate afresh.
     *
     * @return array{errors: int, warnings: int, top_issues: list<array<string, mixed>>}
     */
    public function reviewValidation(CicCapture $capture): array
    {
        if (! $capture->isComplete() || $capture->checksum === null) {
            return $this->validate($capture);
        }

        return CicCache::remember(
            "cic:submission-validation:{$capture->id}:{$capture->checksum}:".CicValidator::cacheFingerprint(),
            self::VALIDATION_CACHE_SECONDS,
            fn (): array => $this->validate($capture),
        );
    }

    /**
     * The records a correction changes, against the file it corrects: kept on
     * the submission when it was generated (both captures are frozen), and
     * worked out again only for a correction saved before that was kept.
     *
     * @return list<array{record_type: string, record_key: string, change: string}>
     */
    public function affected(CicSubmission $correction): array
    {
        if (! $correction->isCorrection() || $correction->corrects_submission_id === null) {
            return [];
        }

        if (is_array($correction->affected_records)) {
            return array_map(fn (array $row): array => [
                'record_type' => (string) $row['record_type'],
                'record_key' => (string) $row['record_key'],
                'change' => (string) $row['change'],
            ], $correction->affected_records);
        }

        /** @var CicSubmission $original */
        $original = $correction->corrects()->firstOrFail();

        return CicCorrectionDiff::affected($correction->capture()->firstOrFail(), $original->capture()->firstOrFail());
    }

    /**
     * A standard file comes from a month-end or test capture, or from a
     * correction capture of a month-end while nothing of that month has gone
     * to CIC (review M-H1: a month whose capture had errors is refiled from a
     * correction). The file reads that correction capture's records.
     *
     * @return array{0: null, 1: list<never>}
     */
    private function standardTarget(CicCapture $capture): array
    {
        if ($capture->kind === CicCapture::KIND_CORRECTION) {
            /** @var CicCapture|null $root */
            $root = $capture->corrects_capture_id === null ? null : CicCapture::query()->find($capture->corrects_capture_id);

            if ($root === null || $root->kind !== CicCapture::KIND_MONTH_END) {
                throw CicSubmissionRefusal::unprocessable('This correction capture doesn\'t correct a month-end capture, so it makes correction files only. Choose Correction and the file it corrects.', 'correction_capture');
            }

            $this->assertMonthNotFiled($root);
        }

        return [null, []];
    }

    /** The month-end capture at the root of this capture's family, locked. */
    private function lockedFamilyRoot(CicCapture $capture): ?CicCapture
    {
        $rootId = $capture->kind === CicCapture::KIND_CORRECTION ? $capture->corrects_capture_id : $capture->id;

        if ($rootId === null) {
            return null;
        }

        /** @var CicCapture|null $root */
        $root = CicCapture::query()->whereKey($rootId)->lockForUpdate()->first();

        return $root !== null && $root->kind === CicCapture::KIND_MONTH_END ? $root : null;
    }

    /**
     * Refuse while a file of the month (from its month-end capture or any
     * correction capture of it) has gone to CIC: submitted, accepted or
     * rejected. With $standardOnly, only standard files count (a month may
     * still be marked submitted after its corrections, which follow it).
     */
    private function assertMonthNotFiled(?CicCapture $root, bool $standardOnly = false): void
    {
        if ($root === null) {
            return;
        }

        $filed = CicSubmission::query()
            ->whereIn('capture_id', CicCapture::query()->select('id')
                ->whereKey($root->id)
                ->orWhere('corrects_capture_id', $root->id))
            ->whereIn('status', self::FILED)
            ->when($standardOnly, fn ($query) => $query->where('submission_type', CicSubmission::TYPE_STANDARD))
            ->orderBy('id')
            ->first();

        if ($filed !== null) {
            throw CicSubmissionRefusal::unprocessable(
                "The {$root->reference_date->toDateString()} file {$filed->filename} was already ".match ($filed->status) {
                    CicSubmission::STATUS_ACCEPTED => 'accepted by CIC',
                    CicSubmission::STATUS_REJECTED => 'rejected by CIC',
                    default => 'submitted to CIC',
                }.', so no new standard file can be made or submitted for that month. Make a correction file of it instead.',
                'month_already_filed',
            );
        }
    }

    /** The capture's records still match its checksum (review S-L6). */
    private function assertCaptureIntact(CicCapture $capture): void
    {
        $stored = (string) $capture->checksum;

        if ($stored === '' || ! hash_equals($stored, $capture->computeChecksum())) {
            throw CicSubmissionRefusal::conflict(
                "Capture #{$capture->id}'s records no longer match its checksum, so no file was made or encrypted from it. Take a new capture.",
                'capture_changed',
            );
        }
    }

    private static function filenameTaken(): CicSubmissionRefusal
    {
        return CicSubmissionRefusal::conflict('Another file with the same name was made this second. Try again in a moment.', 'filename_taken');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: CicSubmission, 1: list<array{record_type: string, record_key: string, change: string}>}
     */
    private function correctionTarget(CicCapture $capture, array $input): array
    {
        /** @var CicSubmission $original */
        $original = CicSubmission::query()->findOrFail($input['corrects_submission_id']);

        if (! in_array($original->status, self::CORRECTABLE, true)) {
            throw CicSubmissionRefusal::unprocessable('Only a file already submitted to CIC (submitted, accepted or rejected) can be corrected; that one is '.CicSubmissionPresenter::statusWords($original->status).'.', 'not_correctable');
        }

        if ($capture->kind !== CicCapture::KIND_CORRECTION) {
            throw CicSubmissionRefusal::unprocessable('A correction file is made from a correction capture. Take a correction capture for the reference date first.', 'not_correction_capture');
        }

        if ($capture->reference_date->toDateString() !== $original->reference_date->toDateString()) {
            throw CicSubmissionRefusal::unprocessable('The correction capture\'s reference date must be the corrected file\'s ('.$original->reference_date->toDateString().').', 'reference_date_mismatch');
        }

        /** @var CicCapture $originalCapture */
        $originalCapture = $original->capture()->firstOrFail();

        // The capture this correction re-reads must be the one the corrected
        // file came from (a correction of a correction shares its root).
        if ($capture->corrects_capture_id === null
            || $capture->corrects_capture_id !== ($originalCapture->corrects_capture_id ?? $originalCapture->id)) {
            throw CicSubmissionRefusal::unprocessable('That correction capture corrects a different capture from the one the corrected file was made from. Take a correction capture of the corrected file\'s capture.', 'capture_mismatch');
        }

        $affected = CicCorrectionDiff::affected($capture, $originalCapture);

        if ($affected === []) {
            throw CicSubmissionRefusal::unprocessable('The correction capture holds the same records as the corrected file, so there is nothing to correct.', 'nothing_to_correct');
        }

        return [$original, $affected];
    }

    private function locked(int $submissionId): CicSubmission
    {
        /** @var CicSubmission|null $submission */
        $submission = CicSubmission::query()->whereKey($submissionId)->lockForUpdate()->first();

        return $submission ?? abort(404, 'There is no such CIC file.');
    }

    private static function countsInWords(CicFile $file): string
    {
        return implode(', ', array_map(fn (string $type, int $count) => "{$type} {$count}", array_keys($file->countsByType), $file->countsByType));
    }
}

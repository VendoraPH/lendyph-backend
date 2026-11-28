<?php

namespace App\Services\Cic\Submissions;

use Closure;
use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Models\CicPublicKey;
use App\Models\CicSubmission;
use App\Models\User;
use App\Services\Cic\CicSwitch;
use App\Services\Cic\File\CicEncryptor;
use App\Services\Cic\File\CicFile;
use App\Services\Cic\File\CicFileBuilder;

/**
 * How the CIC file screens read a submission: the history row, and the
 * pre-encryption review (prompt §12) with its validation summary, key,
 * correction link and status history. Ids, counts, filenames, checksums,
 * fingerprints, dates and staff names only: never a member's data.
 */
final class CicSubmissionPresenter
{
    private const STATUS_WORDS = [
        CicSubmission::STATUS_GENERATED => 'Generated',
        CicSubmission::STATUS_ENCRYPTED => 'Encrypted',
        CicSubmission::STATUS_SUBMITTED => 'Submitted to CIC',
        CicSubmission::STATUS_ACCEPTED => 'Accepted by CIC',
        CicSubmission::STATUS_REJECTED => 'Rejected by CIC',
    ];

    private const TYPE_WORDS = [
        CicSubmission::TYPE_STANDARD => 'Standard reporting',
        CicSubmission::TYPE_CORRECTION => 'Correction',
    ];

    public static function statusWords(string $status): string
    {
        return mb_strtolower(self::STATUS_WORDS[$status] ?? str_replace('_', ' ', $status));
    }

    /** The internal submission reference (§15): CIC-000012. */
    public static function reference(int $id): string
    {
        return 'CIC-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * History rows, with their staff read in one query, and each row's
     * `actions` for the caller when given.
     *
     * @param  iterable<CicSubmission>  $submissions
     * @param  (Closure(CicSubmission): array<string, bool>)|null  $actions
     * @return list<array<string, mixed>>
     */
    public static function rows(iterable $submissions, ?Closure $actions = null): array
    {
        $submissions = collect($submissions);
        $users = self::users($submissions->flatMap(fn (CicSubmission $s) => [$s->generated_by, $s->encrypted_by, $s->submitted_by, $s->acknowledged_by])->all());

        return $submissions->map(fn (CicSubmission $s) => [
            ...self::rowWith($s, $users),
            ...($actions === null ? [] : ['actions' => $actions($s)]),
        ])->values()->all();
    }

    /**
     * The pre-encryption review and the submission's record (§12, §13.6, §15).
     *
     * @param  array{errors: int, warnings: int, top_issues: list<array<string, mixed>>}  $validation
     * @param  list<array{record_type: string, record_key: string, change: string}>  $affected
     * @param  array<string, bool>  $actions
     * @return array<string, mixed>
     */
    public static function detail(CicSubmission $submission, array $validation, array $affected, CicEncryptor $encryptor, array $actions): array
    {
        $users = self::users([$submission->generated_by, $submission->encrypted_by, $submission->submitted_by, $submission->acknowledged_by]);
        /** @var CicCapture $capture */
        $capture = $submission->capture()->firstOrFail();
        $activeKey = CicPublicKey::activeKey();
        $usedKey = $submission->key_fingerprint === null
            ? null
            : CicPublicKey::query()->where('fingerprint', $submission->key_fingerprint)->first();
        $original = $submission->corrects()->first();

        return [
            ...self::rowWith($submission, $users),
            'provider_code' => self::providerCodeOf($submission->filename),
            'format_version' => CicSwitch::formatVersion(),
            'zip_filename' => CicFile::baseOf($submission->filename).'.zip',
            'counts_by_type' => self::countsByType($capture),
            'txt_sha256' => $submission->txt_sha256,
            'zip_sha256' => $submission->zip_sha256,
            'gpg_sha256' => $submission->gpg_sha256,
            'comments' => $submission->comments,
            'capture' => [
                'id' => $capture->id,
                'kind' => $capture->kind,
                'reference_date' => $capture->reference_date->toDateString(),
                'finished_at' => $capture->finished_at?->toJSON(),
                'checksum' => $capture->checksum,
            ],
            'validation' => $validation,
            'active_key' => self::key($activeKey),
            'encrypted_with' => $submission->key_fingerprint === null ? null : [
                'fingerprint' => $submission->key_fingerprint,
                'key_id' => $usedKey?->key_id,
                'user_ids' => $usedKey?->user_ids ?? [],
            ],
            'encryption' => [
                'method' => 'OpenPGP public-key encryption (GnuPG), encrypt only, not signed',
                'archive' => 'ZIP holding the TXT under its own filename',
                'output_extension' => '.zip.gpg',
                'recipient' => 'The active, verified CIC public key only',
                'gpg_available' => $encryptor->available(),
            ],
            'correction' => $submission->isCorrection() ? [
                'reason' => $submission->correction_reason,
                'corrects' => $original === null ? null : [
                    'id' => $original->id,
                    'reference' => self::reference($original->id),
                    'filename' => $original->filename,
                    'reference_date' => $original->reference_date->toDateString(),
                    'status' => $original->status,
                    'status_label' => self::STATUS_WORDS[$original->status] ?? $original->status,
                    'generated_at' => $original->generated_at->toJSON(),
                    'submitted_at' => $original->submitted_at?->toJSON(),
                    'record_count' => $original->record_count,
                ],
                'affected_count' => count($affected),
                'affected_records' => $affected,
            ] : null,
            'corrections' => $submission->corrections()->orderBy('id')->get(['id', 'filename', 'status', 'generated_at'])
                ->map(fn (CicSubmission $c) => [
                    'id' => $c->id,
                    'reference' => self::reference($c->id),
                    'filename' => $c->filename,
                    'status' => $c->status,
                    'status_label' => self::STATUS_WORDS[$c->status] ?? $c->status,
                    'generated_at' => $c->generated_at->toJSON(),
                ])->all(),
            'history' => self::history($submission, $users),
            'actions' => $actions,
        ];
    }

    /**
     * A key as the review shows it, with whether it can encrypt.
     *
     * @return array<string, mixed>|null
     */
    public static function key(?CicPublicKey $key): ?array
    {
        if ($key === null) {
            return null;
        }

        $problem = CicSubmissionService::keyProblem($key);

        return [
            'id' => $key->id,
            'fingerprint' => $key->fingerprint,
            'key_id' => $key->key_id,
            'algorithm' => $key->algorithm,
            'user_ids' => $key->user_ids ?? [],
            'key_created_at' => $key->key_created_at?->toJSON(),
            'expires_at' => $key->expires_at?->toJSON(),
            'verified_at' => $key->verified_at?->toJSON(),
            'expired' => $key->isExpired(),
            'usable' => $problem === null,
            'unusable_reason' => $problem,
        ];
    }

    /** @return array<string, int> */
    private static function countsByType(CicCapture $capture): array
    {
        $counts = array_fill_keys(CicFileBuilder::DATA_TYPES, 0);

        foreach (CicCaptureRecord::query()->toBase()->where('capture_id', $capture->id)
            ->selectRaw('record_type, COUNT(*) as aggregate')->groupBy('record_type')->get() as $row) {
            if (array_key_exists($row->record_type, $counts)) {
                $counts[$row->record_type] = (int) $row->aggregate;
            }
        }

        return $counts;
    }

    /**
     * @param  array<int, array{id: int, name: string}>  $users
     * @return array<string, mixed>
     */
    private static function rowWith(CicSubmission $submission, array $users): array
    {
        $person = fn (?int $id) => $id === null ? null : ($users[$id] ?? ['id' => $id, 'name' => null]);

        return [
            'id' => $submission->id,
            'reference' => self::reference($submission->id),
            'submission_type' => $submission->submission_type,
            'submission_type_label' => self::TYPE_WORDS[$submission->submission_type] ?? (string) $submission->submission_type,
            'status' => $submission->status,
            'status_label' => self::STATUS_WORDS[$submission->status] ?? $submission->status,
            'reference_date' => $submission->reference_date->toDateString(),
            'capture_id' => $submission->capture_id,
            'corrects_submission_id' => $submission->corrects_submission_id,
            'filename' => $submission->filename,
            'encrypted_filename' => $submission->gpg_path === null ? null : CicFile::baseOf($submission->filename).'.zip.gpg',
            'record_count' => $submission->record_count,
            'key_fingerprint' => $submission->key_fingerprint,
            'download_count' => $submission->download_count,
            'generated_at' => $submission->generated_at->toJSON(),
            'generated_by' => $person($submission->generated_by),
            'encrypted_at' => $submission->encrypted_at?->toJSON(),
            'encrypted_by' => $person($submission->encrypted_by),
            'submitted_at' => $submission->submitted_at?->toJSON(),
            'submitted_by' => $person($submission->submitted_by),
            'acknowledged_at' => $submission->acknowledged_at?->toJSON(),
            'acknowledged_by' => $person($submission->acknowledged_by),
            'acknowledgement_notes' => $submission->acknowledgement_notes,
            'cic_reference_no' => $submission->cic_reference_no,
        ];
    }

    /**
     * @param  array<int, array{id: int, name: string}>  $users
     * @return list<array{status: string, status_label: string, at: string|null, by: array{id: int, name: string|null}|null}>
     */
    private static function history(CicSubmission $submission, array $users): array
    {
        $steps = [
            [CicSubmission::STATUS_GENERATED, $submission->generated_at, $submission->generated_by],
            [CicSubmission::STATUS_ENCRYPTED, $submission->encrypted_at, $submission->encrypted_by],
            [CicSubmission::STATUS_SUBMITTED, $submission->submitted_at, $submission->submitted_by],
        ];

        if (in_array($submission->status, [CicSubmission::STATUS_ACCEPTED, CicSubmission::STATUS_REJECTED], true)) {
            $steps[] = [$submission->status, $submission->acknowledged_at, $submission->acknowledged_by];
        }

        $history = [];

        foreach ($steps as [$status, $at, $by]) {
            if ($at === null) {
                continue;
            }

            $history[] = [
                'status' => $status,
                'status_label' => self::STATUS_WORDS[$status],
                'at' => $at->toJSON(),
                'by' => $by === null ? null : ($users[$by] ?? ['id' => $by, 'name' => null]),
            ];
        }

        return $history;
    }

    /**
     * @param  list<int|null>  $ids
     * @return array<int, array{id: int, name: string}>
     */
    private static function users(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id !== null)));

        if ($ids === []) {
            return [];
        }

        return User::query()->whereKey($ids)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (User $user) => [$user->id => ['id' => $user->id, 'name' => trim("{$user->first_name} {$user->last_name}")]])
            ->all();
    }

    private static function providerCodeOf(string $filename): string
    {
        return (string) strstr($filename, '_CSDF_', true);
    }
}

<?php

namespace App\Http\Resources\Cda;

use App\Models\CdaDocument;
use App\Models\CdaDocumentVersion;
use App\Models\CdaMediationCase;
use App\Models\CdaOfficer;
use App\Models\CdaOfficerTraining;
use App\Models\CdaSocialProgram;
use App\Models\User;
use App\Services\Accounting\Money;
use App\Services\Cda\Registers\DocumentCategories;
use App\Services\Cda\Registers\RegisterWords;
use App\Services\Cda\ReportTypes;
use App\Services\SignedFileLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * How the CDA register endpoints present their rows (slice C3): dates as
 * YYYY-MM-DD, money and hours as two-decimal strings, keys with their words,
 * users as `{id, name}`, and files only as signed links (`url`, valid 30
 * minutes, bound to the user who listed them).
 *
 * A linked officer shows the member's id, name and code and sex, nothing
 * else of the member (round fz, CDA decision).
 */
final class RegisterPresenter
{
    /** `per_page` as the lists take it: 15 by default, at most 100. */
    public static function perPage(mixed $value): int
    {
        return min(max((int) ($value ?? 15), 1), 100);
    }

    /**
     * A list page in the `{success, data, meta}` envelope.
     *
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     */
    public static function page(LengthAwarePaginator $page, array $rows, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                ...$meta,
            ],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public static function one(array $data, int $status = 200, ?string $message = null): JsonResponse
    {
        return response()->json(array_filter(['success' => true, 'message' => $message, 'data' => $data], fn ($value) => $value !== null), $status);
    }

    /**
     * @param  array{required: list<string>, completed: list<string>, missing: list<string>, status: string, hours_centi: int}  $compliance
     * @return array<string, mixed>
     */
    public static function officer(CdaOfficer $officer, array $compliance): array
    {
        $member = $officer->borrower_id === null ? null : $officer->member;

        return [
            'id' => (int) $officer->id,
            'borrower_id' => $officer->borrower_id,
            'member' => $member === null ? null : ['id' => (int) $member->id, 'name' => $member->full_name, 'code' => $member->borrower_code],
            'user_id' => $officer->user_id,
            'name' => $officer->displayName(),
            'sex' => $officer->displaySex(),
            'position' => $officer->position,
            'position_label' => RegisterWords::position($officer->position),
            'committee' => $officer->committee,
            'position_title' => $officer->position_title,
            'term_start' => $officer->term_start->toDateString(),
            'term_end' => $officer->term_end?->toDateString(),
            'election_date' => $officer->election_date?->toDateString(),
            'status' => $officer->status,
            'remarks' => $officer->remarks,
            'trainings' => [
                'required' => count($compliance['required']),
                'completed' => count($compliance['completed']),
                'missing' => $compliance['missing'],
                'status' => $compliance['status'],
            ],
            'created_at' => $officer->created_at,
            'updated_at' => $officer->updated_at,
        ];
    }

    /**
     * @param  CdaDocument|null  $certificate  the training's certificate document, with its versions
     * @param  array<int, array{id: int, name: string}|null>|null  $users  from usersFor(), for a list
     * @return array<string, mixed>
     */
    public static function training(CdaOfficerTraining $training, ?CdaDocument $certificate, ?array $users = null): array
    {
        $officer = $training->officer;
        $latest = $certificate?->versions->sortByDesc('version')->first();

        return [
            'id' => (int) $training->id,
            'officer_id' => (int) $training->officer_id,
            'officer' => $officer === null ? null : ['id' => (int) $officer->id, 'name' => $officer->displayName(), 'position' => $officer->position],
            'title' => $training->title,
            'requirement_key' => $training->requirement_key,
            'training_type' => $training->training_type,
            'is_mandatory' => (bool) $training->is_mandatory,
            'provider' => $training->provider,
            'provider_accreditation_no' => $training->provider_accreditation_no,
            'started_on' => $training->started_on?->toDateString(),
            'completed_on' => $training->completed_on?->toDateString(),
            'hours' => RegisterWords::hundredths((int) Money::toCentavos((string) $training->hours)),
            'certificate_number' => $training->certificate_number,
            'status' => $training->status,
            'expires_on' => $training->expires_on?->toDateString(),
            'remarks' => $training->remarks,
            'certificate' => $latest === null ? null : [
                'cda_document_id' => (int) $certificate->id,
                'status' => $certificate->status,
                ...self::version($latest, $users ?? self::usersFor([$certificate])),
            ],
            'created_at' => $training->created_at,
            'updated_at' => $training->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    public static function socialProgram(CdaSocialProgram $program): array
    {
        $officer = $program->responsibleOfficer;

        return [
            'id' => (int) $program->id,
            'period_id' => (int) $program->period_id,
            'year' => (int) $program->period->year,
            'name' => $program->name,
            'category' => $program->category,
            'category_label' => RegisterWords::socialCategory($program->category),
            'description' => $program->description,
            'objective' => $program->objective,
            'held_on' => $program->held_on?->toDateString(),
            'ended_on' => $program->ended_on?->toDateString(),
            'location' => $program->location,
            'target_beneficiaries' => $program->target_beneficiaries,
            'actual_beneficiaries' => $program->actual_beneficiaries,
            'budget' => $program->budget === null ? null : self::money($program->budget),
            'actual_expense' => self::money($program->actual_expense),
            'funding_source' => $program->funding_source,
            'funding_source_label' => RegisterWords::fundingSource($program->funding_source),
            'cdf_amount' => self::money($program->cdf_amount),
            'status' => $program->status,
            'responsible_officer' => $officer === null ? null : ['id' => (int) $officer->id, 'name' => $officer->displayName()],
            'remarks' => $program->remarks,
            'created_at' => $program->created_at,
            'updated_at' => $program->updated_at,
        ];
    }

    /**
     * A case, with the halves of `$year` it belongs to.
     *
     * @return array<string, mixed>
     */
    public static function mediationCase(CdaMediationCase $case, int $year): array
    {
        $halves = array_keys(array_filter(
            ['h1' => ["{$year}-01-01", "{$year}-06-30"], 'h2' => ["{$year}-07-01", "{$year}-12-31"]],
            fn (array $range) => self::caseOverlaps($case, $range[0], $range[1]),
        ));

        return [
            'id' => (int) $case->id,
            'case_number' => $case->case_number,
            'filed_on' => $case->filed_on->toDateString(),
            'year' => $year,
            'halves' => $halves,
            'complainant' => $case->complainant,
            'respondent' => $case->respondent,
            'nature' => $case->nature,
            'description' => $case->description,
            'mediator' => $case->mediator,
            'mediation_started_on' => $case->mediation_started_on?->toDateString(),
            'meetings_count' => (int) $case->meetings_count,
            'resolution' => $case->resolution,
            'resolved_on' => $case->resolved_on?->toDateString(),
            'status' => $case->status,
            'status_label' => RegisterWords::mediationStatus($case->status),
            'remarks' => $case->remarks,
            'created_at' => $case->created_at,
            'updated_at' => $case->updated_at,
        ];
    }

    /**
     * A document with its latest version and its final one. Load `versions`
     * (with `file`), `period` and `report` first; pass usersFor() of the
     * whole page for a list.
     *
     * @param  array<int, array{id: int, name: string}|null>|null  $users
     * @return array<string, mixed>
     */
    public static function document(CdaDocument $document, ?array $users = null): array
    {
        $users ??= self::usersFor([$document]);
        $versions = $document->versions->sortByDesc('version')->values();
        $final = $versions->first(fn (CdaDocumentVersion $version) => $version->final_key !== null);
        $report = $document->report;

        return [
            'id' => (int) $document->id,
            'period_id' => $document->period_id,
            'year' => $document->period?->year === null ? null : (int) $document->period->year,
            'category' => $document->category,
            'category_label' => DocumentCategories::label($document->category),
            'report_id' => $document->report_id,
            'report_type' => $report?->report_type,
            'report_part' => $report?->part,
            'report_title' => $report === null ? null : ReportTypes::title($report->report_type),
            'subject_type' => $document->subject_type,
            'subject_id' => $document->subject_id,
            'title' => $document->title,
            'description' => $document->description,
            'status' => $document->status,
            'withdraw_reason' => $document->withdraw_reason,
            'expires_on' => $document->expires_on?->toDateString(),
            'version_count' => $versions->count(),
            'current_version' => $versions->isEmpty() ? null : self::version($versions->first(), $users),
            'final_version' => $final === null ? null : self::version($final, $users),
            'created_by' => $document->created_by === null ? null : ($users[$document->created_by] ?? null),
            'created_at' => $document->created_at,
            'updated_at' => $document->updated_at,
        ];
    }

    /**
     * @param  array<int, array{id: int, name: string}|null>|null  $users  from usersFor()
     * @return array<string, mixed>
     */
    public static function version(CdaDocumentVersion $version, ?array $users = null): array
    {
        $file = $version->file;
        $uploader = $version->uploaded_by;
        $users ??= self::users([$uploader]);

        return [
            'id' => (int) $version->id,
            'version' => (int) $version->version,
            'version_label' => $version->version_label,
            'is_final' => $version->final_key !== null,
            'origin' => $version->origin,
            'format' => $version->format,
            'is_draft' => (bool) $version->is_draft,
            'snapshot_id' => $version->snapshot_id,
            'file_name' => $file?->original_filename,
            'mime_type' => $file?->mime_type,
            'file_size' => $file?->file_size === null ? null : (int) $file->file_size,
            'sha256' => $version->sha256,
            'notes' => $version->notes,
            'uploaded_by' => $uploader === null ? null : ($users[$uploader] ?? null),
            'created_at' => $version->created_at,
            'url' => $file === null ? null : SignedFileLink::to('files.document', ['document' => $file->getKey()]),
        ];
    }

    /**
     * Who created the documents and uploaded their versions, in one query,
     * keyed by user id, so a list never asks once per row.
     *
     * @param  iterable<CdaDocument|null>  $documents  with `versions` loaded
     * @return array<int, array{id: int, name: string}|null>
     */
    public static function usersFor(iterable $documents): array
    {
        $ids = [];

        foreach ($documents as $document) {
            if ($document === null) {
                continue;
            }

            $ids[] = $document->created_by;

            foreach ($document->versions as $version) {
                $ids[] = $version->uploaded_by;
            }
        }

        return self::users($ids);
    }

    /**
     * @param  list<int|null>  $ids
     * @return array<int, array{id: int, name: string}|null>
     */
    private static function users(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id !== null)));

        return $ids === [] ? [] : User::query()->whereKey($ids)->get()
            ->mapWithKeys(fn (User $user) => [(int) $user->id => CdaPresenter::user($user)])->all();
    }

    private static function money(mixed $value): string
    {
        return CdaPresenter::money((int) Money::toCentavos((string) $value));
    }

    private static function caseOverlaps(CdaMediationCase $case, string $from, string $to): bool
    {
        return $case->filed_on->toDateString() <= $to
            && ($case->resolved_on === null || $case->resolved_on->toDateString() >= $from);
    }
}

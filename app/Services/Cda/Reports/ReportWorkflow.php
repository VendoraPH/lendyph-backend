<?php

namespace App\Services\Cda\Reports;

use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use App\Models\CdaSubmission;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Cda\CdaAccess;
use App\Services\LoanWriteTransaction;
use App\Support\Sentence;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * The CDA report workflow (design §5). Every action locks the report row,
 * re-checks its status (a stale one answers 409) and writes one audit row
 * (from, to, action, reason).
 *
 * | From | Action | To | Permission |
 * |---|---|---|---|
 * | draft | refresh (alias validate) | draft | prepare |
 * | draft | submit_for_review | for_review | prepare; refreshes first; Critical → 422 |
 * | for_review, approved | return {reason} | draft, sub_status returned | review |
 * | for_review | approve | approved | approve; not the submitter (403); Critical → 422 |
 * | approved | finalize {confirm_warnings} | finalized | finalize; 409 when a figure moved since approval; writes the snapshot |
 * | finalized | mark_submitted {submission} | submitted | submit; creates the one submission row |
 * | submitted | mark_accepted | accepted | submit |
 * | submitted, accepted | mark_for_correction {reason} | for_correction | submit |
 * | finalized, submitted, for_correction | reopen {reason} | draft | reopen; supersedes the snapshot |
 * | (financial statements, sworn statement) | set_stage {stage} | same | prepare |
 *
 * A Critical issue blocks submit_for_review, approve and finalize; a
 * document needed at a later stage (submit) blocks only that stage, and
 * filing (mark_submitted) is blocked by nothing else. A finalized report's
 * stored issues are never rewritten. Warnings
 * need `confirm_warnings` to finalize, and the confirmation is audited.
 * Notices go out after commit.
 */
final class ReportWorkflow
{
    public const ACTIONS = [
        'refresh' => ['permission' => CdaAccess::PREPARE, 'from' => [CdaReport::STATUS_DRAFT]],
        'validate' => ['permission' => CdaAccess::PREPARE, 'from' => [CdaReport::STATUS_DRAFT]],
        'submit_for_review' => ['permission' => CdaAccess::PREPARE, 'from' => [CdaReport::STATUS_DRAFT]],
        'return' => ['permission' => CdaAccess::REVIEW, 'from' => [CdaReport::STATUS_FOR_REVIEW, CdaReport::STATUS_APPROVED]],
        'approve' => ['permission' => CdaAccess::APPROVE, 'from' => [CdaReport::STATUS_FOR_REVIEW]],
        'finalize' => ['permission' => CdaAccess::FINALIZE, 'from' => [CdaReport::STATUS_APPROVED]],
        'mark_submitted' => ['permission' => CdaAccess::SUBMIT, 'from' => [CdaReport::STATUS_FINALIZED]],
        'mark_accepted' => ['permission' => CdaAccess::SUBMIT, 'from' => [CdaReport::STATUS_SUBMITTED]],
        'mark_for_correction' => ['permission' => CdaAccess::SUBMIT, 'from' => [CdaReport::STATUS_SUBMITTED, CdaReport::STATUS_ACCEPTED]],
        'reopen' => ['permission' => CdaAccess::REOPEN, 'from' => [CdaReport::STATUS_FINALIZED, CdaReport::STATUS_SUBMITTED, CdaReport::STATUS_FOR_CORRECTION]],
        'set_stage' => ['permission' => CdaAccess::PREPARE, 'from' => [
            CdaReport::STATUS_DRAFT, CdaReport::STATUS_FOR_REVIEW, CdaReport::STATUS_APPROVED, CdaReport::STATUS_FINALIZED,
            CdaReport::STATUS_SUBMITTED, CdaReport::STATUS_FOR_CORRECTION,
        ]],
    ];

    /** The actions that need a reason. */
    public const NEED_REASON = ['return', 'mark_for_correction', 'reopen'];

    private const STATUS_WORDS = [
        'draft' => 'a draft', 'for_review' => 'for review', 'approved' => 'approved', 'finalized' => 'finalized',
        'submitted' => 'submitted', 'accepted' => 'accepted', 'for_correction' => 'for correction',
    ];

    private const ACTION_WORDS = [
        'refresh' => 'refreshed', 'validate' => 'refreshed', 'submit_for_review' => 'submitted for review', 'return' => 'returned',
        'approve' => 'approved', 'finalize' => 'finalized', 'mark_submitted' => 'marked as filed', 'mark_accepted' => 'marked as accepted',
        'mark_for_correction' => 'marked for correction', 'reopen' => 'reopened', 'set_stage' => 'moved to another stage',
    ];

    public function __construct(
        private readonly ReportEngine $engine,
        private readonly SnapshotWriter $snapshots,
        private readonly ReportStages $stages,
        private readonly WorkflowNotices $notices,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function run(CdaReport $report, string $action, array $input, User $user): CdaReport
    {
        $outcome = DB::transaction(function () use ($report, $action, $input, $user): array {
            $locked = CdaReport::query()->with(['template', 'period', 'currentSnapshot'])->whereKey($report->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, self::ACTIONS[$action]['from'], true)) {
                throw LoanWriteTransaction::conflict(sprintf(
                    'This report is %s, so it can\'t be %s now. Reload it to see its current status.',
                    self::STATUS_WORDS[$locked->status] ?? $locked->status,
                    self::ACTION_WORDS[$action],
                ));
            }

            if (! $locked->applicable && $action !== 'refresh' && $action !== 'validate') {
                throw self::refused($locked->not_applicable_reason ?? 'This report doesn\'t apply to the cooperative for this period.');
            }

            return match ($action) {
                'refresh', 'validate' => $this->refresh($locked, $user),
                'submit_for_review' => $this->submitForReview($locked, $user),
                'return' => $this->returnToDraft($locked, (string) $input['reason'], $user),
                'approve' => $this->approve($locked, $user),
                'finalize' => $this->finalize($locked, (bool) ($input['confirm_warnings'] ?? false), $user),
                'mark_submitted' => $this->markSubmitted($locked, (array) $input['submission'], $user),
                'mark_accepted' => $this->markAccepted($locked, $user),
                'mark_for_correction' => $this->markForCorrection($locked, (string) $input['reason'], $user),
                'reopen' => $this->reopen($locked, (string) $input['reason'], $user),
                'set_stage' => $this->setStage($locked, (string) $input['stage']),
            };
        });

        if (isset($outcome['blocked'])) {
            throw self::refused($outcome['blocked'], $outcome['issues'] ?? []);
        }

        return $outcome['report'];
    }

    /** The actions the user can take on the report now. @return list<string> */
    public static function available(CdaReport $report, User $user, CdaAccess $access): array
    {
        if (! $report->applicable) {
            return [];
        }

        $actions = [];

        foreach (self::ACTIONS as $action => $spec) {
            if ($action === 'validate' || ! in_array($report->status, $spec['from'], true) || ! $access->allows($user, $spec['permission'])) {
                continue;
            }

            if ($action === 'set_stage' && ! isset(ReportStages::STAGES[$report->report_type])) {
                continue;
            }

            if ($action === 'approve' && (int) $report->submitted_for_review_by === (int) $user->id) {
                continue;
            }

            $actions[] = $action;
        }

        return $actions;
    }

    private function refresh(CdaReport $report, User $user): array
    {
        $this->engine->refresh($report, $user);

        return ['report' => $report];
    }

    private function submitForReview(CdaReport $report, User $user): array
    {
        $this->engine->refresh($report, $user);
        $blocked = $this->blocking($report, 'submit_for_review');

        if ($blocked !== null) {
            return $blocked;
        }

        $report->forceFill([
            'status' => CdaReport::STATUS_FOR_REVIEW,
            'sub_status' => null,
            'submitted_for_review_by' => $user->id,
            'submitted_for_review_at' => now(),
        ])->save();
        $this->audit($report, 'submit_for_review', CdaReport::STATUS_DRAFT);
        $this->engine->revalidate($report);
        $this->notices->approvalNeeded($report, $user);

        return ['report' => $report];
    }

    private function returnToDraft(CdaReport $report, string $reason, User $user): array
    {
        $from = $report->status;
        $report->forceFill([
            'status' => CdaReport::STATUS_DRAFT,
            'sub_status' => CdaReport::SUB_STATUS_RETURNED,
            'approved_by' => null,
            'approved_at' => null,
        ])->save();
        $this->audit($report, 'return', $from, $reason);
        $this->engine->revalidate($report);
        $this->notices->returned($report, $user, $reason);

        return ['report' => $report];
    }

    private function approve(CdaReport $report, User $user): array
    {
        if ((int) $report->submitted_for_review_by === (int) $user->id) {
            throw new HttpResponseException(response()->json(['message' => 'You submitted this report for review, so someone else has to approve it.'], 403));
        }

        $this->engine->revalidate($report);
        $blocked = $this->blocking($report, 'approve');

        if ($blocked !== null) {
            return $blocked;
        }

        $report->forceFill(['status' => CdaReport::STATUS_APPROVED, 'approved_by' => $user->id, 'approved_at' => now()])->save();
        $this->audit($report, 'approve', CdaReport::STATUS_FOR_REVIEW);
        $this->engine->revalidate($report);

        return ['report' => $report];
    }

    private function finalize(CdaReport $report, bool $confirmWarnings, User $user): array
    {
        $stored = $this->engine->storedValues($report);
        $changed = [];
        $definition = $this->engine->definition($report);

        foreach ($this->engine->freshValues($report) as $key => $value) {
            if (! ReportEngine::same($stored[$key] ?? null, $value)) {
                $changed[] = ReportValidator::label($definition->field($key)) ?: $key;
            }
        }

        if ($changed !== []) {
            throw LoanWriteTransaction::conflict('Figures changed since this report was approved ('.implode(', ', $changed).'). Return it to draft, refresh and approve it again.');
        }

        $this->engine->revalidate($report);
        $blocked = $this->blocking($report, 'finalize');

        if ($blocked !== null) {
            return $blocked;
        }

        if ($report->warning_count > 0 && ! $confirmWarnings) {
            $count = $report->warning_count;

            return ['blocked' => "This report has {$count} ".($count === 1 ? 'warning' : 'warnings').'. Confirm the warnings to finalize it.', 'issues' => array_values(array_filter($report->issues ?? [], fn (array $issue) => $issue['severity'] === 'warning'))];
        }

        $report->forceFill(['status' => CdaReport::STATUS_FINALIZED, 'sub_status' => null, 'finalized_by' => $user->id, 'finalized_at' => now()])->save();
        $snapshot = $this->snapshots->write($report, $definition, $user, $confirmWarnings && $report->warning_count > 0);
        $report->forceFill(['current_snapshot_id' => $snapshot->id])->save();
        $this->audit($report, 'finalize', CdaReport::STATUS_APPROVED, null, [
            'snapshot_version' => $snapshot->version,
            'checksum' => $snapshot->checksum,
            'warnings_confirmed' => $confirmWarnings && $report->warning_count > 0,
            'warning_count' => $report->warning_count,
        ]);
        $this->notices->finalized($report, $user);

        return ['report' => $report];
    }

    /**
     * Records a filing that already happened: only a Critical document needed
     * at filing (checked live, not stored: the report's issues stay as
     * finalized) can stop it, and the refusal names what to upload.
     */
    private function markSubmitted(CdaReport $report, array $submission, User $user): array
    {
        $missing = array_values(array_filter($this->engine->missingDocuments($report, 'submit'), fn (array $document) => ReportValidator::blocks($document['issue'], 'submit')));

        if ($missing !== []) {
            return ['blocked' => 'Upload '.self::documentList($missing).' before recording the filing.', 'issues' => array_column($missing, 'issue')];
        }

        if (CdaSubmission::query()->where('report_id', $report->id)->where('snapshot_id', $report->current_snapshot_id)->exists()) {
            throw LoanWriteTransaction::conflict('This version of the report is already recorded as filed.');
        }

        $row = CdaSubmission::query()->create([
            'report_id' => $report->id,
            'snapshot_id' => $report->current_snapshot_id,
            'method' => $submission['method'],
            'submitted_on' => $submission['submitted_on'],
            'submitted_by_name' => $submission['submitted_by_name'] ?? null,
            'recorded_by' => $user->id,
            'cda_reference_no' => $submission['cda_reference_no'] ?? null,
            'acknowledgment_no' => $submission['acknowledgment_no'] ?? null,
            'status' => 'submitted',
            'remarks' => $submission['remarks'] ?? null,
        ]);

        $report->forceFill(['status' => CdaReport::STATUS_SUBMITTED])->save();
        $this->audit($report, 'mark_submitted', CdaReport::STATUS_FINALIZED, null, ['submission_id' => $row->id, 'method' => $row->method, 'submitted_on' => $submission['submitted_on']]);

        return ['report' => $report];
    }

    private function markAccepted(CdaReport $report, User $user): array
    {
        $report->forceFill(['status' => CdaReport::STATUS_ACCEPTED])->save();
        $this->latestSubmission($report)?->update(['status' => 'accepted']);
        $this->audit($report, 'mark_accepted', CdaReport::STATUS_SUBMITTED);

        return ['report' => $report];
    }

    private function markForCorrection(CdaReport $report, string $reason, User $user): array
    {
        $from = $report->status;
        $report->forceFill(['status' => CdaReport::STATUS_FOR_CORRECTION])->save();
        $this->latestSubmission($report)?->update(['status' => 'requires_correction']);
        $this->audit($report, 'mark_for_correction', $from, $reason);
        $this->notices->forCorrection($report, $user, $reason);

        return ['report' => $report];
    }

    private function reopen(CdaReport $report, string $reason, User $user): array
    {
        $from = $report->status;
        $snapshot = $report->currentSnapshot;

        $snapshot?->forceFill(['superseded_at' => now(), 'reopened_by' => $user->id, 'reopen_reason' => $reason])->save();
        $report->forceFill([
            'status' => CdaReport::STATUS_DRAFT,
            'sub_status' => null,
            'current_snapshot_id' => null,
            'submitted_for_review_by' => null,
            'submitted_for_review_at' => null,
            'approved_by' => null,
            'approved_at' => null,
            'finalized_by' => null,
            'finalized_at' => null,
        ])->save();
        $this->audit($report, 'reopen', $from, $reason, ['superseded_version' => $snapshot?->version]);
        $this->engine->revalidate($report);

        return ['report' => $report];
    }

    private function setStage(CdaReport $report, string $stage): array
    {
        $this->stages->advance($report, $stage);

        return ['report' => $report];
    }

    /**
     * The blocking Critical issues for the action, as a refusal; null when none.
     *
     * @param  list<array<string, mixed>>|null  $issues  live issues; the stored ones by default
     */
    private function blocking(CdaReport $report, string $stage, ?array $issues = null): ?array
    {
        $blocking = array_values(array_filter($issues ?? $report->issues ?? [], fn (array $issue) => ReportValidator::blocks($issue, $stage)));

        if ($blocking === []) {
            return null;
        }

        $count = count($blocking);

        return ['blocked' => "This report has {$count} critical ".($count === 1 ? 'issue' : 'issues').' to fix first.', 'issues' => $blocking];
    }

    /**
     * The missing documents in one phrase ("the signed CAPR (final copy) and
     * training certificates"). A category needed as a final copy is named
     * once, as the final copy.
     *
     * @param  list<array{name: string, category: string, final: bool}>  $missing
     */
    private static function documentList(array $missing): string
    {
        $finals = array_column(array_filter($missing, fn (array $document) => $document['final']), 'category');
        $names = array_values(array_unique(array_column(array_filter(
            $missing,
            fn (array $document) => $document['final'] || ! in_array($document['category'], $finals, true),
        ), 'name')));
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' and '.$last;
    }

    private function latestSubmission(CdaReport $report): ?CdaSubmission
    {
        return CdaSubmission::query()->where('report_id', $report->id)->where('snapshot_id', $report->current_snapshot_id)->latest('id')->first();
    }

    /**
     * One audit row per transition. A mediation, audit or tax report's
     * free-text reason stays out of it (its text can name parties or
     * figures); the reason still reaches the notice, and a reopen's reason
     * is kept on the superseded snapshot.
     *
     * @param  array<string, mixed>  $extra
     */
    private function audit(CdaReport $report, string $action, string $from, ?string $reason = null, array $extra = []): void
    {
        $reason = AuditValues::restricted($report) ? null : $reason;
        $new = ['status' => $report->status, 'action' => $action, ...$extra];

        if ($reason !== null) {
            $new['reason'] = $reason;
        }

        // The sentence ends with its own full stop, so the reason's own ending
        // is dropped from it ("…future." never reads "…future.."); the values
        // keep the reason as typed.
        $inSentence = $reason === null ? '' : Sentence::withoutEnding($reason);

        AuditLogService::log(
            'cda_workflow',
            $report,
            ['status' => $from],
            $new,
            sprintf('%s %d %s%s.', ReportValidator::reportName($report), $report->period->year, self::ACTION_WORDS[$action], $inSentence === '' ? '' : ": {$inSentence}"),
        );
    }

    /** @param  list<array<string, mixed>>  $issues */
    private static function refused(string $message, array $issues = []): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message, 'errors' => ['action' => [$message]], 'issues' => $issues], 422));
    }

    /** The snapshot a report shows: its current one. */
    public static function snapshotOf(CdaReport $report): ?CdaReportSnapshot
    {
        return $report->current_snapshot_id === null ? null : CdaReportSnapshot::query()->find($report->current_snapshot_id);
    }
}

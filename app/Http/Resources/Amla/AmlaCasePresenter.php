<?php

namespace App\Http\Resources\Amla;

use App\Models\AmlaAlert;
use App\Models\AmlaAlertAction;
use App\Models\AmlaCase;
use App\Models\AmlaCaseAction;
use App\Models\AmlaCaseEvidence;
use App\Models\AmlaCaseNote;
use App\Models\AmlaInboxItem;
use App\Models\AmlaReport;
use App\Models\AmlaReportSubmission;
use App\Models\AmlaRiskDowngrade;
use App\Models\Borrower;
use App\Models\GCashNonMember;
use App\Models\User;
use App\Services\Amla\Cases\FilingDeadlines;
use App\Services\Amla\Detection\Finding;
use Carbon\CarbonImmutable;

/**
 * AMLA cases, alert history, CTR/STR records, the inbox and High-risk
 * downgrades as AMLA screens read them (AMLA-B6). Everything in words, money
 * in ₱, "Data not available" where Lendy holds no value. A subject shows its
 * name and code only. Encrypted values are decrypted here for AMLA screens
 * only and never leave AMLA.
 */
final class AmlaCasePresenter
{
    /** @return array<string, mixed> */
    /** One workflow step; which case it went to shows only to head-office case holders. */
    public static function alertAction(AmlaAlertAction $action, ?User $viewer = null): array
    {
        return [
            'id' => (int) $action->id,
            'action' => $action->action,
            'action_words' => AmlaAlertAction::WORDS[$action->action] ?? ucfirst(str_replace('_', ' ', $action->action)),
            'from_status' => $action->from_status,
            'to_status' => $action->to_status,
            'to_status_words' => AmlaAlert::STATUS_WORDS[$action->to_status] ?? $action->to_status,
            'by' => AmlaPresenter::user($action->user),
            'assignee' => AmlaPresenter::user($action->assignee),
            'case_id' => AmlaDetectionPresenter::seesCases($viewer) ? $action->case_id : null,
            'note' => $action->note,
            'at' => $action->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function caseRow(AmlaCase $case): array
    {
        return [
            'id' => (int) $case->id,
            'reference' => $case->reference,
            'title' => $case->title,
            'status' => $case->status,
            'status_words' => AmlaCase::STATUS_WORDS[$case->status] ?? $case->status,
            'subject' => self::subject($case->borrower, $case->walkIn),
            'branch' => ['id' => $case->branch_id, 'name' => $case->branch?->name ?? ($case->branch_id === null ? AmlaDetectionPresenter::ORGANISATION_WIDE : Finding::NOT_AVAILABLE)],
            'assigned_to' => AmlaPresenter::user($case->assignee),
            'opened_by' => AmlaPresenter::user($case->opener),
            'decision' => $case->decision,
            'determination_date' => $case->determination_date?->toDateString(),
            'opened_at' => $case->created_at?->toIso8601String(),
            'closed_at' => $case->closed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function caseDetail(AmlaCase $case, AmlaDetectionPresenter $alerts, ?User $viewer = null): array
    {
        return [
            ...self::caseRow($case),
            'decision_words' => match ($case->decision) {
                'str' => 'Suspicion established',
                'no_str' => 'No STR',
                default => null,
            },
            'decision_reason' => $case->decision_reason,
            'decided_by' => AmlaPresenter::user($case->decider),
            'alerts' => $case->alerts()->with(['events', 'borrower', 'walkIn', 'branch', 'assignee', 'amlaCase'])->orderBy('amla_alerts.id')->get()
                ->map(fn (AmlaAlert $alert) => $alerts->alertRow($alert, $viewer))->values()->all(),
            'notes' => $case->notes()->with('author')->orderBy('id')->get()->map(fn (AmlaCaseNote $note) => [
                'id' => (int) $note->id, 'body' => $note->body, 'by' => AmlaPresenter::user($note->author), 'at' => $note->created_at?->toIso8601String(),
            ])->values()->all(),
            'evidence' => $case->evidence()->with('uploader')->orderBy('id')->get()->map(fn (AmlaCaseEvidence $file) => [
                'id' => (int) $file->id, 'name' => $file->original_name, 'description' => $file->description, 'size' => (int) $file->file_size,
                'by' => AmlaPresenter::user($file->uploader), 'at' => $file->created_at?->toIso8601String(),
            ])->values()->all(),
            'history' => $case->actions()->with('user')->orderBy('id')->get()->map(fn (AmlaCaseAction $action) => [
                'action' => $action->action, 'action_words' => AmlaCaseAction::WORDS[$action->action] ?? $action->action,
                'by' => AmlaPresenter::user($action->user), 'at' => $action->created_at?->toIso8601String(),
            ])->values()->all(),
            'reports' => AmlaReport::query()->where('case_id', $case->id)->orderBy('id')->get(['id', 'reference', 'status'])
                ->map(fn (AmlaReport $report) => ['id' => (int) $report->id, 'reference' => $report->reference, 'status_words' => AmlaReport::STATUS_WORDS[$report->status] ?? $report->status])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function reportRow(AmlaReport $report, FilingDeadlines $deadlines): array
    {
        $due = $deadlines->due($report);

        return [
            'id' => (int) $report->id,
            'reference' => $report->reference,
            'type' => $report->type,
            'type_words' => AmlaReport::TYPE_WORDS[$report->type],
            'status' => $report->status,
            'status_words' => AmlaReport::STATUS_WORDS[$report->status] ?? $report->status,
            'subject' => self::subject($report->borrower_id === null ? null : Borrower::query()->find($report->borrower_id), $report->gcash_non_member_id === null ? null : GCashNonMember::withTrashed()->find($report->gcash_non_member_id)),
            'deadline_from' => $report->deadline_from->toDateString(),
            'due_on' => $due['due_on'],
            'due_provisional' => $due['provisional'],
            'due_to_verify' => $due['to_verify'],
            'format' => ['code' => $report->format->code, 'name' => $report->format->name, 'file_available' => (bool) $report->format->file_available],
            'prepared_by' => AmlaPresenter::user($report->preparer),
            'approved_by' => AmlaPresenter::user($report->approver),
            'submission_reference' => $report->submission_reference,
            'submitted_on' => $report->submitted_on?->toDateString(),
            'prepared_at' => $report->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function reportDetail(AmlaReport $report, FilingDeadlines $deadlines): array
    {
        return [
            ...self::reportRow($report, $deadlines),
            'fields' => $report->fields,
            'narrative' => $report->narrative,
            'returned_reason' => $report->returned_reason,
            'ctr_candidate_id' => $report->ctr_candidate_id,
            'case_id' => $report->case_id,
            'submissions' => $report->submissions()->with('recorder')->orderBy('id')->get()->map(fn (AmlaReportSubmission $step) => [
                'id' => (int) $step->id, 'status' => $step->status, 'status_words' => AmlaReport::STATUS_WORDS[$step->status] ?? $step->status,
                'reference' => $step->reference, 'date' => $step->occurred_on->toDateString(), 'note' => $step->note,
                'evidence_name' => $step->evidence_name, 'by' => AmlaPresenter::user($step->recorder), 'at' => $step->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function inboxItem(AmlaInboxItem $item): array
    {
        return [
            'id' => (int) $item->id,
            'kind' => $item->kind,
            'subject_type' => $item->subject_type,
            'subject_id' => (int) $item->subject_id,
            'message' => $item->message,
            'read' => $item->read_at !== null,
            'at' => $item->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function downgrade(AmlaRiskDowngrade $downgrade): array
    {
        $profile = $downgrade->profile()->with(['borrower', 'walkIn'])->first();

        return [
            'id' => (int) $downgrade->id,
            'status' => $downgrade->status,
            'status_words' => AmlaRiskDowngrade::STATUS_WORDS[$downgrade->status] ?? $downgrade->status,
            'from_level' => $downgrade->from_level,
            'to_level' => $downgrade->to_level,
            'reason' => $downgrade->reason,
            'subject' => self::subject($profile?->borrower, $profile?->walkIn),
            'requested_by' => AmlaPresenter::user($downgrade->requester),
            'decision_note' => $downgrade->decision_note,
            'requested_at' => $downgrade->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function deadline(string $kind, int $id, string $reference, string $statusWords, array $due, string $today, FilingDeadlines $deadlines): array
    {
        return [
            'kind' => $kind,
            'id' => $id,
            'reference' => $reference,
            'status_words' => $statusWords,
            'due_on' => $due['due_on'],
            'due_words' => CarbonImmutable::parse($due['due_on'])->format('M j, Y'),
            'provisional' => $due['provisional'],
            'standing' => $deadlines->standing($due['due_on'], $today),
        ];
    }

    /** @return array<string, mixed> */
    private static function subject(?Borrower $member, ?GCashNonMember $walkIn): array
    {
        if ($member !== null) {
            return ['type' => 'member', 'id' => (int) $member->id, 'name' => (string) $member->full_name, 'code' => $member->borrower_code ?? Finding::NOT_AVAILABLE];
        }

        if ($walkIn !== null) {
            return ['type' => 'walk_in', 'id' => (int) $walkIn->id, 'name' => (string) $walkIn->full_name, 'code' => Finding::NOT_AVAILABLE];
        }

        return ['type' => null, 'id' => null, 'name' => Finding::NOT_AVAILABLE, 'code' => Finding::NOT_AVAILABLE];
    }
}

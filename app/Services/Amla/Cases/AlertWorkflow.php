<?php

namespace App\Services\Amla\Cases;

use App\Models\AmlaAlert;
use App\Models\AmlaAlertAction;
use App\Models\AmlaCase;
use App\Models\User;
use App\Services\Amla\Detection\DetectionScope;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\BranchAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The alert workflow (task §12; AMLA-B6): assign, start the review, escalate
 * to a case, or close with a documented reason. Each step locks the alert,
 * re-reads its status (409 when it moved on), writes AmlaAlertAction (AMLA's
 * own history) and the AMLA log, never the main audit trail. An assignee
 * hears of it in AMLA's inbox, by reference and priority only.
 */
final class AlertWorkflow
{
    public function __construct(
        private readonly DetectionScope $scope,
        private readonly AmlaLog $log,
        private readonly AmlaInbox $inbox,
        private readonly BranchAccess $branches,
        private readonly CaseRecords $cases,
    ) {}

    /** The alert, if the user may see it; else 404. */
    public function find(User $user, int $id): AmlaAlert
    {
        return $this->scope->constrainAlerts(AmlaAlert::query(), $user)->whereKey($id)->first() ?? abort(404);
    }

    public function assign(User $user, AmlaAlert $alert, int $assigneeId, ?string $note): AmlaAlert
    {
        $assignee = User::query()->whereKey($assigneeId)->where('status', '!=', 'inactive')->first();

        if ($assignee === null || ! $assignee->can(AmlaAccess::ALERTS) || ! $this->scope->constrainAlerts(AmlaAlert::query(), $assignee)->whereKey($alert->id)->exists()) {
            throw ValidationException::withMessages(['assignee_id' => 'Assign the alert to someone who holds the AMLA alerts permission and can see this alert.']);
        }

        return $this->step($user, $alert, 'assigned', function (AmlaAlert $locked) use ($assignee) {
            $locked->forceFill([
                'assigned_to' => $assignee->id,
                'status' => $locked->status === AmlaAlert::OPEN ? AmlaAlert::ASSIGNED : $locked->status,
            ])->save();

            $this->inbox->send([$assignee], 'alert_assigned', 'alert', $locked->id,
                "Alert {$locked->reference} (".(AmlaAlert::PRIORITY_WORDS[$locked->priority] ?? $locked->priority).' priority) is assigned to you.');

            return ['assignee_id' => $assignee->id];
        }, $note);
    }

    public function review(User $user, AmlaAlert $alert, ?string $note): AmlaAlert
    {
        return $this->step($user, $alert, 'review_started', function (AmlaAlert $locked) {
            abort_unless(in_array($locked->status, [AmlaAlert::OPEN, AmlaAlert::ASSIGNED], true), 409, 'This alert is already under review or no longer open.');
            $locked->forceFill(['status' => AmlaAlert::UNDER_REVIEW])->save();

            return [];
        }, $note);
    }

    public function close(User $user, AmlaAlert $alert, string $reason): AmlaAlert
    {
        return $this->step($user, $alert, 'closed', function (AmlaAlert $locked) {
            $locked->forceFill(['status' => AmlaAlert::CLOSED, 'open_key' => null, 'closed_at' => Carbon::now()])->save();

            return [];
        }, $reason);
    }

    /**
     * Escalate to an existing active case or a new one on the alert's
     * subject. The note stays on the alert's history; a new case also keeps
     * it as its first investigation note, by the same person, in the same
     * transaction, so the case shows why it was opened.
     */
    public function escalate(User $user, AmlaAlert $alert, ?int $caseId, ?string $title, ?string $note): AmlaCase
    {
        $case = null;

        $this->step($user, $alert, 'escalated', function (AmlaAlert $locked) use ($user, $caseId, $title, $note, &$case) {
            $case = $caseId !== null
                ? $this->cases->activeCaseForAlert($caseId, $locked)
                : $this->cases->open($user, ['title' => $title ?? "Alert {$locked->reference}", 'borrower_id' => $locked->borrower_id, 'gcash_non_member_id' => $locked->gcash_non_member_id]);

            if ($caseId === null && $note !== null && trim($note) !== '') {
                $this->cases->addNote($user, $case, $note);
            }

            $this->cases->attachAlert($user, $case, $locked);
            $locked->forceFill(['status' => AmlaAlert::ESCALATED, 'open_key' => null, 'case_id' => $case->id])->save();

            return ['case_id' => $case->id];
        }, $note);

        return $case->refresh();
    }

    /**
     * One locked step: the alert re-read under a row lock, 409 unless a person
     * can still act on it, then the change, the history row and the log row.
     *
     * @param  callable(AmlaAlert): array<string, mixed>  $change
     */
    private function step(User $user, AmlaAlert $alert, string $action, callable $change, ?string $note): AmlaAlert
    {
        return DB::transaction(function () use ($user, $alert, $action, $change, $note) {
            $locked = AmlaAlert::query()->lockForUpdate()->findOrFail($alert->id);

            abort_unless(in_array($locked->status, AmlaAlert::WORKABLE, true), 409, 'This alert is '.strtolower(AmlaAlert::STATUS_WORDS[$locked->status] ?? $locked->status).', so it can\'t change.');

            $from = $locked->status;
            $details = $change($locked);
            $locked->refresh();

            AmlaAlertAction::query()->create([
                'alert_id' => $locked->id,
                'action' => $action,
                'from_status' => $from,
                'to_status' => $locked->status,
                'user_id' => $user->id,
                'assignee_id' => $details['assignee_id'] ?? null,
                'case_id' => $details['case_id'] ?? null,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ]);

            $this->log->record('alert_'.$action, $user, 'alert', $locked->id, ['reference' => $locked->reference, 'from' => $from, 'to' => $locked->status, ...$details]);

            return $locked;
        });
    }
}

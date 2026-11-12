<?php

namespace App\Services\Cda\Reports;

use App\Http\Resources\Cda\CdaPresenter;
use App\Models\ApprovalWorkflowSetting;
use App\Models\CdaReport;
use App\Models\CdaReportApprovalStep;
use App\Models\LoanApprovalStep;
use App\Models\Role;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A CDA report's review chain (gap round B6).
 *
 * - The `cda` chain in Settings → Approval Workflow lists approve-only steps
 *   (name, role, optional status label). With none saved, a report has the
 *   single review it always had.
 * - At submit for review the chain is copied onto the report as a new round:
 *   the first step pending, the rest waiting. A report already in review
 *   when the chain was saved, or one submitted with no chain, has no open
 *   round and keeps the single review.
 * - A step is approved by a user who holds `cda:approve` (with
 *   `branches:view_all`) and the step's role through an active role, or
 *   admin or super_admin, and who neither submitted the report nor acted on
 *   an earlier step of the round. Only the last step approves the report.
 * - A return closes the round: the pending step reads returned, the waiting
 *   ones cancelled. A return from approved and a reopen close it too.
 */
final class CdaApprovalChain
{
    public const MAX_STEPS = 10;

    public function __construct(private readonly CdaAccess $access) {}

    /**
     * The saved `cda` chain, cleaned: a malformed one counts as none, so a
     * bad settings row never stops a submit.
     *
     * @return list<array{name: string, role: string, status: ?string}>
     */
    public function configured(): array
    {
        $steps = ApprovalWorkflowSetting::query()->where('type', ApprovalWorkflowSetting::TYPE_CDA)->value('steps');

        if (! is_array($steps)) {
            return [];
        }

        $clean = [];

        foreach (array_slice($steps, 0, self::MAX_STEPS) as $step) {
            if (! is_array($step) || ! is_string($step['name'] ?? null) || trim($step['name']) === '' || ! is_string($step['role'] ?? null) || $step['role'] === '') {
                return [];
            }

            $clean[] = [
                'name' => mb_substr(trim($step['name']), 0, 100),
                'role' => mb_substr($step['role'], 0, 100),
                'status' => is_string($step['status'] ?? null) && trim($step['status']) !== '' ? mb_substr(trim($step['status']), 0, 100) : null,
            ];
        }

        return $clean;
    }

    /**
     * Copies the chain onto the report as a new round; the first step, or
     * null when there is no chain (the single review).
     */
    public function seed(CdaReport $report): ?CdaReportApprovalStep
    {
        $steps = $this->configured();

        if ($steps === []) {
            return null;
        }

        $this->close($report);
        $round = (int) CdaReportApprovalStep::query()->where('report_id', $report->id)->max('round') + 1;
        $first = null;

        foreach ($steps as $index => $step) {
            $row = CdaReportApprovalStep::query()->create([
                'report_id' => $report->id,
                'round' => $round,
                'step_order' => $index + 1,
                'name' => $step['name'],
                'role' => $step['role'],
                'status_label' => $step['status'],
                'status' => $index === 0 ? CdaReportApprovalStep::STATUS_PENDING : CdaReportApprovalStep::STATUS_WAITING,
            ]);
            $first ??= $row;
        }

        return $first;
    }

    /**
     * The step waiting for an approval: the pending step of the report's open
     * round while it is for review; null on the single review.
     */
    public function current(CdaReport $report, bool $lock = false): ?CdaReportApprovalStep
    {
        if ($report->status !== CdaReport::STATUS_FOR_REVIEW) {
            return null;
        }

        return CdaReportApprovalStep::query()
            ->where('report_id', $report->id)
            ->where('status', CdaReportApprovalStep::STATUS_PENDING)
            ->whereNull('closed_at')
            ->orderByDesc('round')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /** Whether the user holds the step's role through an active role, or is admin or super_admin. */
    public function holdsRole(CdaReportApprovalStep $step, User $user): bool
    {
        $roles = $user->activeRoles();

        return $roles->contains(fn (Role $role) => in_array($role->name, LoanApprovalStep::BYPASS_ROLES, true))
            || $roles->contains('name', $step->role);
    }

    /** Whether the user may return the report at its current step: `cda:approve` and the step's role. */
    public function canReturnAt(CdaReportApprovalStep $step, User $user): bool
    {
        return $this->access->allows($user, CdaAccess::APPROVE) && $this->holdsRole($step, $user);
    }

    /** Why the user can't approve the step, or null when they can (the submitter is checked by the caller). */
    public function refusal(CdaReport $report, CdaReportApprovalStep $step, User $user): ?string
    {
        if (! $this->access->allows($user, CdaAccess::APPROVE)) {
            return 'Approving a CDA report needs the cda:approve permission.';
        }

        if (! $this->holdsRole($step, $user)) {
            return "The \"{$step->name}\" step is for the ".self::roleLabel($step->role).' role.';
        }

        if (in_array((int) $user->id, $this->earlierActors($step), true)) {
            return 'You approved an earlier step of this review, so someone else has to approve this one.';
        }

        return null;
    }

    /** Whether the user is offered the step's approval. */
    public function canApprove(CdaReport $report, CdaReportApprovalStep $step, User $user): bool
    {
        return (int) $report->submitted_for_review_by !== (int) $user->id && $this->refusal($report, $step, $user) === null;
    }

    /**
     * Marks the step approved; the next step (now pending), or null when it
     * was the last.
     */
    public function approve(CdaReportApprovalStep $step, User $user, ?string $remarks): ?CdaReportApprovalStep
    {
        $step->forceFill([
            'status' => CdaReportApprovalStep::STATUS_APPROVED,
            'acted_by' => $user->id,
            'acted_at' => now(),
            'remarks' => $remarks,
        ])->save();

        $next = CdaReportApprovalStep::query()
            ->where('report_id', $step->report_id)
            ->where('round', $step->round)
            ->where('step_order', '>', $step->step_order)
            ->orderBy('step_order')
            ->first();

        $next?->forceFill(['status' => CdaReportApprovalStep::STATUS_PENDING])->save();

        return $next;
    }

    /** A return: the pending step reads returned (who, when, the reason), then the round closes. */
    public function returned(CdaReport $report, User $user, string $reason): void
    {
        $this->current($report, true)?->forceFill([
            'status' => CdaReportApprovalStep::STATUS_RETURNED,
            'acted_by' => $user->id,
            'acted_at' => now(),
            'remarks' => $reason,
        ])->save();

        $this->close($report);
    }

    /** Closes the report's open round: steps nobody acted on read cancelled. */
    public function close(CdaReport $report): void
    {
        $open = CdaReportApprovalStep::query()->where('report_id', $report->id)->whereNull('closed_at');

        (clone $open)->whereIn('status', [CdaReportApprovalStep::STATUS_PENDING, CdaReportApprovalStep::STATUS_WAITING])
            ->update(['status' => CdaReportApprovalStep::STATUS_CANCELLED, 'updated_at' => now()]);
        $open->update(['closed_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The users who acted on the round's earlier steps.
     *
     * @return list<int>
     */
    public function earlierActors(CdaReportApprovalStep $step): array
    {
        return CdaReportApprovalStep::query()
            ->where('report_id', $step->report_id)
            ->where('round', $step->round)
            ->where('step_order', '<', $step->step_order)
            ->whereNotNull('acted_by')
            ->pluck('acted_by')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The report's latest round, for the report payload: each step's name,
     * role and its label, status label, status, who acted and when, and the
     * remarks. Empty on the single review.
     *
     * @return list<array<string, mixed>>
     */
    public function present(CdaReport $report): array
    {
        $round = CdaReportApprovalStep::query()->where('report_id', $report->id)->max('round');

        if ($round === null) {
            return [];
        }

        /** @var Collection<int, CdaReportApprovalStep> $steps */
        $steps = CdaReportApprovalStep::query()->with('actor')->where('report_id', $report->id)->where('round', $round)->orderBy('step_order')->get();

        return $steps->map(fn (CdaReportApprovalStep $step) => [
            'id' => (int) $step->id,
            'round' => (int) $step->round,
            'step_order' => (int) $step->step_order,
            'name' => $step->name,
            'role' => $step->role,
            'role_label' => self::roleLabel($step->role),
            'status_label' => $step->status_label,
            'status' => $step->status,
            'acted_by' => CdaPresenter::user($step->actor),
            'acted_by_name' => CdaPresenter::user($step->actor)['name'] ?? null,
            'acted_at' => $step->acted_at,
            'remarks' => $step->remarks,
            'closed' => $step->closed_at !== null,
        ])->all();
    }

    /** A role's display name, else its key in words. */
    public static function roleLabel(string $role): string
    {
        $display = Role::query()->where('name', $role)->value('display_name');

        return is_string($display) && trim($display) !== '' ? $display : Str::headline($role);
    }

    /** A 403 in words. */
    public static function forbidden(string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message], 403));
    }
}

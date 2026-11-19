<?php

namespace App\Services\Amla\Cases;

use App\Models\AmlaAlert;
use App\Models\AmlaCase;
use App\Models\AmlaCaseAction;
use App\Models\AmlaCaseAlert;
use App\Models\AmlaCaseEvidence;
use App\Models\AmlaCaseNote;
use App\Models\Borrower;
use App\Models\GCashNonMember;
use App\Models\User;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\BranchAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * AMLA cases (task §12–13; AMLA-B6), head office only: open one on a member
 * or walk-in, assign it, add notes (encrypted) and evidence (private disk),
 * attach escalated alerts, and record the authorized decision: no STR (the
 * case closes with its reason) or suspicion established on a determination
 * date, from which the STR is due. Each step writes the case's own history
 * and the AMLA log, which names ids only, never note text or file names.
 */
final class CaseRecords
{
    public function __construct(
        private readonly AmlaLog $log,
        private readonly AmlaInbox $inbox,
        private readonly References $references,
        private readonly EvidenceStore $files,
    ) {}

    /** @param  array{title: string, borrower_id?: int|null, gcash_non_member_id?: int|null}  $input */
    public function open(User $user, array $input): AmlaCase
    {
        return DB::transaction(function () use ($user, $input) {
            $branchId = isset($input['borrower_id']) ? Borrower::query()->whereKey($input['borrower_id'])->value('branch_id') : null;
            $case = AmlaCase::query()->create([
                'reference' => $this->references->next('cases', 'CASE', fn () => AmlaCase::query()->max('id')),
                'status' => AmlaCase::OPEN,
                'title' => trim($input['title']),
                'borrower_id' => $input['borrower_id'] ?? null,
                'gcash_non_member_id' => isset($input['borrower_id']) ? null : ($input['gcash_non_member_id'] ?? null),
                'branch_id' => $branchId === null ? null : (int) $branchId,
                'opened_by' => $user->id,
            ]);

            $this->history($case, 'opened', $user);
            $this->log->record('case_opened', $user, 'case', $case->id, ['reference' => $case->reference]);

            return $case;
        });
    }

    /** An active case that may take this alert (the same subject), or 422. */
    public function activeCaseForAlert(int $caseId, AmlaAlert $alert): AmlaCase
    {
        $case = AmlaCase::query()->lockForUpdate()->find($caseId);

        if ($case === null || ! in_array($case->status, AmlaCase::ACTIVE, true)
            || $case->borrower_id !== $alert->borrower_id || $case->gcash_non_member_id !== $alert->gcash_non_member_id) {
            throw ValidationException::withMessages(['case_id' => 'Pick an open case about the same member or walk-in as the alert.']);
        }

        return $case;
    }

    public function attachAlert(User $user, AmlaCase $case, AmlaAlert $alert): void
    {
        AmlaCaseAlert::query()->create(['case_id' => $case->id, 'alert_id' => $alert->id]);
        $this->history($case, 'alert_added', $user, ['alert_id' => $alert->id]);
    }

    public function assign(User $user, AmlaCase $case, int $assigneeId): AmlaCase
    {
        $assignee = User::query()->whereKey($assigneeId)->where('status', '!=', 'inactive')->first();

        if ($assignee === null || ! $assignee->can(AmlaAccess::CASES) || ! $assignee->can(BranchAccess::VIEW_ALL)) {
            throw ValidationException::withMessages(['assignee_id' => 'Assign the case to someone who holds the AMLA cases permission and head-office access.']);
        }

        return $this->step($user, $case, 'assigned', function (AmlaCase $locked) use ($assignee) {
            $locked->forceFill(['assigned_to' => $assignee->id, 'status' => AmlaCase::INVESTIGATING])->save();
            $this->inbox->send([$assignee], 'case_assigned', 'case', $locked->id, "Case {$locked->reference} is assigned to you.");

            return ['assignee_id' => $assignee->id];
        });
    }

    public function addNote(User $user, AmlaCase $case, string $body): AmlaCaseNote
    {
        return DB::transaction(function () use ($user, $case, $body) {
            $locked = $this->lockActive($case);
            $note = AmlaCaseNote::query()->create(['case_id' => $locked->id, 'body' => trim($body), 'user_id' => $user->id]);
            $this->history($locked, 'note_added', $user, ['note_id' => $note->id]);
            $this->log->record('case_note_added', $user, 'case', $locked->id, ['note_id' => $note->id, 'length' => mb_strlen($note->body)]);

            return $note;
        });
    }

    public function addEvidence(User $user, AmlaCase $case, UploadedFile $file, ?string $description): AmlaCaseEvidence
    {
        $stored = $this->files->store($file, "amla/cases/{$case->id}");

        try {
            return DB::transaction(function () use ($user, $case, $stored, $description) {
                $locked = $this->lockActive($case);
                $evidence = AmlaCaseEvidence::query()->create([
                    'case_id' => $locked->id,
                    'file_path' => $stored['path'],
                    'original_name' => $stored['name'],
                    'description' => $description !== null && trim($description) !== '' ? trim($description) : null,
                    'mime_type' => $stored['mime'],
                    'file_size' => $stored['size'],
                    'sha256' => $stored['sha256'],
                    'uploaded_by' => $user->id,
                ]);
                $this->history($locked, 'evidence_added', $user, ['evidence_id' => $evidence->id]);
                $this->log->record('case_evidence_added', $user, 'case', $locked->id, ['evidence_id' => $evidence->id, 'size' => $stored['size']]);

                return $evidence;
            });
        } catch (Throwable $failed) {
            $this->files->forget($stored['path']);

            throw $failed;
        }
    }

    /** @param  array{decision: string, reason: string, determination_date?: string|null}  $input */
    public function decide(User $user, AmlaCase $case, array $input): AmlaCase
    {
        if ($input['decision'] === 'no_str' && in_array($user->id, [$case->opened_by, $case->assigned_to], true)) {
            app(AmlaAccess::class)->refuse($user, 'own_case_no_str', 'You opened or are investigating this case, so someone else holding AMLA report approval must decide that no STR is needed.');
        }

        return $this->step($user, $case, 'decided', function (AmlaCase $locked) use ($user, $input) {
            abort_unless(in_array($locked->status, AmlaCase::ACTIVE, true), 409, 'This case already has its decision.');
            $str = $input['decision'] === 'str';
            $locked->forceFill([
                'status' => $str ? AmlaCase::STR_REQUIRED : AmlaCase::CLOSED_NO_REPORT,
                'decision' => $input['decision'],
                'decision_reason' => trim($input['reason']),
                'determination_date' => $str ? $input['determination_date'] : null,
                'decided_by' => $user->id,
                'decided_at' => Carbon::now(),
                'closed_at' => $str ? null : Carbon::now(),
            ])->save();

            return ['decision' => $input['decision']];
        });
    }

    /** Close a case once its STR is submitted. Runs in the caller's transaction. */
    public function strFiled(User $user, AmlaCase $case): void
    {
        $locked = AmlaCase::query()->lockForUpdate()->findOrFail($case->id);

        if ($locked->status === AmlaCase::STR_REQUIRED) {
            $locked->forceFill(['status' => AmlaCase::CLOSED_STR_FILED, 'closed_at' => Carbon::now()])->save();
            $this->history($locked, 'str_filed', $user);
        }
    }

    /** @param  array<string, mixed>  $details */
    public function history(AmlaCase $case, string $action, User $user, array $details = []): void
    {
        AmlaCaseAction::query()->create(['case_id' => $case->id, 'action' => $action, 'user_id' => $user->id, 'details' => $details === [] ? null : $details]);
    }

    public static function subjectExists(?int $borrowerId, ?int $walkInId): bool
    {
        return $borrowerId !== null
            ? Borrower::query()->members()->whereKey($borrowerId)->exists()
            : $walkInId !== null && GCashNonMember::query()->withTrashed()->whereKey($walkInId)->exists();
    }

    private function lockActive(AmlaCase $case): AmlaCase
    {
        $locked = AmlaCase::query()->lockForUpdate()->findOrFail($case->id);
        abort_unless(in_array($locked->status, AmlaCase::ACTIVE, true), 409, 'This case is closed or decided, so it takes no more notes or evidence.');

        return $locked;
    }

    /** @param  callable(AmlaCase): array<string, mixed>  $change */
    private function step(User $user, AmlaCase $case, string $action, callable $change): AmlaCase
    {
        return DB::transaction(function () use ($user, $case, $action, $change) {
            $locked = AmlaCase::query()->lockForUpdate()->findOrFail($case->id);
            abort_unless(in_array($locked->status, AmlaCase::ACTIVE, true), 409, 'This case is closed or decided, so it can\'t change.');
            $details = $change($locked);
            $this->history($locked, $action, $user, $details);
            $this->log->record('case_'.$action, $user, 'case', $locked->id, ['reference' => $locked->reference, 'status' => $locked->status, ...$details]);

            return $locked->refresh();
        });
    }
}

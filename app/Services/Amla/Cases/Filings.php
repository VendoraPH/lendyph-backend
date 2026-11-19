<?php

namespace App\Services\Amla\Cases;

use App\Enums\PaymentMethod;
use App\Models\AmlaCase;
use App\Models\AmlaCtrCandidate;
use App\Models\AmlaCtrSetAside;
use App\Models\AmlaEvent;
use App\Models\AmlaProfile;
use App\Models\AmlaReport;
use App\Models\AmlaReportFormat;
use App\Models\AmlaReportSubmission;
use App\Models\AmlaSettingsVersion;
use App\Models\Borrower;
use App\Models\GCashNonMember;
use App\Models\User;
use App\Services\Amla\Detection\Finding;
use App\Services\Amla\Events\PesoAmount;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\Amla\Foundation\AmlaSettings;
use App\Services\BranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * CTR and STR records (task §13–15; AMLA design §4.13, §4.16; AMLA-B6).
 *
 * - **Prepare.** A CTR from a detected candidate (`amla:ctr`), an STR from a
 *   case whose decision established suspicion (`amla:str`, head office).
 *   Either takes a snapshot of its fields, with "Data not available" where
 *   Lendy holds no value, and waits for approval. Nothing is filed by itself.
 * - **Approve.** `amla:approve`, never by its preparer: the check is on the
 *   user, so `Gate::before` doesn't let a super_admin approve their own. A
 *   return sends it back with a reason, and a CTR's candidate can be
 *   prepared again.
 * - **File.** The GoTRACS file adapter isn't built (format `internal_v1`), so
 *   a report is filed by hand on the AMLC portal and each step (submitted,
 *   accepted, rejected, correction required) is recorded with its
 *   reference, date and evidence (`amla:submit`). An export is an internal
 *   review copy and never changes the status.
 *
 * Every step writes the AMLA log, never the main audit trail; STRs and their
 * cases never leave AMLA.
 */
final class Filings
{
    public function __construct(
        private readonly AmlaLog $log,
        private readonly AmlaAccess $access,
        private readonly AmlaInbox $inbox,
        private readonly References $references,
        private readonly EvidenceStore $files,
        private readonly CaseRecords $cases,
        private readonly BranchAccess $branches,
    ) {}

    /** @var list<string> keys that read CTRs and STRs; an STR also needs head office */
    public const CTR_READ = [AmlaAccess::CTR, AmlaAccess::APPROVE, AmlaAccess::SUBMIT, AmlaAccess::EXPORT];

    public const STR_READ = [AmlaAccess::STR, AmlaAccess::APPROVE, AmlaAccess::SUBMIT, AmlaAccess::EXPORT];

    /**
     * The reports the user may read: CTRs in their branches (walk-ins and no
     * branch: head office) with a CTR reading key, STRs with an STR reading
     * key and head-office access.
     *
     * @return Builder<AmlaReport>
     */
    public function visibleQuery(User $user): Builder
    {
        $ids = $this->branches->for($user);
        $ctr = $user->canAny(self::CTR_READ);
        $str = $user->canAny(self::STR_READ) && $ids === null;

        return AmlaReport::query()->where(function (Builder $q) use ($ctr, $str, $ids) {
            $q->whereRaw('1 = 0');

            if ($ctr) {
                $q->orWhere(fn (Builder $c) => $c->where('type', AmlaReport::CTR)
                    ->when($ids !== null, fn (Builder $scoped) => $scoped->whereIn('branch_id', $ids)->whereNull('gcash_non_member_id')));
            }

            if ($str) {
                $q->orWhere('type', AmlaReport::STR);
            }
        });
    }

    /** Whether the user may see this report: STRs need head office; a CTR follows its branch (walk-ins: head office). */
    public function visible(User $user, AmlaReport $report): bool
    {
        if ($report->type === AmlaReport::STR || $report->gcash_non_member_id !== null) {
            return $user->can(BranchAccess::VIEW_ALL);
        }

        return $this->branches->canSee($user, $report->branch_id);
    }

    public function prepareCtr(User $user, AmlaCtrCandidate $candidate): AmlaReport
    {
        return DB::transaction(function () use ($user, $candidate) {
            $locked = AmlaCtrCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            abort_unless($locked->status === AmlaCtrCandidate::DETECTED, 409, 'This CTR candidate already has its CTR or was set aside as not reportable.');

            $report = $this->create($user, AmlaReport::CTR, [
                'ctr_candidate_id' => $locked->id,
                'borrower_id' => $locked->borrower_id,
                'gcash_non_member_id' => $locked->gcash_non_member_id,
                'branch_id' => $locked->branch_id,
                'fields' => $this->ctrFields($locked),
                'deadline_from' => $locked->occurred_on->toDateString(),
                'deadline_settings_version' => $locked->settings_version,
            ]);
            $locked->forceFill(['status' => AmlaCtrCandidate::REPORT_PREPARED, 'status_changed_by' => $user->id])->save();

            return $report;
        });
    }

    /**
     * Ask to set a candidate aside as not reportable, with a reason, in its
     * own AmlaCtrSetAside row, so an earlier ask is never overwritten. It
     * waits for a different `amla:approve` holder: setting a CTR aside is
     * final.
     */
    public function requestNotReportable(User $user, AmlaCtrCandidate $candidate, string $reason): AmlaCtrCandidate
    {
        return DB::transaction(function () use ($user, $candidate, $reason) {
            $locked = AmlaCtrCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            abort_unless($locked->status === AmlaCtrCandidate::DETECTED, 409, 'This CTR candidate already has its CTR or is being set aside.');
            $ask = AmlaCtrSetAside::query()->create([
                'ctr_candidate_id' => $locked->id,
                'reason' => trim($reason),
                'status' => AmlaCtrSetAside::PENDING,
                'pending_key' => $locked->id,
                'requested_by' => $user->id,
            ]);
            $locked->forceFill(['status' => AmlaCtrCandidate::SET_ASIDE_PENDING, 'status_changed_by' => $user->id])->save();
            $this->log->record('ctr_set_aside_requested', $user, 'ctr_candidate', $locked->id, ['reference' => $locked->reference, 'set_aside_id' => $ask->id]);
            $this->inbox->send($this->inbox->recipients([AmlaAccess::APPROVE], $locked->branch_id, $locked->gcash_non_member_id !== null, [$user->id]),
                'ctr_set_aside_needs_approval', 'ctr_candidate', $locked->id, "Setting CTR candidate {$locked->reference} aside is waiting for your approval.");

            return $locked;
        });
    }

    /** The candidate's waiting set-aside ask, if any. */
    public function pendingSetAside(AmlaCtrCandidate $candidate): ?AmlaCtrSetAside
    {
        return AmlaCtrSetAside::query()->where('pending_key', $candidate->id)->first();
    }

    /**
     * Approve (`$approve`) or refuse a set-aside ask, never by the person who
     * asked. A refusal keeps its reason (encrypted) on the ask's row.
     */
    public function decideNotReportable(User $user, AmlaCtrCandidate $candidate, bool $approve, ?string $note = null): AmlaCtrCandidate
    {
        $ask = $this->pendingSetAside($candidate);
        abort_if($ask === null, 409, 'This CTR candidate isn\'t waiting to be set aside.');

        if ($ask->requested_by === $user->id) {
            $this->access->refuse($user, 'requester_cannot_decide', 'You asked to set this CTR candidate aside, so someone else holding AMLA report approval must decide it.');
        }

        return DB::transaction(function () use ($user, $candidate, $approve, $note) {
            $locked = AmlaCtrCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            $ask = AmlaCtrSetAside::query()->where('pending_key', $locked->id)->lockForUpdate()->first();
            abort_unless($locked->status === AmlaCtrCandidate::SET_ASIDE_PENDING && $ask !== null, 409, 'This CTR candidate isn\'t waiting to be set aside.');

            $ask->forceFill([
                'status' => $approve ? AmlaCtrSetAside::APPROVED : AmlaCtrSetAside::REJECTED,
                'pending_key' => null,
                'decided_by' => $user->id,
                'decided_at' => Carbon::now(),
                'decision_note' => filled($note) ? trim((string) $note) : null,
            ])->save();

            $locked->forceFill($approve
                ? ['status' => AmlaCtrCandidate::NOT_REPORTABLE, 'not_reportable_reason' => $ask->reason, 'set_aside_requested_by' => $ask->requested_by, 'set_aside_approved_by' => $user->id, 'status_changed_by' => $user->id]
                : ['status' => AmlaCtrCandidate::DETECTED, 'status_changed_by' => $user->id])->save();
            $this->log->record($approve ? 'ctr_set_aside_approved' : 'ctr_set_aside_rejected', $user, 'ctr_candidate', $locked->id, ['reference' => $locked->reference, 'set_aside_id' => $ask->id]);

            return $locked;
        });
    }

    public function prepareStr(User $user, AmlaCase $case, string $narrative): AmlaReport
    {
        return DB::transaction(function () use ($user, $case, $narrative) {
            $locked = AmlaCase::query()->lockForUpdate()->findOrFail($case->id);
            abort_unless($locked->status === AmlaCase::STR_REQUIRED, 409, 'An STR is prepared only once the case\'s decision establishes suspicion.');
            abort_if(AmlaReport::query()->where('case_id', $locked->id)->where('status', '!=', AmlaReport::RETURNED)->exists(), 409, 'This case already has its STR.');

            $settings = app(AmlaSettings::class)->at(Carbon::parse($locked->determination_date->toDateString().' 23:59:59', config('app.timezone')));
            $report = $this->create($user, AmlaReport::STR, [
                'case_id' => $locked->id,
                'borrower_id' => $locked->borrower_id,
                'gcash_non_member_id' => $locked->gcash_non_member_id,
                'branch_id' => $locked->branch_id,
                'fields' => $this->strFields($locked),
                'narrative' => trim($narrative),
                'deadline_from' => $locked->determination_date->toDateString(),
                'deadline_settings_version' => $settings->version,
            ]);
            $this->cases->history($locked, 'str_prepared', $user, ['report_id' => $report->id]);

            return $report;
        });
    }

    public function approve(User $user, AmlaReport $report): AmlaReport
    {
        if ($report->prepared_by === $user->id) {
            $this->access->refuse($user, 'preparer_cannot_approve', 'You prepared this report, so someone else holding AMLA report approval must approve it.');
        }

        return $this->step($user, $report, 'report_approved', AmlaReport::PENDING_APPROVAL, function (AmlaReport $locked) use ($user) {
            $locked->forceFill(['status' => AmlaReport::APPROVED, 'approved_by' => $user->id, 'approved_at' => Carbon::now()])->save();
        });
    }

    public function return(User $user, AmlaReport $report, string $reason): AmlaReport
    {
        if ($report->prepared_by === $user->id) {
            $this->access->refuse($user, 'preparer_cannot_return', 'You prepared this report, so someone else holding AMLA report approval must return it.');
        }

        return $this->step($user, $report, 'report_returned', AmlaReport::PENDING_APPROVAL, function (AmlaReport $locked) use ($user, $reason) {
            $locked->forceFill(['status' => AmlaReport::RETURNED, 'returned_reason' => trim($reason)])->save();

            if ($locked->ctr_candidate_id !== null) {
                $candidate = AmlaCtrCandidate::query()->lockForUpdate()->findOrFail($locked->ctr_candidate_id);
                $candidate->forceFill(['status' => AmlaCtrCandidate::DETECTED, 'status_changed_by' => $user->id])->save();
            }

            $this->inbox->send([$locked->preparer], 'report_returned', 'report', $locked->id, "Report {$locked->reference} was returned to you.");
        });
    }

    /**
     * Record one manual filing step: submitted (from approved, or after a
     * rejection or correction), then accepted, rejected or correction required.
     *
     * @param  array{status: string, reference: string, date: string, note?: string|null}  $input
     */
    public function recordSubmission(User $user, AmlaReport $report, array $input, UploadedFile $evidence): AmlaReportSubmission
    {
        $stored = $this->files->store($evidence, "amla/reports/{$report->id}");

        try {
            return DB::transaction(function () use ($user, $report, $input, $stored) {
                $locked = AmlaReport::query()->lockForUpdate()->findOrFail($report->id);
                $to = $input['status'];
                abort_unless(in_array($to, AmlaReport::NEXT[$locked->status] ?? [], true) && $to !== AmlaReport::APPROVED && $to !== AmlaReport::RETURNED, 409,
                    'A report that is '.strtolower(AmlaReport::STATUS_WORDS[$locked->status] ?? $locked->status).' can\'t be recorded as '.strtolower(AmlaReport::STATUS_WORDS[$to] ?? $to).'.');

                $submission = AmlaReportSubmission::query()->create([
                    'report_id' => $locked->id,
                    'status' => $to,
                    'reference' => trim($input['reference']),
                    'occurred_on' => $input['date'],
                    'note' => filled($input['note'] ?? null) ? trim((string) $input['note']) : null,
                    'evidence_path' => $stored['path'],
                    'evidence_name' => $stored['name'],
                    'evidence_mime' => $stored['mime'],
                    'evidence_size' => $stored['size'],
                    'evidence_sha256' => $stored['sha256'],
                    'recorded_by' => $user->id,
                ]);

                $values = ['status' => $to];

                if ($to === AmlaReport::SUBMITTED) {
                    $values += ['submission_reference' => $submission->reference, 'submitted_on' => $input['date']];
                }

                $locked->forceFill($values)->save();

                if ($to === AmlaReport::SUBMITTED && $locked->case_id !== null) {
                    $this->cases->strFiled($user, AmlaCase::query()->findOrFail($locked->case_id));
                }

                if (in_array($to, [AmlaReport::REJECTED, AmlaReport::CORRECTION_REQUIRED], true)) {
                    $this->inbox->send([$locked->preparer], 'report_'.$to, 'report', $locked->id,
                        "Report {$locked->reference}: ".strtolower(AmlaReport::STATUS_WORDS[$to]).'.');
                }

                $this->log->record('report_submission_recorded', $user, 'report', $locked->id, ['reference' => $locked->reference, 'status' => $to, 'submission_id' => $submission->id]);

                return $submission;
            });
        } catch (Throwable $failed) {
            $this->files->forget($stored['path']);

            throw $failed;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function create(User $user, string $type, array $values): AmlaReport
    {
        $report = AmlaReport::query()->create([
            'reference' => $this->references->next('reports', 'RPT-'.strtoupper($type), fn () => AmlaReport::query()->max('id')),
            'type' => $type,
            'status' => AmlaReport::PENDING_APPROVAL,
            'format_id' => AmlaReportFormat::query()->where('code', AmlaReportFormat::INTERNAL_V1)->value('id'),
            'prepared_by' => $user->id,
            ...$values,
        ]);

        $this->log->record('report_prepared', $user, 'report', $report->id, ['reference' => $report->reference, 'type' => $type]);

        $headOffice = $type === AmlaReport::STR || $report->gcash_non_member_id !== null;
        $this->inbox->send($this->inbox->recipients([AmlaAccess::APPROVE], $report->branch_id, $headOffice, [$user->id]), 'report_needs_approval', 'report', $report->id,
            "Report {$report->reference} is waiting for your approval.");

        return $report;
    }

    /** @param  callable(AmlaReport): void  $change */
    private function step(User $user, AmlaReport $report, string $action, string $from, callable $change): AmlaReport
    {
        return DB::transaction(function () use ($user, $report, $action, $from, $change) {
            $locked = AmlaReport::query()->lockForUpdate()->findOrFail($report->id);
            abort_unless($locked->status === $from, 409, 'This report is '.strtolower(AmlaReport::STATUS_WORDS[$locked->status] ?? $locked->status).'.');
            $change($locked);
            $this->log->record($action, $user, 'report', $locked->id, ['reference' => $locked->reference]);

            return $locked->refresh();
        });
    }

    /** @return list<array{label: string, value: string}> */
    private function ctrFields(AmlaCtrCandidate $candidate): array
    {
        $event = AmlaEvent::query()->with('branch')->findOrFail($candidate->event_id);
        $settings = AmlaSettingsVersion::query()->where('version', $candidate->settings_version)->first();

        return [
            self::field('Report type', AmlaReport::TYPE_WORDS[AmlaReport::CTR]),
            self::field('CTR candidate', $candidate->reference),
            self::field('Transaction reference', $event->reference),
            self::field('Transaction', AmlaEvent::kindWords($event->kind)),
            self::field('Transaction date', $event->occurred_on->format('M j, Y')),
            self::field('Amount', PesoAmount::words((string) $candidate->amount)),
            self::field('Direction', $candidate->direction === 'in' ? 'Money in' : 'Money out'),
            self::field('Payment channel', PaymentMethod::labelFor($candidate->channel)),
            self::field('Branch', $event->branch?->name),
            ...$this->subjectFields($candidate->borrower_id, $candidate->gcash_non_member_id),
            self::field('Account', null),
            self::field('CTR threshold', PesoAmount::words((string) $candidate->threshold).($settings === null ? '' : " (AMLA settings version {$settings->version})")),
        ];
    }

    /** @return list<array{label: string, value: string}> */
    private function strFields(AmlaCase $case): array
    {
        $events = AmlaEvent::query()->whereIn('id', DB::table('amla_alert_events')
            ->join('amla_case_alerts', 'amla_case_alerts.alert_id', '=', 'amla_alert_events.alert_id')
            ->where('amla_case_alerts.case_id', $case->id)->select('amla_alert_events.event_id'))->whereNull('reversed_at')->get();
        $total = $events->sum(fn (AmlaEvent $event) => PesoAmount::centavos((string) $event->amount));

        return [
            self::field('Report type', AmlaReport::TYPE_WORDS[AmlaReport::STR]),
            self::field('Case reference', $case->reference),
            self::field('Determination date', $case->determination_date?->format('M j, Y')),
            ...$this->subjectFields($case->borrower_id, $case->gcash_non_member_id),
            self::field('Related transactions', $events->isEmpty() ? null : $events->count().' totalling '.PesoAmount::words(PesoAmount::fromCentavos($total))),
            self::field('Reason for suspicion', $case->decision_reason),
            self::field('Reason code (GoTRACS)', null),
            self::field('Beneficial ownership', null),
        ];
    }

    /** @return list<array{label: string, value: string}> */
    private function subjectFields(?int $borrowerId, ?int $walkInId): array
    {
        $member = $borrowerId === null ? null : Borrower::query()->find($borrowerId);
        $walkIn = $walkInId === null ? null : GCashNonMember::query()->withTrashed()->find($walkInId);
        $profile = AmlaProfile::query()->where($member !== null ? 'borrower_id' : 'gcash_non_member_id', $member?->id ?? $walkIn?->id)->first();

        if ($member !== null) {
            $id = $member->documents()->where('type', 'valid_id')->whereNotNull('id_number')->latest('id')->first();

            return [
                self::field('Subject', 'Member'),
                self::field('Name', (string) $member->full_name),
                self::field('Member code', $member->borrower_code),
                self::field('Date of birth', $member->birthdate?->format('M j, Y')),
                self::field('Address', collect([$member->street_address ?? null, $member->city ?? null, $member->province ?? null])->filter()->implode(', ')),
                self::field('ID type and number', $id === null ? null : ($id->label ?? 'Valid ID').' ending '.mb_substr((string) $id->id_number, -4)),
                self::field('Nationality', $profile?->nationality),
                self::field('Occupation', $profile?->occupation),
                self::field('Source of funds', $profile?->source_of_funds),
            ];
        }

        return [
            self::field('Subject', $walkIn === null ? null : 'Walk-in'),
            self::field('Name', $walkIn?->full_name),
            self::field('Member code', null),
            self::field('Date of birth', null),
            self::field('Address', null),
            self::field('ID type and number', $walkIn === null || blank($walkIn->id_type) || blank($walkIn->id_number) ? null : $walkIn->id_type.' ending '.mb_substr((string) $walkIn->id_number, -4)),
            self::field('Nationality', $profile?->nationality),
            self::field('Occupation', $profile?->occupation),
            self::field('Source of funds', $profile?->source_of_funds),
        ];
    }

    /** @return array{label: string, value: string} */
    private static function field(string $label, ?string $value): array
    {
        return ['label' => $label, 'value' => $value === null || trim($value) === '' ? Finding::NOT_AVAILABLE : $value];
    }
}

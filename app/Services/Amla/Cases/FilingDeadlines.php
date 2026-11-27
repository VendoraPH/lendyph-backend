<?php

namespace App\Services\Amla\Cases;

use App\Models\AmlaCase;
use App\Models\AmlaCtrCandidate;
use App\Models\AmlaReport;
use App\Models\AmlaSettingsVersion;
use App\Models\User;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaSettings;
use App\Services\Amla\Foundation\Deadlines;
use App\Services\Amla\Foundation\WorkingDayCalendar;
use App\Services\BranchAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The AMLA filing deadline engine (task §15; AMLA design §4.15), on B1's
 * working-day calendar: a CTR is due the settings' working days after its
 * transaction, an STR the working days after suspicion was established,
 * both "to verify". A deadline is provisional while any year it spans has no
 * confirmed calendar, and is worked out on every read, so confirming a year
 * or adding a holiday moves it at once.
 *
 * The nightly run tells compliance staff, in AMLA's own inbox and once per
 * filing and kind (an unfiled report, a candidate with no CTR yet, or a case
 * with no STR yet), of filings due within the next UPCOMING_WORKING_DAYS
 * working days, due today, or overdue. CTRs reach `amla:ctr`, `amla:approve`
 * and `amla:submit` holders who see the branch; STRs reach `amla:str`,
 * `amla:approve` and `amla:submit` holders with head-office access.
 */
final class FilingDeadlines
{
    public const UPCOMING_WORKING_DAYS = 2;

    public function __construct(
        private readonly Deadlines $deadlines,
        private readonly WorkingDayCalendar $calendar,
        private readonly AmlaInbox $inbox,
        private readonly AmlaSettings $settings,
        private readonly BranchAccess $branches,
    ) {}

    /**
     * @return array{kind: string, kind_words: string, from: string, due_on: string, working_days: int, provisional: bool, provisional_years: list<int>, settings_version: int, to_verify: bool}
     */
    public function due(AmlaReport $report): array
    {
        $version = AmlaSettingsVersion::query()->where('version', $report->deadline_settings_version)->first();

        return $this->deadlines->due($report->type, $report->deadline_from->toDateString(), $version);
    }

    /** overdue, due_today, upcoming or null, on `$today` (Manila). */
    public function standing(string $dueOn, string $today): ?string
    {
        return match (true) {
            $dueOn < $today => 'overdue',
            $dueOn === $today => 'due_today',
            $dueOn <= $this->calendar->addWorkingDays($today, self::UPCOMING_WORKING_DAYS) => 'upcoming',
            default => null,
        };
    }

    /** A detected CTR candidate's due date, under the settings version it was measured against. */
    public function candidateDue(AmlaCtrCandidate $candidate): array
    {
        return $this->deadlines->due('ctr', $candidate->occurred_on->toDateString(), AmlaSettingsVersion::query()->where('version', $candidate->settings_version)->first());
    }

    /** An STR-required case's due date, under the settings in force on its determination date (as prepareStr dates the STR). */
    public function caseDue(AmlaCase $case): array
    {
        $day = $case->determination_date->toDateString();

        return $this->deadlines->due('str', $day, $this->settings->at(Carbon::parse($day.' 23:59:59', config('app.timezone'))));
    }

    /** Reports still waiting to be filed. @return Collection<int, AmlaReport> */
    public function unfiled(): Collection
    {
        return AmlaReport::query()->whereIn('status', AmlaReport::UNFILED)->orderBy('id')->get();
    }

    /**
     * CTR candidates with no CTR prepared: detected, or waiting for a
     * set-aside decision (still due until the set-aside is approved).
     *
     * @return Collection<int, AmlaCtrCandidate>
     */
    public function candidatesWithoutReport(): Collection
    {
        return AmlaCtrCandidate::query()->whereIn('status', [AmlaCtrCandidate::DETECTED, AmlaCtrCandidate::SET_ASIDE_PENDING])->orderBy('id')->get();
    }

    /** Cases whose decision established suspicion and whose STR isn't prepared. @return Collection<int, AmlaCase> */
    public function casesWithoutStr(): Collection
    {
        return AmlaCase::query()->where('status', AmlaCase::STR_REQUIRED)
            ->whereNotExists(fn ($reports) => $reports->selectRaw('1')->from('amla_reports')->whereColumn('amla_reports.case_id', 'amla_cases.id')->where('amla_reports.status', '!=', AmlaReport::RETURNED))
            ->orderBy('id')->get();
    }

    /**
     * Every filing still to make: unfiled reports, candidates with no CTR and
     * cases with no STR, each with its due date and who it is for.
     *
     * @return list<array{subject_type: string, id: int, reference: string, type: string, status_words: string, due: array<string, mixed>, branch_id: int|null, head_office: bool}>
     */
    public function filings(): array
    {
        $rows = [];

        foreach ($this->unfiled() as $report) {
            $rows[] = ['subject_type' => 'report', 'id' => $report->id, 'reference' => $report->reference, 'type' => $report->type,
                'status_words' => AmlaReport::STATUS_WORDS[$report->status], 'due' => $this->due($report), 'branch_id' => $report->branch_id,
                'head_office' => $report->type === AmlaReport::STR || $report->gcash_non_member_id !== null];
        }

        foreach ($this->candidatesWithoutReport() as $candidate) {
            $rows[] = ['subject_type' => 'ctr_candidate', 'id' => $candidate->id, 'reference' => $candidate->reference, 'type' => AmlaReport::CTR,
                'status_words' => AmlaCtrCandidate::STATUS_WORDS[$candidate->status], 'due' => $this->candidateDue($candidate), 'branch_id' => $candidate->branch_id,
                'head_office' => $candidate->gcash_non_member_id !== null];
        }

        foreach ($this->casesWithoutStr() as $case) {
            $rows[] = ['subject_type' => 'case', 'id' => $case->id, 'reference' => $case->reference, 'type' => AmlaReport::STR,
                'status_words' => AmlaCase::STATUS_WORDS[$case->status], 'due' => $this->caseDue($case), 'branch_id' => $case->branch_id, 'head_office' => true];
        }

        return $rows;
    }

    /**
     * The filings `$user` may see: a CTR with a CTR reading key, an STR with
     * an STR reading key, each in the user's branches (STRs and walk-ins:
     * head office). The deadlines list, the dashboard counts and the
     * management summary all count through this one rule.
     *
     * @return list<array{subject_type: string, id: int, reference: string, type: string, status_words: string, due: array<string, mixed>, branch_id: int|null, head_office: bool}>
     */
    public function visibleTo(User $user): array
    {
        $headOffice = $user->can(BranchAccess::VIEW_ALL);

        return array_values(array_filter($this->filings(), fn (array $filing) => $user->canAny($filing['type'] === AmlaReport::STR ? Filings::STR_READ : Filings::CTR_READ)
            && ($filing['head_office'] ? $headOffice : $this->branches->canSee($user, $filing['branch_id']))));
    }

    /** @return int inbox items added */
    public function remind(?string $today = null): int
    {
        $today ??= CarbonImmutable::now(WorkingDayCalendar::TIMEZONE)->toDateString();
        $added = 0;

        foreach ($this->filings() as $filing) {
            $standing = $this->standing($filing['due']['due_on'], $today);

            if ($standing === null) {
                continue;
            }

            $words = ['overdue' => 'is overdue', 'due_today' => 'is due today', 'upcoming' => 'is due soon'][$standing];
            $type = strtoupper($filing['type']);
            $what = ['report' => "{$type} filing", 'ctr_candidate' => 'The CTR for candidate', 'case' => 'The STR for case'][$filing['subject_type']];
            $str = $filing['type'] === AmlaReport::STR;

            $added += $this->inbox->send(
                $this->inbox->recipients([$str ? AmlaAccess::STR : AmlaAccess::CTR, AmlaAccess::APPROVE, AmlaAccess::SUBMIT], $filing['branch_id'], $filing['head_office']),
                'filing_'.$standing,
                $filing['subject_type'],
                $filing['id'],
                "{$what} {$filing['reference']} {$words} (due ".CarbonImmutable::parse($filing['due']['due_on'])->format('M j, Y').($filing['due']['provisional'] ? ', provisional' : '').').',
                "filing_{$standing}|{$filing['subject_type']}:{$filing['id']}|{$filing['due']['due_on']}",
            );
        }

        return $added;
    }
}

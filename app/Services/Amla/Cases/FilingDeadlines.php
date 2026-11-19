<?php

namespace App\Services\Amla\Cases;

use App\Models\AmlaReport;
use App\Models\AmlaSettingsVersion;
use App\Models\User;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\Deadlines;
use App\Services\Amla\Foundation\WorkingDayCalendar;
use Carbon\CarbonImmutable;
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
 * report and kind, of filings due within the next UPCOMING_WORKING_DAYS
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

    /** Reports still waiting to be filed. @return Collection<int, AmlaReport> */
    public function unfiled(): Collection
    {
        return AmlaReport::query()->whereIn('status', AmlaReport::UNFILED)->orderBy('id')->get();
    }

    /** @return int inbox items added */
    public function remind(?string $today = null): int
    {
        $today ??= CarbonImmutable::now(WorkingDayCalendar::TIMEZONE)->toDateString();
        $added = 0;

        foreach ($this->unfiled() as $report) {
            $due = $this->due($report);
            $standing = $this->standing($due['due_on'], $today);

            if ($standing === null) {
                continue;
            }

            $words = ['overdue' => 'is overdue', 'due_today' => 'is due today', 'upcoming' => 'is due soon'][$standing];
            $type = strtoupper($report->type);
            $added += $this->inbox->send(
                $this->recipients($report),
                'filing_'.$standing,
                'report',
                $report->id,
                "{$type} filing {$report->reference} {$words} (due ".CarbonImmutable::parse($due['due_on'])->format('M j, Y').($due['provisional'] ? ', provisional' : '').').',
                "filing_{$standing}|report:{$report->id}|{$due['due_on']}",
            );
        }

        return $added;
    }

    /** @return Collection<int, User> */
    private function recipients(AmlaReport $report): Collection
    {
        $str = $report->type === AmlaReport::STR;

        return $this->inbox->recipients(
            [$str ? AmlaAccess::STR : AmlaAccess::CTR, AmlaAccess::APPROVE, AmlaAccess::SUBMIT],
            $report->branch_id,
            $str || $report->gcash_non_member_id !== null,
        );
    }
}

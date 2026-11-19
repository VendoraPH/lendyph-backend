<?php

namespace App\Services\Amla\Foundation;

use App\Models\AmlaSettingsVersion;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * AMLA filing deadlines (AMLA design §4.15), in working days counted from the
 * day after the start date (WorkingDayCalendar): a CTR within the settings'
 * `ctr_deadline_working_days` after the transaction, an STR within
 * `str_deadline_working_days` after the suspicion is established. Both are
 * "to verify" settings. A deadline is provisional when any year from its
 * start to its due date has no confirmed calendar. The case and filing
 * slice (AMLA-B6) tracks deadlines on its records with this.
 */
final class Deadlines
{
    /** @var array<string, array{setting: string, words: string}> */
    public const KINDS = [
        'ctr' => ['setting' => 'ctr_deadline_working_days', 'words' => 'Covered transaction report (CTR)'],
        'str' => ['setting' => 'str_deadline_working_days', 'words' => 'Suspicious transaction report (STR)'],
    ];

    public function __construct(
        private readonly WorkingDayCalendar $calendar,
        private readonly AmlaSettings $settings,
    ) {}

    /**
     * A report's due date from its start date, under the given settings
     * version (the current one when none is given).
     *
     * @return array{kind: string, kind_words: string, from: string, due_on: string, working_days: int, provisional: bool, provisional_years: list<int>, settings_version: int, to_verify: bool}
     */
    public function due(string $kind, string $from, ?AmlaSettingsVersion $version = null): array
    {
        if (! array_key_exists($kind, self::KINDS)) {
            throw new InvalidArgumentException("\"{$kind}\" isn't an AMLA report with a deadline.");
        }

        $version ??= $this->settings->current();
        $setting = self::KINDS[$kind]['setting'];
        $workingDays = (int) $version->values[$setting];
        $start = CarbonImmutable::parse($from, WorkingDayCalendar::TIMEZONE)->toDateString();
        $dueOn = $this->calendar->addWorkingDays($start, $workingDays);
        $unconfirmed = $this->calendar->unconfirmedYears((int) substr($start, 0, 4), (int) substr($dueOn, 0, 4));

        return [
            'kind' => $kind,
            'kind_words' => self::KINDS[$kind]['words'],
            'from' => $start,
            'due_on' => $dueOn,
            'working_days' => $workingDays,
            'provisional' => $unconfirmed !== [],
            'provisional_years' => $unconfirmed,
            'settings_version' => $version->version,
            'to_verify' => $version->isToVerify($setting),
        ];
    }
}

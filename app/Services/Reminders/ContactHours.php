<?php

namespace App\Services\Reminders;

use App\Models\ReminderSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The window automated reminders may go out in, Manila time: the stored
 * contact hours held within 06:00 to 22:00, the earliest and latest any
 * setting may reach. A window that is empty once held falls back to the
 * default 08:00 to 18:00. The end is exclusive: nothing goes at 18:00.
 */
final readonly class ContactHours
{
    public const FLOOR = 360;

    public const CEILING = 1320;

    private const DEFAULT_START = 480;

    private const DEFAULT_END = 1080;

    private function __construct(public int $startMinute, public int $endMinute) {}

    public static function from(ReminderSetting $settings): self
    {
        $start = max(self::FLOOR, self::minutes($settings->contact_hours_start) ?? self::DEFAULT_START);
        $end = min(self::CEILING, self::minutes($settings->contact_hours_end) ?? self::DEFAULT_END);

        return $start < $end ? new self($start, $end) : new self(self::DEFAULT_START, self::DEFAULT_END);
    }

    public function contains(CarbonInterface $at): bool
    {
        $local = ReminderClock::manila($at);
        $minute = $local->hour * 60 + $local->minute;

        return $minute >= $this->startMinute && $minute < $this->endMinute;
    }

    public function isBeforeOpening(CarbonInterface $at): bool
    {
        $local = ReminderClock::manila($at);

        return $local->hour * 60 + $local->minute < $this->startMinute;
    }

    public function openingOn(CarbonInterface $day): Carbon
    {
        return ReminderClock::manila($day)->startOfDay()->addMinutes($this->startMinute);
    }

    /**
     * The moment a reminder with this send time goes out on `$day`: the send
     * time when it falls inside the window, else the window's opening.
     */
    public function scheduleOn(CarbonInterface $day, ?string $sendTime): Carbon
    {
        $minute = self::minutes($sendTime);

        if ($minute === null || $minute < $this->startMinute || $minute >= $this->endMinute) {
            $minute = $this->startMinute;
        }

        return ReminderClock::manila($day)->startOfDay()->addMinutes($minute);
    }

    private static function minutes(?string $time): ?int
    {
        if ($time === null || preg_match('/^(\d{1,2}):(\d{2})/', $time, $match) !== 1) {
            return null;
        }

        $hour = (int) $match[1];
        $minute = (int) $match[2];

        return $hour > 23 || $minute > 59 ? null : $hour * 60 + $minute;
    }
}

<?php

namespace App\Services\Cda;

use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Models\CdaSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * CDA deadlines (CDA design §10).
 *
 * - Annual reports are due `annual_deadline_days` (120) after Dec 31:
 *   CY2026 → 2027-04-30, and Apr 29 after a leap February (CY2027 →
 *   2028-04-29). Staff use an extension for holidays.
 * - Mediation H1 and H2 are due `mediation_deadline_days` (30) after the
 *   half's end: Jun 30 + 30 = Jul 30, Dec 31 + 30 = Jan 30.
 * - A report's effective deadline is its override, else (annual reports
 *   only) the period's extension, else its default.
 * - `days_remaining` counts Manila calendar days from today (negative once
 *   overdue).
 */
final class Deadlines
{
    public const TIMEZONE = 'Asia/Manila';

    /** The annual reports' default deadline for a year. */
    public function annual(int $year, CdaSetting $settings): string
    {
        return CarbonImmutable::create($year, 12, 31, 0, 0, 0, self::TIMEZONE)->addDays($settings->annual_deadline_days)->toDateString();
    }

    /**
     * An instance's default deadline, by its type's basis: a year end, or a
     * half's end for mediation. A template's `deadline` block, when it names
     * a basis and a days setting, decides instead.
     *
     * @param  array<string, mixed>|null  $definition
     */
    public function forInstance(int $year, string $type, string $part, CdaSetting $settings, ?array $definition = null): string
    {
        $config = ReportTypes::get($type);
        $basis = $definition['deadline']['basis'] ?? $config['basis'];
        $daysSetting = $definition['deadline']['days_setting'] ?? $config['days_setting'];
        $days = in_array($daysSetting, TemplateDefinitionValidator::DAYS_SETTINGS, true)
            ? (int) $settings->{$daysSetting}
            : (int) $settings->{$config['days_setting']};

        $end = $basis === 'half_end' && $part === ReportTypes::H1
            ? CarbonImmutable::create($year, 6, 30, 0, 0, 0, self::TIMEZONE)
            : CarbonImmutable::create($year, 12, 31, 0, 0, 0, self::TIMEZONE);

        return $end->addDays($days)->toDateString();
    }

    /** The report's override, else the period extension (annual only), else its default. */
    public static function effective(CdaReport $report, CdaReportingPeriod $period): string
    {
        if ($report->due_override_on !== null) {
            return $report->due_override_on->toDateString();
        }

        if ($report->part === ReportTypes::ANNUAL && $period->extended_deadline !== null) {
            return $period->extended_deadline->toDateString();
        }

        return $report->default_due_on->toDateString();
    }

    /** Manila calendar days from today to the date; negative once it has passed. */
    public static function daysRemaining(string $date, ?CarbonInterface $now = null): int
    {
        $today = CarbonImmutable::instance($now ?? Carbon::now())->setTimezone(self::TIMEZONE)->startOfDay();
        $due = CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay();

        return (int) $today->diffInDays($due, false);
    }
}

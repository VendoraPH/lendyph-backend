<?php

namespace App\Services\Cda\Reminders;

use App\Models\CdaComplianceRequirement;
use App\Models\CdaNoticeSent;
use App\Models\CdaReport;
use App\Models\CdaSetting;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffNotification;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Deadlines;
use App\Services\Cda\ReportTypes;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * CDA deadline reminders (CDA design §10), run nightly by
 * `cda:send-reminders` at 00:50 Manila.
 *
 * - Milestones: each of the settings' `reminder_days` (30, 15, 7 and 3 by
 *   default) before the effective deadline, the due day, and overdue (once).
 * - The milestone sent is the latest one crossed on the day, so a missed run
 *   sends only that one, never the ones before it.
 * - Each is recorded in `cda_notices_sent`, keyed on the report, the
 *   milestone and the effective deadline: it goes out once, and an extension
 *   or override (a new deadline) starts the milestones again.
 * - Filed reports (submitted or accepted) and reports that don't apply are
 *   skipped. A report sent back for correction still gets them.
 * - Recipients: active users holding `cda:prepare` or `cda:approve` (through
 *   an active role or directly) with `branches:view_all`, and the report's
 *   view permission when it is restricted. In-app only (StaffNotification,
 *   database channel); a failure to send is logged and the run goes on.
 * - Custom compliance requirements (gap round B6) still pending get the same
 *   milestones on their effective due date (the extension, else the
 *   default), recorded as `cda_requirement` subjects, to the same people.
 * - Missing requirement (gap round B6): an open report whose stored
 *   readiness is below 100% with required fields still missing tells
 *   `cda:prepare` holders (with view_all and the restricted view) how many,
 *   at most once in 7 days per report (`missing_requirement`).
 */
final class DeadlineReminders
{
    public const TYPE = 'cda_deadline_reminder';

    public const KIND = 'deadline_reminder';

    public const SUBJECT_TYPE = 'cda_report';

    public const REQUIREMENT_SUBJECT_TYPE = 'cda_requirement';

    public const MISSING_TYPE = 'cda_missing_requirement';

    public const MISSING_KIND = 'missing_requirement';

    /** A missing-requirement notice goes out at most once in this many days per report. */
    public const MISSING_EVERY_DAYS = 7;

    /** Statuses already filed with CDA. */
    public const FILED = [CdaReport::STATUS_SUBMITTED, CdaReport::STATUS_ACCEPTED];

    /** The screen of each report type, for the notice's link. */
    private const PATHS = [
        'capr' => '/cda/capr',
        'financial_statements' => '/cda/financial-statements',
        'performance_audit' => '/cda/performance-audit',
        'social_audit' => '/cda/social-audit',
        'pesos' => '/cda/pesos',
        'officers_trainings' => '/cda/officers',
        'mediation' => '/cda/mediation',
        'sworn_statement' => '/cda/sworn-statement',
        'atir' => '/cda/tax-reports',
        'abr' => '/cda/tax-reports',
    ];

    /** @var array<string, Collection<int, User>> recipients per restriction */
    private array $recipients = [];

    public function __construct(private readonly CdaAccess $access) {}

    /** @return array{sent: int, already_sent: int, reports: int, requirements: int, missing_requirement: int} */
    public function run(?CarbonInterface $now = null): array
    {
        $now ??= CarbonImmutable::now(Deadlines::TIMEZONE);
        $this->recipients = [];
        $days = collect(CdaSetting::current()->reminder_days ?? [30, 15, 7, 3])->map(fn ($day) => (int) $day)
            ->filter(fn (int $day) => $day > 0)->unique()->sort()->values()->all();
        $sent = 0;
        $already = 0;

        $reports = CdaReport::query()->with('period')
            ->where('applicable', true)
            ->whereNotIn('status', self::FILED)
            ->orderBy('id')
            ->get();

        foreach ($reports as $report) {
            $deadline = Deadlines::effective($report, $report->period);
            $remaining = Deadlines::daysRemaining($deadline, $now);
            $milestone = self::milestone($remaining, $days);

            if ($milestone === null) {
                continue;
            }

            $recorded = CdaNoticeSent::query()->insertOrIgnore([
                'subject_type' => self::SUBJECT_TYPE,
                'subject_id' => $report->id,
                'kind' => self::KIND,
                'dedupe_key' => $milestone.'|'.$deadline,
                'sent_at' => CarbonImmutable::now(),
            ]);

            if ($recorded === 0) {
                $already++;

                continue;
            }

            $this->send($report, $milestone, $deadline, $remaining);
            $sent++;
        }

        $requirements = CdaComplianceRequirement::query()->where('status', CdaComplianceRequirement::STATUS_PENDING)->orderBy('id')->get();

        foreach ($requirements as $requirement) {
            $deadline = $requirement->effectiveDueOn();
            $remaining = Deadlines::daysRemaining($deadline, $now);
            $milestone = self::milestone($remaining, $days);

            if ($milestone === null) {
                continue;
            }

            $recorded = CdaNoticeSent::query()->insertOrIgnore([
                'subject_type' => self::REQUIREMENT_SUBJECT_TYPE,
                'subject_id' => $requirement->id,
                'kind' => self::KIND,
                'dedupe_key' => $milestone.'|'.$deadline,
                'sent_at' => CarbonImmutable::now(),
            ]);

            if ($recorded === 0) {
                $already++;

                continue;
            }

            $this->sendRequirement($requirement, $milestone, $deadline, $remaining);
            $sent++;
        }

        $missing = 0;

        foreach ($reports as $report) {
            $missing += $this->missingRequirement($report, $now) ? 1 : 0;
        }

        return ['sent' => $sent, 'already_sent' => $already, 'reports' => $reports->count(), 'requirements' => $requirements->count(), 'missing_requirement' => $missing];
    }

    /**
     * The number of required fields the report's stored readiness counts as
     * missing (its failed `required:` checks).
     */
    public static function missingRequired(CdaReport $report): int
    {
        $failed = $report->readiness_breakdown['failed'] ?? [];

        return is_array($failed) ? count(array_filter($failed, fn ($id) => is_string($id) && str_starts_with($id, 'required:'))) : 0;
    }

    /** Sends the report's missing-requirement notice when one is due; whether it was sent. */
    private function missingRequirement(CdaReport $report, CarbonInterface $now): bool
    {
        $missing = self::missingRequired($report);

        if ($missing === 0 || (int) $report->readiness >= 100) {
            return false;
        }

        $today = CarbonImmutable::parse($now)->setTimezone(Deadlines::TIMEZONE)->startOfDay();
        $recent = CdaNoticeSent::query()
            ->where('subject_type', self::SUBJECT_TYPE)->where('subject_id', $report->id)->where('kind', self::MISSING_KIND)
            ->where('dedupe_key', '>', $today->subDays(self::MISSING_EVERY_DAYS)->toDateString())
            ->exists();

        if ($recent) {
            return false;
        }

        $recorded = CdaNoticeSent::query()->insertOrIgnore([
            'subject_type' => self::SUBJECT_TYPE,
            'subject_id' => $report->id,
            'kind' => self::MISSING_KIND,
            'dedupe_key' => $today->toDateString(),
            'sent_at' => CarbonImmutable::now(),
        ]);

        if ($recorded === 0) {
            return false;
        }

        try {
            $recipients = $this->recipientsFor($report->report_type)->filter(fn (User $user) => $this->access->allows($user, CdaAccess::PREPARE))->values();

            if ($recipients->isEmpty()) {
                return true;
            }

            $year = (int) $report->period->year;
            $readiness = (int) $report->readiness;

            Notification::send($recipients, new StaffNotification(
                self::MISSING_TYPE,
                'CDA report incomplete',
                sprintf(
                    '%s %d is %d%% complete. %d required %s still missing.',
                    self::reportName($report), $year, $readiness, $missing, $missing === 1 ? 'field is' : 'fields are',
                ),
                (self::PATHS[$report->report_type] ?? '/cda').'?year='.$year.($report->part === ReportTypes::ANNUAL ? '' : '&part='.$report->part),
                ['report_id' => $report->id, 'report_type' => $report->report_type, 'part' => $report->part, 'year' => $year, 'readiness' => $readiness, 'missing_required' => $missing],
            ));
        } catch (Throwable $e) {
            Log::error('A CDA missing-requirement notice could not be sent; it is recorded as sent and the run went on.', [
                'cda_report_id' => $report->id,
                'exception' => $e,
            ]);
        }

        return true;
    }

    /** "Cooperative Annual Progress Report (CAPR)", or with its half ("Mediation …, First half (January–June)"). */
    private static function reportName(CdaReport $report): string
    {
        return ReportTypes::title($report->report_type)
            .($report->part === ReportTypes::ANNUAL ? '' : ', '.(ReportTypes::PART_TITLES[$report->part] ?? $report->part).',');
    }

    private function sendRequirement(CdaComplianceRequirement $requirement, string $milestone, string $deadline, int $remaining): void
    {
        try {
            $recipients = $this->recipientsFor('');

            if ($recipients->isEmpty()) {
                return;
            }

            $name = "\"{$requirement->title}\"";
            $year = (int) $requirement->year;
            $due = CarbonImmutable::parse($deadline)->format('M j, Y');

            [$title, $body] = match (true) {
                $milestone === 'overdue' => ['Compliance requirement overdue', "{$name} for CY{$year} was due on {$due}, ".abs($remaining).' '.(abs($remaining) === 1 ? 'day' : 'days').' ago, and is not filed yet.'],
                $milestone === 'due' => ['Compliance requirement due today', "{$name} for CY{$year} is due on {$due}, today."],
                default => ['Compliance requirement due in '.$remaining.' '.($remaining === 1 ? 'day' : 'days'), "{$name} for CY{$year} is due on {$due}."],
            };

            Notification::send($recipients, new StaffNotification(
                self::TYPE,
                $title,
                $body,
                '/cda/calendar?year='.$year,
                ['requirement_id' => $requirement->id, 'year' => $year, 'milestone' => $milestone, 'deadline' => $deadline],
            ));
        } catch (Throwable $e) {
            Log::error('A CDA requirement reminder could not be sent; it is recorded as sent and the run went on.', [
                'cda_requirement_id' => $requirement->id,
                'milestone' => $milestone,
                'exception' => $e,
            ]);
        }
    }

    /**
     * The latest milestone crossed with this many days left: overdue, due,
     * or the smallest reminder day not below it; null before the first.
     *
     * @param  list<int>  $days  ascending
     */
    public static function milestone(int $remaining, array $days): ?string
    {
        if ($remaining < 0) {
            return 'overdue';
        }

        if ($remaining === 0) {
            return 'due';
        }

        foreach ($days as $day) {
            if ($remaining <= $day) {
                return 'days_'.$day;
            }
        }

        return null;
    }

    private function send(CdaReport $report, string $milestone, string $deadline, int $remaining): void
    {
        try {
            $recipients = $this->recipientsFor($report->report_type);

            if ($recipients->isEmpty()) {
                return;
            }

            $year = (int) $report->period->year;
            $name = self::reportName($report);
            $due = CarbonImmutable::parse($deadline)->format('M j, Y');

            [$title, $body] = match (true) {
                $milestone === 'overdue' => ['CDA report overdue', "{$name} for CY{$year} was due on {$due}, ".abs($remaining).' '.(abs($remaining) === 1 ? 'day' : 'days').' ago, and is not filed yet.'],
                $milestone === 'due' => ['CDA report due today', "{$name} for CY{$year} is due on {$due}, today."],
                default => ['CDA report due in '.$remaining.' '.($remaining === 1 ? 'day' : 'days'), "{$name} for CY{$year} is due on {$due}."],
            };

            Notification::send($recipients, new StaffNotification(
                self::TYPE,
                $title,
                $body,
                (self::PATHS[$report->report_type] ?? '/cda').'?year='.$year.($report->part === ReportTypes::ANNUAL ? '' : '&part='.$report->part),
                [
                    'report_id' => $report->id,
                    'report_type' => $report->report_type,
                    'part' => $report->part,
                    'year' => $year,
                    'milestone' => $milestone,
                    'deadline' => $deadline,
                ],
            ));
        } catch (Throwable $e) {
            Log::error('A CDA deadline reminder could not be sent; it is recorded as sent and the run went on.', [
                'cda_report_id' => $report->id,
                'milestone' => $milestone,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Active users holding cda:prepare or cda:approve through an active role
     * (admin and super_admin always count) or directly, then those who also
     * hold branches:view_all and may see the report type ('' for a custom
     * requirement, which belongs to no report type).
     *
     * @return Collection<int, User>
     */
    private function recipientsFor(string $reportType): Collection
    {
        $restriction = $reportType === '' ? '' : (ReportTypes::restriction($reportType) ?? '');

        return $this->recipients[$restriction] ??= $this->holders()
            ->filter(fn (User $user) => ($this->access->allows($user, CdaAccess::PREPARE) || $this->access->allows($user, CdaAccess::APPROVE))
                && ($reportType === '' || $this->access->canSeeType($user, $reportType)))
            ->values();
    }

    /** @return Collection<int, User> */
    private function holders(): Collection
    {
        $grants = fn (Builder $permissions) => $permissions->whereIn('name', [CdaAccess::PREPARE, CdaAccess::APPROVE]);

        return User::query()->active()
            ->with(['roles', 'permissions'])
            ->where(fn (Builder $users) => $users
                ->whereHas('roles', fn (Builder $roles) => $roles
                    ->where(fn (Builder $active) => $active->where('roles.is_active', true)->orWhereIn('roles.name', Role::PROTECTED_KEYS))
                    ->whereHas('permissions', $grants))
                ->orWhereHas('permissions', $grants))
            ->orderBy('id')
            ->get()
            ->toBase();
    }
}

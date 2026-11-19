<?php

namespace App\Services\Linda\Answers;

use App\Models\CdaReportingPeriod;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Reports\ReportDashboard;
use App\Services\Cda\ReportTypes;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaScope;
use App\Services\Linda\LindaWording as W;

/**
 * "When is the next CDA deadline?", "Are we ready for CDA?": the CDA
 * dashboard's figures (ReportDashboard::dashboard(), GET
 * /cda/periods/{year}/dashboard) for the newest reporting year, the one the
 * dashboard opens on: Lendy CDA Readiness, applicable and filed reports, open
 * critical issues and warnings, and the next deadline still to come.
 *
 * It needs `cda:view` and `branches:view_all` (CdaAccess), as the dashboard
 * does, and the dashboard leaves out every report the caller can't open
 * (mediation, audit, tax without their view): no row, count, readiness or
 * deadline. CDA reports cover the whole cooperative, so a named branch or
 * period doesn't narrow them; the reply says so.
 */
final class CdaSummaryAnswer extends Answer
{
    public function __construct(
        private readonly ReportDashboard $dashboard,
        private readonly CdaAccess $access,
    ) {}

    public function intent(): LindaIntent
    {
        return LindaIntent::CdaSummary;
    }

    public function allows(User $user): bool
    {
        return $this->access->allows($user, CdaAccess::VIEW);
    }

    protected function subject(): string
    {
        return 'CDA reports';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        $reportingYear = CdaReportingPeriod::query()->orderByDesc('year')->first();

        if ($reportingYear === null) {
            return new LindaReply($this->intent()->value, W::cdaNotSetUp(), 'not_yet', answerFunction: $this->name());
        }

        $year = (int) $reportingYear->year;
        $data = $this->dashboard->dashboard($reportingYear, $user);
        $counts = $data['counts'];
        $next = $data['next_deadline'];

        $answer = 'For the '.$year.' CDA reporting year, '.$data['readiness_label'].' is '.W::percent($data['readiness'])
            .' across '.W::count((int) $counts['applicable'], 'applicable report').', and '.$counts['filed']
            .((int) $counts['filed'] === 1 ? ' is' : ' are').' filed. '
            .self::issues((int) $counts['critical'], (int) $counts['warnings']).' '
            .self::nextDeadline($next, $year);

        if ($scope->branchId !== null) {
            $answer .= ' CDA reports cover the whole cooperative, so these figures are for all branches.';
        }

        if ($period !== null) {
            $answer .= ' These are the newest reporting year\'s figures; open the CDA Dashboard to see another year.';
        }

        return $this->reply($answer, [
            LindaReply::stats([
                LindaReply::percent($data['readiness_label'], (int) $data['readiness']),
                LindaReply::number('Applicable Reports', (int) $counts['applicable']),
                LindaReply::number('Filed', (int) $counts['filed']),
                LindaReply::number('Critical Issues', (int) $counts['critical']),
                LindaReply::number('Warnings', (int) $counts['warnings']),
                ...($next === null ? [] : [
                    LindaReply::date('Next Deadline', (string) $next['effective_deadline']),
                    LindaReply::number('Days Remaining', (int) $next['days_remaining']),
                ]),
            ], 'CDA reporting year '.$year),
        ], LindaLinks::forYear($user, $year, 'cda_dashboard', 'cda_calendar'));
    }

    private static function issues(int $critical, int $warnings): string
    {
        if ($critical === 0 && $warnings === 0) {
            return 'No issues are open.';
        }

        return W::count($critical, 'critical issue').' and '.W::count($warnings, 'warning').' are open.';
    }

    /** @param  array<string, mixed>|null  $next */
    private static function nextDeadline(?array $next, int $year): string
    {
        if ($next === null) {
            return 'No deadline for '.$year.' is still to come.';
        }

        $part = $next['part'] === ReportTypes::ANNUAL ? '' : ' ('.lcfirst(str_replace([' (', ')'], [', ', ''], (string) $next['title_part'])).')';
        $days = (int) $next['days_remaining'];
        $date = W::date((string) $next['effective_deadline']);

        return 'The next deadline is '.$next['title'].$part.', due '
            .($days === 0 ? 'today, '.$date.'.' : 'on '.$date.', in '.W::count($days, 'day').'.');
    }
}

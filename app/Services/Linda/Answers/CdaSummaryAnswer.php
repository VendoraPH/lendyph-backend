<?php

namespace App\Services\Linda\Answers;

use App\Models\CdaOfficer;
use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Pesos\PesosAssessment;
use App\Services\Cda\Pesos\PesosEngine;
use App\Services\Cda\Registers\RegisterWords;
use App\Services\Cda\Registers\TrainingCompliance;
use App\Services\Cda\Reports\ReportDashboard;
use App\Services\Cda\ReportTypes;
use App\Services\Cda\Sources\SourceContext;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaScope;
use App\Services\Linda\LindaText;
use App\Services\Linda\LindaWording as W;

/**
 * CDA questions, from the CDA screens' own code for the newest reporting
 * year, the one the dashboard opens on:
 *
 * - "When is the next CDA deadline?" (and any CDA question with no focus
 *   below): the dashboard's figures (ReportDashboard::dashboard(), GET
 *   /cda/periods/{year}/dashboard): Lendy CDA Readiness, applicable and filed
 *   reports, open critical issues and warnings, and the next deadline.
 * - "Are we ready for CAPR?": each named report's readiness, status, open
 *   issues (listed) and deadline, from the dashboard's rows and issues.
 * - "Are we ready to submit our CDA reports?": readiness, the complete reports
 *   and what each incomplete one still needs, and the officers missing
 *   trainings.
 * - "What CDA reports are incomplete?": the applicable reports not filed and
 *   below 100% (or returned by CDA for correction), by name.
 * - "Why is our CDA readiness only 82%?": readiness is the mean of the
 *   applicable reports; the reports below 100% with their open critical
 *   issues.
 * - "Which documents are missing?": the readiness issues about compliance
 *   documents (`compliance_document`), by report.
 * - "Which officer trainings are missing?": the officers serving today whose
 *   position's required trainings aren't all complete, by the officers
 *   register's own check (TrainingCompliance, as of today, as GET
 *   /cda/officers reads it).
 * - "What is our Enhanced PESOS rating?": the PESOS page's assessment
 *   (PesosAssessment, GET /cda/periods/{year}/pesos), labelled provisional
 *   and "to verify" while its template isn't verified.
 *
 * The focus is read from the question's words on the server; the model only
 * classifies the question as CDA. Every answer needs `cda:view` and
 * `branches:view_all` (CdaAccess), as the CDA screens do, and the dashboard
 * leaves out every report the caller can't open (mediation, audit, tax
 * without their view): no row, count, readiness, issue or deadline. A
 * question naming such a report is refused in words. CDA reports cover the
 * whole cooperative, so a named branch or period doesn't narrow them; the
 * reply says so.
 */
final class CdaSummaryAnswer extends Answer
{
    /** @var array<string, string> each report type's names in a cleaned question */
    private const REPORT_NAMES = [
        'capr' => '/(?<![\pL\pN])(?:capr|annual\s+progress\s+report)(?![\pL\pN])/u',
        'financial_statements' => '/(?<![\pL\pN])(?:financial\s+statements?|afs)(?![\pL\pN])/u',
        'performance_audit' => '/(?<![\pL\pN])performance\s+audit(?![\pL\pN])/u',
        'social_audit' => '/(?<![\pL\pN])social\s+audit(?![\pL\pN])/u',
        'pesos' => '/(?<![\pL\pN])pesos(?![\pL\pN])/u',
        'officers_trainings' => '/(?<![\pL\pN])(?:officers?\s+and\s+trainings?|list\s+of\s+officers)(?![\pL\pN])/u',
        'mediation' => '/(?<![\pL\pN])(?:mediation|conciliation)(?![\pL\pN])/u',
        'sworn_statement' => '/(?<![\pL\pN])sworn\s+statements?(?![\pL\pN])/u',
        'atir' => '/(?<![\pL\pN])(?:atir|tax\s+incentives?)(?![\pL\pN])/u',
        'abr' => '/(?<![\pL\pN])(?:abr|annual\s+benefits?)(?![\pL\pN])/u',
    ];

    private const RATING = '/(?<![\pL\pN])(?:rating|rated|score|grade|grado|marka)(?![\pL\pN])/u';

    private const TRAINING = '/(?<![\pL\pN])(?:trainings?|pagsasanay|pagbansay)(?![\pL\pN])/u';

    private const DOCUMENT = '/(?<![\pL\pN])(?:documents?|dokumentos?|uploads?|uploaded)(?![\pL\pN])/u';

    private const WHY = '/(?<![\pL\pN])(?:why|bakit|ngano|nganong)(?![\pL\pN])/u';

    private const INCOMPLETE = '/(?<![\pL\pN])(?:incomplete|unfinished|missing|pending|lacking|kulang\pL*|(?:not|hindi|dili)(?:\s+(?:yet|pa))?\s+(?:complete|kumpleto|kompleto|done|finished))(?![\pL\pN])/u';

    private const READY = '/(?<![\pL\pN])(?:ready|readiness|handa|andam|submit\pL*|file|filed|filing|ipasa|isumite|complete|kumpleto|kompleto)(?![\pL\pN])/u';

    /** The dashboard's status words (lendyph-frontend cda/_lib/labels.ts STATUS_META). */
    private const STATUS_WORDS = [
        'not_applicable' => 'Not applicable', 'not_started' => 'Not started', 'draft' => 'Draft',
        'validation_failed' => 'Has critical issues', 'returned' => 'Returned for changes', 'for_review' => 'For review',
        'approved' => 'Approved', 'finalized' => 'Finalized', 'submitted' => 'Filed with CDA', 'accepted' => 'Accepted by CDA',
        'for_correction' => 'For correction',
    ];

    /** The issue category of a missing document (TemplateDefinitionValidator::CATEGORIES). */
    private const DOCUMENT_CATEGORY = 'compliance_document';

    /** Filed with CDA: no longer counts towards a deadline. */
    private const FILED = [CdaReport::STATUS_SUBMITTED, CdaReport::STATUS_ACCEPTED];

    /** The critical issues a "why" row quotes. */
    private const QUOTED_ISSUES = 3;

    public function __construct(
        private readonly ReportDashboard $dashboard,
        private readonly CdaAccess $access,
        private readonly TrainingCompliance $compliance,
        private readonly PesosAssessment $pesos,
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

        [$focus, $types] = self::focus($question === null ? '' : LindaText::clean($question));

        $result = match ($focus) {
            'pesos' => $this->pesosRating($user, $reportingYear),
            'reports' => $this->namedReports($user, $reportingYear, $types),
            'trainings' => $this->trainings($user, $reportingYear),
            'documents' => $this->documents($user, $reportingYear),
            'why' => $this->why($user, $reportingYear),
            'incomplete' => $this->incomplete($user, $reportingYear),
            'ready' => $this->readiness($user, $reportingYear),
            default => $this->summary($user, $reportingYear),
        };

        if ($result instanceof LindaReply) {
            return $result;
        }

        [$answer, $blocks, $links, $memberIds] = $result;

        if ($scope->branchId !== null) {
            $answer .= ' CDA reports cover the whole cooperative, so these figures are for all branches.';
        }

        if ($period !== null) {
            $answer .= $focus === 'trainings'
                ? ' '.W::todayOnly()
                : ' These are the newest reporting year\'s figures; open the CDA Dashboard to see another year.';
        }

        return $this->reply($answer, $blocks, $links, $memberIds);
    }

    /**
     * What a question asks, by its words: the PESOS rating, named reports,
     * trainings, documents, why, the incomplete reports, readiness, or else
     * the summary; with the report types it names.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function focus(string $text): array
    {
        if ($text === '') {
            return ['summary', []];
        }

        $asks = fn (string $pattern): bool => preg_match($pattern, $text) === 1;
        $named = array_keys(array_filter(self::REPORT_NAMES, fn (string $pattern) => preg_match($pattern, $text) === 1));

        if (in_array('pesos', $named, true)
            && ($asks(self::RATING) || ($named === ['pesos'] && ! $asks(self::READY) && ! $asks(self::INCOMPLETE) && ! $asks(self::DOCUMENT) && ! $asks(self::WHY)))) {
            return ['pesos', []];
        }

        return match (true) {
            $named !== [] => ['reports', $named],
            $asks(self::TRAINING) => ['trainings', []],
            $asks(self::DOCUMENT) => ['documents', []],
            $asks(self::WHY) => ['why', []],
            $asks(self::INCOMPLETE) => ['incomplete', []],
            $asks(self::READY) => ['ready', []],
            default => ['summary', []],
        };
    }

    /**
     * The dashboard's figures: readiness, counts and the next deadline.
     *
     * @return array{0: string, 1: list<array<string, mixed>>, 2: list<array{label: string, url: string}>, 3: list<int>}
     */
    private function summary(User $user, CdaReportingPeriod $reportingYear): array
    {
        $year = (int) $reportingYear->year;
        $data = $this->dashboard->dashboard($reportingYear, $user);
        $counts = $data['counts'];
        $next = $data['next_deadline'];

        $answer = 'For the '.$year.' CDA reporting year, '.$data['readiness_label'].' is '.W::percent($data['readiness'])
            .' across '.W::count((int) $counts['applicable'], 'applicable report').', and '.$counts['filed']
            .((int) $counts['filed'] === 1 ? ' is' : ' are').' filed. '
            .self::issues((int) $counts['critical'], (int) $counts['warnings']).' '
            .self::nextDeadline($next, $year);

        return [$answer, [
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
        ], LindaLinks::forYear($user, $year, 'cda_dashboard', 'cda_calendar'), []];
    }

    /**
     * Readiness across the reports: the complete ones, what each incomplete
     * one still needs, and the officers missing trainings.
     *
     * @return array{0: string, 1: list<array<string, mixed>>, 2: list<array{label: string, url: string}>, 3: list<int>}
     */
    private function readiness(User $user, CdaReportingPeriod $reportingYear): array
    {
        $year = (int) $reportingYear->year;
        $data = $this->dashboard->dashboard($reportingYear, $user);
        $applicable = self::applicable($data);
        $incomplete = array_values(array_filter($applicable, fn (array $row) => ! self::isComplete($row)));
        $complete = array_values(array_filter($applicable, self::isComplete(...)));
        $documents = self::documentsByReport($data);
        $officers = $this->officersMissingTrainings();

        $answer = 'For the '.$year.' CDA reporting year, '.$data['readiness_label'].' is '.W::percent($data['readiness'])
            .' across '.W::count(count($applicable), 'applicable report').'.';

        if ($complete !== []) {
            $answer .= ' Complete: '.W::listOf(array_map(self::nameOf(...), $complete)).'.';
        }

        if ($incomplete !== []) {
            $answer .= ' Still to finish: '.W::listOf(array_map(fn (array $row) => self::nameOf($row).' ('.self::standing($row, $documents[$row['id']] ?? 0).')', $incomplete)).'.';
        } elseif ($applicable !== []) {
            $answer .= ' Every applicable report is complete.';
        }

        $missing = count($officers['incomplete']);

        if ($missing > 0) {
            $answer .= ' '.W::count($missing, 'officer').' serving today '.($missing === 1 ? 'hasn\'t' : 'haven\'t').' completed the trainings their position requires.';
        }

        return [$answer, [
            LindaReply::stats([
                LindaReply::percent($data['readiness_label'], (int) $data['readiness']),
                LindaReply::number('Applicable Reports', count($applicable)),
                LindaReply::number('Complete', count($complete)),
                LindaReply::number('Still to Finish', count($incomplete)),
                LindaReply::number('Critical Issues', (int) $data['counts']['critical']),
                LindaReply::number('Officers Missing Trainings', $missing),
            ], 'CDA reporting year '.$year),
            ...($applicable === [] ? [] : [LindaReply::list('Reports', self::reportItems($applicable))]),
        ], LindaLinks::forYear($user, $year, 'cda_dashboard'), []];
    }

    /**
     * The reports still incomplete, by name.
     *
     * @return array{0: string, 1: list<array<string, mixed>>, 2: list<array{label: string, url: string}>, 3: list<int>}
     */
    private function incomplete(User $user, CdaReportingPeriod $reportingYear): array
    {
        $year = (int) $reportingYear->year;
        $data = $this->dashboard->dashboard($reportingYear, $user);
        $applicable = self::applicable($data);
        $incomplete = array_values(array_filter($applicable, fn (array $row) => ! self::isComplete($row)));
        $complete = array_values(array_filter($applicable, self::isComplete(...)));

        $answer = match (true) {
            $applicable === [] => 'No CDA report applies for '.$year.'.',
            $incomplete === [] => 'For the '.$year.' CDA reporting year, every applicable report is complete ('.W::count(count($applicable), 'report').').',
            default => 'For the '.$year.' CDA reporting year, '.count($incomplete).' of '.W::count(count($applicable), 'applicable report').' '
                .(count($incomplete) === 1 ? 'is' : 'are').' still incomplete: '
                .W::listOf(array_map(fn (array $row) => self::nameOf($row).' ('.$row['readiness'].'%)', $incomplete)).'.',
        };

        if ($incomplete !== [] && $complete !== []) {
            $answer .= ' Complete: '.W::listOf(array_map(self::nameOf(...), $complete)).'.';
        }

        $answer .= ' '.$data['readiness_label'].' is '.W::percent($data['readiness']).'.';

        return [$answer, [
            LindaReply::stats([
                LindaReply::percent($data['readiness_label'], (int) $data['readiness']),
                LindaReply::number('Applicable Reports', count($applicable)),
                LindaReply::number('Incomplete', count($incomplete)),
            ], 'CDA reporting year '.$year),
            ...($incomplete === [] ? [] : [LindaReply::list('Incomplete reports', self::reportItems($incomplete))]),
        ], LindaLinks::forYear($user, $year, 'cda_dashboard'), []];
    }

    /**
     * Why readiness is what it is: the mean of the applicable reports, and
     * the reports below 100% with their open critical issues.
     *
     * @return array{0: string, 1: list<array<string, mixed>>, 2: list<array{label: string, url: string}>, 3: list<int>}
     */
    private function why(User $user, CdaReportingPeriod $reportingYear): array
    {
        $year = (int) $reportingYear->year;
        $data = $this->dashboard->dashboard($reportingYear, $user);
        $applicable = self::applicable($data);
        $below = array_values(array_filter($applicable, fn (array $row) => (int) $row['readiness'] < 100));
        $critical = [];

        foreach ($data['issues'] as $issue) {
            if ($issue['severity'] === 'critical') {
                $critical[$issue['report_id']][] = (string) $issue['message'];
            }
        }

        $answer = $data['readiness_label'].' for the '.$year.' CDA reporting year is '.W::percent($data['readiness']).': '
            .(count($applicable) === 1
                ? 'it is the readiness of its 1 applicable report.'
                : 'the average of the '.W::count(count($applicable), 'applicable report').'\' own readiness.');

        if ($below === []) {
            $answer .= $applicable === [] ? '' : ' Every applicable report passes all its checks.';
        } else {
            $answer .= ' '.count($below).' '.(count($below) === 1 ? 'report is' : 'reports are').' below 100%: '
                .W::listOf(array_map(fn (array $row) => self::nameOf($row).' ('.$row['readiness'].'%, '.W::count((int) $row['critical'], 'critical issue').')', $below)).'.';

            if (array_filter($below, fn (array $row) => (int) $row['critical'] > 0) !== []) {
                $answer .= ' Their open critical issues are listed below.';
            }

            if (array_filter($below, fn (array $row) => (int) $row['critical'] === 0 && (int) $row['warnings'] === 0 && $row['display_status'] !== 'not_started') !== []) {
                $answer .= ' A report also stays below 100% until it is approved.';
            }
        }

        $max = max(1, (int) config('linda.list_max', 10));

        return [$answer, [
            LindaReply::stats([
                LindaReply::percent($data['readiness_label'], (int) $data['readiness']),
                LindaReply::number('Reports Below 100%', count($below)),
                LindaReply::number('Critical Issues', (int) $data['counts']['critical']),
            ], 'CDA reporting year '.$year),
            ...($below === [] ? [] : [LindaReply::list('Reports below 100%', array_map(fn (array $row): array => [
                'title' => self::nameOf($row),
                'subtitle' => $row['readiness'].'% ready',
                'fields' => [
                    LindaReply::number('Critical Issues', (int) $row['critical']),
                    ...array_map(fn (string $message) => LindaReply::text('Critical issue', $message), array_slice($critical[$row['id']] ?? [], 0, self::QUOTED_ISSUES)),
                ],
            ], array_slice($below, 0, $max)))]),
        ], LindaLinks::forYear($user, $year, 'cda_dashboard'), []];
    }

    /**
     * The documents the readiness issues say are missing, by report.
     *
     * @return array{0: string, 1: list<array<string, mixed>>, 2: list<array{label: string, url: string}>, 3: list<int>}
     */
    private function documents(User $user, CdaReportingPeriod $reportingYear): array
    {
        $year = (int) $reportingYear->year;
        $data = $this->dashboard->dashboard($reportingYear, $user);
        $names = [];

        foreach ($data['reports'] as $row) {
            $names[$row['id']] = self::nameOf($row);
        }

        $missing = array_values(array_filter($data['issues'], fn (array $issue) => self::isMissingDocument($issue)));
        $count = count($missing);
        $max = max(1, (int) config('linda.list_max', 10));
        $critical = count(array_filter($missing, fn (array $issue) => $issue['severity'] === 'critical'));
        $reports = count(array_unique(array_column($missing, 'report_id')));

        $answer = $count === 0
            ? 'For the '.$year.' CDA reporting year, no documents are missing from the applicable reports.'
            : 'For the '.$year.' CDA reporting year, '.W::count($count, 'document').' '.($count === 1 ? 'is' : 'are').' still missing from '
                .W::count($reports, 'report').'; '.$critical.' of them '.($critical === 1 ? 'is' : 'are').' critical. '
                .($count > $max ? "The first {$max} are listed below; " : ($count === 1 ? 'It is listed below; ' : 'They are listed below; '))
                .'upload them in Compliance Documents.';

        return [$answer, [
            LindaReply::stats([
                LindaReply::number('Missing Documents', $count),
                LindaReply::number('Critical', $critical),
            ], 'CDA reporting year '.$year),
            ...($missing === [] ? [] : [LindaReply::list('Missing documents', array_map(fn (array $issue): array => [
                'title' => (string) $issue['message'],
                'subtitle' => $names[$issue['report_id']] ?? '',
                'fields' => [LindaReply::text('Severity', ucfirst((string) $issue['severity']))],
            ], array_slice($missing, 0, $max)))]),
        ], LindaLinks::forYear($user, $year, 'cda_documents', 'cda_dashboard'), []];
    }

    /**
     * The officers serving today who haven't completed their position's
     * required trainings, by the register's own check.
     *
     * @return array{0: string, 1: list<array<string, mixed>>, 2: list<array{label: string, url: string}>, 3: list<int>}
     */
    private function trainings(User $user, CdaReportingPeriod $reportingYear): array
    {
        $officers = $this->officersMissingTrainings();
        $serving = $officers['serving'];
        $missing = count($officers['incomplete']);
        $max = max(1, (int) config('linda.list_max', 10));
        $listed = array_slice($officers['incomplete'], 0, $max);

        $answer = match (true) {
            $serving === 0 => 'No officers are recorded as serving today, '.W::today().'. Add them in Officers & Trainings.',
            $missing === 0 => W::asOfToday().', every officer serving now has completed the trainings their position requires ('.W::count($serving, 'officer').').',
            default => W::asOfToday().', '.$missing.' of '.W::count($serving, 'officer').' serving now '.($missing === 1 ? 'hasn\'t' : 'haven\'t')
                .' completed the trainings their position requires. '
                .($missing > $max ? "The first {$max} are listed below with what is missing." : ($missing === 1 ? 'The officer is listed below with what is missing.' : 'They are listed below with what is missing.')),
        };

        return [$answer, [
            LindaReply::stats([
                LindaReply::number('Officers Serving', $serving),
                LindaReply::number('Officers Missing Trainings', $missing),
            ], W::asOfToday()),
            ...($listed === [] ? [] : [LindaReply::list('Officers missing trainings', array_map(fn (array $row): array => [
                'title' => $row['name'],
                'subtitle' => RegisterWords::position($row['position']),
                'fields' => [LindaReply::text('Missing', W::listOf(array_map(TrainingCompliance::keyInWords(...), $row['missing'])))],
            ], $listed))]),
        ], LindaLinks::forYear($user, (int) $reportingYear->year, 'cda_officers'), array_values(array_filter(array_column($listed, 'member_id')))];
    }

    /**
     * The Enhanced PESOS score and rating, provisional and to verify while the
     * template isn't verified.
     *
     * @return array{0: string, 1: list<array<string, mixed>>, 2: list<array{label: string, url: string}>, 3: list<int>}
     */
    private function pesosRating(User $user, CdaReportingPeriod $reportingYear): array
    {
        $year = (int) $reportingYear->year;
        $result = $this->pesos->for(new SourceContext($reportingYear, $this->pesos->reportFor($reportingYear)));
        $total = count($result['indicators']);
        $scored = count(array_filter($result['indicators'], fn (array $row) => $row['status'] === PesosEngine::STATUS_SCORED));
        $unscored = $total - $scored;
        $start = 'For the '.$year.' CDA reporting year, the Enhanced PESOS';

        $answer = match (true) {
            $result['rating'] !== null => $start.' score is '.$result['score'].', rated '.$result['rating'].'.',
            $total === 0 => $start.' has no indicators to score yet.',
            $result['score'] !== null => $start.' has no rating yet: '.$unscored.' of '.W::count($total, 'indicator').' '.($unscored === 1 ? 'has' : 'have')
                .' no score, and the rating stays open until every indicator is scored. The score so far is '.$result['score'].'.',
            default => $start.' has no rating yet: none of its '.W::count($total, 'indicator').' could be scored.',
        };

        if (! $result['verified']) {
            $answer .= ' It is provisional and to verify: the PESOS template is still to be checked against the official CDA tool.';
        } elseif ($result['provisional']) {
            $answer .= ' It is provisional: some of its figures are not final yet.';
        }

        return [$answer, [
            LindaReply::stats([
                LindaReply::text('Score', $result['score'] ?? '—'),
                LindaReply::text('Rating', $result['rating'] ?? 'Not yet rated'),
                LindaReply::number('Indicators Scored', $scored),
                LindaReply::number('Indicators Without a Score', $unscored),
            ], 'Enhanced PESOS '.$year.($result['provisional'] ? ' (provisional, to verify)' : '')),
        ], LindaLinks::forYear($user, $year, 'cda_pesos'), []];
    }

    /**
     * The named reports: each one's readiness, status, open issues and
     * deadline. A named report the caller can't open is refused in words; a
     * question naming only such reports is refused outright.
     *
     * @param  list<string>  $types
     * @return array{0: string, 1: list<array<string, mixed>>, 2: list<array{label: string, url: string}>, 3: list<int>}|LindaReply
     */
    private function namedReports(User $user, CdaReportingPeriod $reportingYear, array $types): array|LindaReply
    {
        $year = (int) $reportingYear->year;
        $restricted = [];

        foreach ($types as $type) {
            if (! $this->access->canSeeType($user, $type)) {
                $restricted[] = ReportTypes::title($type).' is restricted: it needs '.CdaAccess::words((string) ReportTypes::restriction($type)).'.';
            }
        }

        $data = $this->dashboard->dashboard($reportingYear, $user);
        $rows = array_values(array_filter($data['reports'], fn (array $row) => in_array($row['report_type'], $types, true)));

        if ($rows === []) {
            return $restricted === []
                ? $this->summary($user, $reportingYear)
                : new LindaReply($this->intent()->value, implode(' ', $restricted), 'denied', answerFunction: $this->name());
        }

        $issues = [];
        foreach ($data['issues'] as $issue) {
            if (in_array($issue['severity'], ['critical', 'warning'], true)) {
                $issues[$issue['report_id']][] = $issue;
            }
        }

        $reasons = CdaReport::query()->whereIn('id', array_column($rows, 'id'))->pluck('not_applicable_reason', 'id');
        $max = max(1, (int) config('linda.list_max', 10));
        $sentences = [];
        $blocks = [];

        foreach ($rows as $row) {
            $open = $issues[$row['id']] ?? [];
            usort($open, fn (array $a, array $b): int => ($a['severity'] === 'critical' ? 0 : 1) <=> ($b['severity'] === 'critical' ? 0 : 1));
            $sentences[] = self::reportSentence($row, $year, (string) ($reasons[$row['id']] ?? ''), $open !== []);

            if ($open !== []) {
                $blocks[] = LindaReply::list('Open issues: '.self::nameOf($row), array_map(fn (array $issue): array => [
                    'title' => (string) $issue['message'],
                    'subtitle' => ucfirst((string) $issue['severity']),
                ], array_slice($open, 0, $max)));
            }
        }

        if (count($rows) === 1) {
            $row = $rows[0];
            array_unshift($blocks, LindaReply::stats([
                LindaReply::percent('Readiness', (int) $row['readiness']),
                LindaReply::number('Critical Issues', (int) $row['critical']),
                LindaReply::number('Warnings', (int) $row['warnings']),
                LindaReply::date('Deadline', (string) $row['effective_deadline']),
                LindaReply::number('Days Remaining', (int) $row['days_remaining']),
            ], self::nameOf($row)));
        } else {
            array_unshift($blocks, LindaReply::list('Reports', self::reportItems($rows)));
        }

        $pages = array_values(array_unique(array_map(fn (array $row) => LindaLinks::CDA_REPORT_PAGES[$row['report_type']], $rows)));

        return [
            'For the '.$year.' CDA reporting year, '.implode(' ', [...$sentences, ...$restricted]),
            $blocks,
            LindaLinks::forYear($user, $year, ...[...$pages, 'cda_dashboard']),
            [],
        ];
    }

    /**
     * One report in words: "Cooperative Annual Progress Report (CAPR) is 65%
     * ready (status: Has critical issues). 3 critical issues and 1 warning
     * are open; they are listed below. It is due on April 30, 2027, in 162
     * days."
     *
     * @param  array<string, mixed>  $row  a dashboard row
     */
    private static function reportSentence(array $row, int $year, string $notApplicableReason, bool $listed): string
    {
        $name = self::nameOf($row);

        if (! $row['applicable']) {
            return $name.' doesn\'t apply for '.$year.'.'.($notApplicableReason === '' ? '' : ' '.$notApplicableReason);
        }

        if (in_array($row['status'], self::FILED, true)) {
            return $name.' is filed (status: '.self::statusWords($row).').';
        }

        $critical = (int) $row['critical'];
        $warnings = (int) $row['warnings'];
        $days = (int) $row['days_remaining'];
        $date = W::date((string) $row['effective_deadline']);

        return $name.' is '.$row['readiness'].'% ready (status: '.self::statusWords($row).'). '
            .($critical === 0 && $warnings === 0
                ? 'No issues are open.'
                : W::count($critical, 'critical issue').' and '.W::count($warnings, 'warning').' are open'.($listed ? '; they are listed below.' : '.'))
            .' '.match (true) {
                $days === 0 => 'It is due today, '.$date.'.',
                $days > 0 => 'It is due on '.$date.', in '.W::count($days, 'day').'.',
                default => 'It was due on '.$date.', '.W::count(-$days, 'day').' ago.',
            };
    }

    /**
     * What an incomplete report still needs: "40%: 3 critical issues and 1
     * warning, including 2 missing documents".
     *
     * @param  array<string, mixed>  $row
     */
    private static function standing(array $row, int $documents): string
    {
        $parts = [];

        if ((int) $row['critical'] > 0) {
            $parts[] = W::count((int) $row['critical'], 'critical issue');
        }

        if ((int) $row['warnings'] > 0) {
            $parts[] = W::count((int) $row['warnings'], 'warning');
        }

        $needs = match (true) {
            $row['status'] === CdaReport::STATUS_FOR_CORRECTION => 'returned by CDA for correction',
            $row['display_status'] === 'not_started' => 'not started yet',
            $parts === [] => 'waiting for approval',
            default => W::listOf($parts).($documents > 0 ? ', including '.W::count($documents, 'missing document') : ''),
        };

        return $row['readiness'].'%: '.$needs;
    }

    /**
     * A list row for each report: its status, readiness, open issues and
     * deadline.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function reportItems(array $rows): array
    {
        return array_map(fn (array $row): array => [
            'title' => self::nameOf($row),
            'subtitle' => self::statusWords($row),
            'fields' => [
                LindaReply::percent('Readiness', (int) $row['readiness']),
                LindaReply::number('Critical Issues', (int) $row['critical']),
                LindaReply::number('Warnings', (int) $row['warnings']),
                LindaReply::date('Deadline', (string) $row['effective_deadline']),
            ],
        ], array_slice($rows, 0, max(1, (int) config('linda.list_max', 10))));
    }

    /**
     * The officers serving today and, of them, those whose position's required
     * trainings aren't all complete as of today (the register's check), in the
     * register's order (position, then id).
     *
     * @return array{serving: int, incomplete: list<array{name: string, position: string, missing: list<string>, member_id: ?int}>}
     */
    private function officersMissingTrainings(): array
    {
        $today = LindaPeriod::today()->toDateString();
        $order = array_flip(CdaOfficer::POSITIONS);
        $officers = CdaOfficer::query()->with(['member', 'trainings'])->servingOn($today)->get()
            ->sortBy(fn (CdaOfficer $officer) => sprintf('%02d-%010d', $order[$officer->position] ?? 99, $officer->id))
            ->values();
        $incomplete = [];

        foreach ($officers as $officer) {
            $result = $this->compliance->evaluate($officer, $today, $today);

            if ($result['status'] === TrainingCompliance::INCOMPLETE) {
                $incomplete[] = [
                    'name' => (string) ($officer->displayName() ?? 'An officer'),
                    'position' => (string) $officer->position,
                    'missing' => $result['missing'],
                    'member_id' => $officer->borrower_id === null ? null : (int) $officer->borrower_id,
                ];
            }
        }

        return ['serving' => $officers->count(), 'incomplete' => $incomplete];
    }

    /**
     * @param  array<string, mixed>  $data  the dashboard
     * @return list<array<string, mixed>>
     */
    private static function applicable(array $data): array
    {
        return array_values(array_filter($data['reports'], fn (array $row) => (bool) $row['applicable']));
    }

    /**
     * Filed with CDA, or at 100% (every check passed and approved), unless
     * CDA returned it for correction.
     *
     * @param  array<string, mixed>  $row
     */
    private static function isComplete(array $row): bool
    {
        return in_array($row['status'], self::FILED, true)
            || ((int) $row['readiness'] >= 100 && $row['status'] !== CdaReport::STATUS_FOR_CORRECTION);
    }

    /** @param  array<string, mixed>  $issue */
    private static function isMissingDocument(array $issue): bool
    {
        return $issue['category'] === self::DOCUMENT_CATEGORY && in_array($issue['severity'], ['critical', 'warning'], true);
    }

    /**
     * @param  array<string, mixed>  $data  the dashboard
     * @return array<int, int> report id => its missing documents
     */
    private static function documentsByReport(array $data): array
    {
        $counts = [];

        foreach ($data['issues'] as $issue) {
            if (self::isMissingDocument($issue)) {
                $counts[$issue['report_id']] = ($counts[$issue['report_id']] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** @param  array<string, mixed>  $row */
    private static function statusWords(array $row): string
    {
        return self::STATUS_WORDS[$row['display_status']] ?? ucfirst(str_replace('_', ' ', (string) $row['display_status']));
    }

    /**
     * A report's name with its part: "Mediation and Conciliation Report
     * (first half, January–June)"; an annual report reads its title alone.
     *
     * @param  array<string, mixed>  $row  a dashboard row or the next deadline
     */
    private static function nameOf(array $row): string
    {
        return $row['title'].($row['part'] === ReportTypes::ANNUAL ? '' : ' ('.lcfirst(str_replace([' (', ')'], [', ', ''], (string) $row['title_part'])).')');
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

        $days = (int) $next['days_remaining'];
        $date = W::date((string) $next['effective_deadline']);

        return 'The next deadline is '.self::nameOf($next).', due '
            .($days === 0 ? 'today, '.$date.'.' : 'on '.$date.', in '.W::count($days, 'day').'.');
    }
}

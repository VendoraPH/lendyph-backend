<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * The CDA templates 2026.2 (gap round, 2026-11-12): the fields the CDA tasks
 * list that 2026.1 lacked, from database/cda-templates/2026.2/*.json. Every
 * 2026.1 field key stays with its type, so a draft that moves keeps every
 * value it holds. Still marked `meta.verified: false`: to verify against the
 * official forms.
 *
 * For each report type:
 * - 2026.2 is inserted as active (effective 2026-01-01) and 2026.1, when
 *   active, is retired with RETIRE_REASON;
 * - the draft reports on 2026.1 of the years 2026.2 covers move to 2026.2,
 *   each marked stale so the screen asks for a refresh, with one system audit
 *   row each (`cda_workflow`, no user);
 * - every report that isn't a draft (in review, approved, finalized and
 *   later) keeps 2026.1: its figures and snapshot follow the form it was
 *   prepared on.
 *
 * A 2026.2 row is this migration's own only when it has no creator
 * (`created_by` null) and its definition equals the shipped JSON (isOwn()):
 * a super_admin may already have made a "2026.2" in CDA Settings. A type is
 * left as it is, with a note the dry run lists, when its 2026.2 isn't this
 * migration's, when another active template made in CDA Settings exists for
 * it, or when its 2026.2 is no longer active. Each
 * template change writes an audit row too. A report is re-read under a lock
 * and moved only while it's still a draft on 2026.1, so a second run changes
 * nothing.
 *
 * Written with the query builder alone, and its titles spelled out, so it
 * writes on its last deployment what it wrote on its first. The JSON files
 * are frozen: a later form is a new version folder, never an edit of these.
 * `php artisan cda:activate-templates-2026-2 --dry-run` runs run(true) on a
 * database and lists what this will change there, writing nothing.
 *
 * down() moves back the drafts this moved that are still drafts on its own
 * 2026.2, then deletes each of its own 2026.2 rows no report or snapshot
 * uses, and reactivates the 2026.1 it retired when no other template of that
 * type is active. A 2026.2 still in use, or not its own, stays. Audit rows
 * are never removed.
 */
return new class extends Migration
{
    public const VERSION = '2026.2';

    public const PREVIOUS = '2026.1';

    public const EFFECTIVE_FROM = '2026-01-01';

    public const RETIRE_REASON = 'Replaced by 2026.2, which adds the fields the CDA tasks list (still to verify against the official form).';

    /** Marks the audit rows of a draft this moved, so down() finds them. */
    public const MARK = 'cda_templates_2026_2';

    private const TEMPLATE_CLASS = 'App\Models\CdaReportTemplate';

    private const REPORT_CLASS = 'App\Models\CdaReport';

    private const TITLES = [
        'capr' => 'Cooperative Annual Progress Report (CAPR)',
        'financial_statements' => 'Audited Financial Statements',
        'performance_audit' => 'Performance Audit Report',
        'social_audit' => 'Social Audit Report',
        'pesos' => 'Enhanced PESOS',
        'officers_trainings' => 'List of Officers and Trainings',
        'mediation' => 'Mediation and Conciliation Report',
        'sworn_statement' => 'Sworn Statement of Compliance',
        'atir' => 'Annual Tax Incentives Report (ATIR)',
        'abr' => 'Annual Benefits Report (ABR)',
    ];

    private const PARTS = ['h1' => ' (first half)', 'h2' => ' (second half)'];

    public function up(): void
    {
        $result = $this->run(false);
        $summary = sprintf(
            'CDA templates 2026.2: %d type(s) activated, %d left as they are, %d draft report(s) moved',
            count(array_filter($result['types'], fn (array $type) => $type['changed'])),
            count(array_filter($result['types'], fn (array $type) => ! $type['changed'])),
            count($result['moved']),
        );

        Log::info($summary, $result);

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    /**
     * @return array{types: list<array{type: string, changed: bool, retired: bool, activated: bool, note: string|null}>, moved: list<array{id: int, type: string, part: string, year: int}>}
     */
    public function run(bool $dryRun): array
    {
        $result = ['types' => [], 'moved' => []];
        $files = glob(database_path('cda-templates/'.self::VERSION.'/*.json')) ?: [];
        sort($files);

        foreach ($files as $file) {
            $template = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

            $apply = function () use ($template, $dryRun, &$result): void {
                [$entry, $moved] = $this->type($template, $dryRun);
                $result['types'][] = $entry;
                $result['moved'] = [...$result['moved'], ...$moved];
            };

            $dryRun ? $apply() : DB::transaction($apply);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array{0: array{type: string, changed: bool, retired: bool, activated: bool, note: string|null}, 1: list<array{id: int, type: string, part: string, year: int}>}
     */
    private function type(array $template, bool $dryRun): array
    {
        $type = (string) $template['report_type'];
        $entry = ['type' => $type, 'changed' => false, 'retired' => false, 'activated' => false, 'note' => null];
        $templates = DB::table('cda_report_templates')->where('report_type', $type);

        if (! $dryRun) {
            $templates->lockForUpdate();
        }

        $rows = $templates->get(['id', 'version', 'status', 'reason', 'created_by', 'definition'])->keyBy('version');
        $old = $rows->get(self::PREVIOUS);
        $new = $rows->get(self::VERSION);
        $own = $rows->first(fn ($row) => $row->status === 'active' && ! in_array($row->version, [self::PREVIOUS, self::VERSION], true));

        if ($new !== null && ! self::isOwn($new, $template['definition'])) {
            $entry['note'] = 'a 2026.2 made in CDA Settings is already there';

            return [$entry, []];
        }

        if ($new === null && $own !== null) {
            $entry['note'] = "the template {$own->version}, made in CDA Settings, is active";

            return [$entry, []];
        }

        if ($new !== null && $new->status !== 'active') {
            $entry['note'] = "its 2026.2 is {$new->status}";

            return [$entry, []];
        }

        $newId = $new?->id;

        if ($new === null) {
            $entry['activated'] = $entry['changed'] = true;
            $newId = $dryRun ? null : $this->insert($template);
        }

        if ($old !== null && $old->status === 'active') {
            $entry['retired'] = $entry['changed'] = true;

            if (! $dryRun) {
                $this->retire($old, $type);
            }
        }

        $moved = $old === null ? [] : $this->moveDrafts($type, (int) $old->id, $newId, $dryRun);

        return [$entry, $moved];
    }

    /** @param  array<string, mixed>  $template */
    private function insert(array $template): int
    {
        $id = (int) DB::table('cda_report_templates')->insertGetId([
            'report_type' => $template['report_type'],
            'version' => $template['version'],
            'name' => $template['name'],
            'effective_from' => $template['effective_from'],
            'effective_until' => $template['effective_until'] ?? null,
            'cooperative_types' => json_encode($template['cooperative_types'] ?? []),
            'asset_classes' => json_encode($template['asset_classes'] ?? []),
            'definition' => json_encode($template['definition'], JSON_UNESCAPED_UNICODE),
            'status' => 'active',
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit('created', self::TEMPLATE_CLASS, $id, null, [
            'report_type' => $template['report_type'],
            'version' => self::VERSION,
            'status' => 'active',
            'effective_from' => $template['effective_from'],
        ], sprintf('System change: CDA template %s %s activated (still to verify against the official form).', self::TITLES[$template['report_type']] ?? $template['report_type'], self::VERSION));

        return $id;
    }

    private function retire(object $old, string $type): void
    {
        DB::table('cda_report_templates')->where('id', $old->id)->update(['status' => 'retired', 'reason' => self::RETIRE_REASON, 'updated_at' => now()]);

        $this->audit('updated', self::TEMPLATE_CLASS, (int) $old->id, ['status' => 'active'], ['status' => 'retired', 'reason' => self::RETIRE_REASON],
            sprintf('System change: CDA template %s %s retired: %s', self::TITLES[$type] ?? $type, self::PREVIOUS, self::RETIRE_REASON));
    }

    /** @return list<array{id: int, type: string, part: string, year: int}> */
    private function moveDrafts(string $type, int $oldId, ?int $newId, bool $dryRun): array
    {
        $query = DB::table('cda_reports')
            ->join('cda_reporting_periods', 'cda_reporting_periods.id', '=', 'cda_reports.period_id')
            ->where('cda_reports.report_type', $type)
            ->where('cda_reports.status', 'draft')
            ->where('cda_reports.template_id', $oldId)
            ->where('cda_reporting_periods.period_end', '>=', self::EFFECTIVE_FROM)
            ->orderBy('cda_reports.id');

        if (! $dryRun) {
            $query->lockForUpdate();
        }

        $moved = [];

        foreach ($query->get(['cda_reports.id', 'cda_reports.part', 'cda_reporting_periods.year']) as $report) {
            $entry = ['id' => (int) $report->id, 'type' => $type, 'part' => (string) $report->part, 'year' => (int) $report->year];

            if (! $dryRun) {
                DB::table('cda_reports')->where('id', $report->id)->update(['template_id' => $newId, 'stale_at' => now(), 'updated_at' => now()]);

                $this->audit('cda_workflow', self::REPORT_CLASS, (int) $report->id,
                    ['status' => 'draft', 'template_id' => $oldId, 'template_version' => self::PREVIOUS],
                    ['status' => 'draft', 'template_id' => $newId, 'template_version' => self::VERSION, 'system_change' => self::MARK],
                    sprintf('System change: the %s %d%s draft moved from template %s to %s, which adds the fields the CDA tasks list (still to verify against the official form). Refresh the report to fill them.',
                        self::TITLES[$type] ?? $type, $entry['year'], self::PARTS[$entry['part']] ?? '', self::PREVIOUS, self::VERSION),
                );
            }

            $moved[] = $entry;
        }

        return $moved;
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $shipped = $this->shippedDefinitions();
            $ownIds = DB::table('cda_report_templates')->where('version', self::VERSION)->lockForUpdate()
                ->get(['id', 'report_type', 'created_by', 'definition'])
                ->filter(fn ($row) => isset($shipped[$row->report_type]) && self::isOwn($row, $shipped[$row->report_type]))
                ->map(fn ($row) => (int) $row->id)->values()->all();
            $rows = DB::table('audit_logs')
                ->where('action', 'cda_workflow')
                ->where('auditable_type', self::REPORT_CLASS)
                ->where('new_values->system_change', self::MARK)
                ->orderBy('id')
                ->get(['auditable_id', 'old_values', 'new_values']);

            foreach ($rows as $row) {
                $old = json_decode((string) $row->old_values, true);
                $new = json_decode((string) $row->new_values, true);
                $report = DB::table('cda_reports')
                    ->join('cda_reporting_periods', 'cda_reporting_periods.id', '=', 'cda_reports.period_id')
                    ->where('cda_reports.id', $row->auditable_id)
                    ->lockForUpdate()
                    ->first(['cda_reports.id', 'cda_reports.report_type', 'cda_reports.part', 'cda_reports.status', 'cda_reports.template_id', 'cda_reporting_periods.year']);

                if ($report === null || $report->status !== 'draft' || (int) $report->template_id !== (int) ($new['template_id'] ?? 0)
                    || ! in_array((int) $report->template_id, $ownIds, true)
                    || ! DB::table('cda_report_templates')->where('id', $old['template_id'] ?? 0)->exists()) {
                    continue;
                }

                DB::table('cda_reports')->where('id', $report->id)->update(['template_id' => $old['template_id'], 'stale_at' => now(), 'updated_at' => now()]);

                $this->audit('cda_workflow', self::REPORT_CLASS, (int) $report->id,
                    ['status' => 'draft', 'template_id' => (int) $report->template_id, 'template_version' => self::VERSION],
                    ['status' => 'draft', 'template_id' => (int) $old['template_id'], 'template_version' => self::PREVIOUS, 'system_change' => self::MARK.'_rollback'],
                    sprintf('System change: the %s %d%s draft moved back from template %s to %s (the 2026.2 update was rolled back).',
                        self::TITLES[$report->report_type] ?? $report->report_type, (int) $report->year, self::PARTS[$report->part] ?? '', self::VERSION, self::PREVIOUS),
                );
            }

            foreach (DB::table('cda_report_templates')->where('version', self::VERSION)->lockForUpdate()->get(['id', 'report_type', 'status', 'created_by', 'definition']) as $template) {
                if (! isset($shipped[$template->report_type]) || ! self::isOwn($template, $shipped[$template->report_type])) {
                    continue;
                }

                $used = DB::table('cda_reports')->where('template_id', $template->id)->exists()
                    || DB::table('cda_report_snapshots')->where('template_id', $template->id)->exists();

                if ($used) {
                    Log::warning("CDA template {$template->report_type} 2026.2 is used by a report and was kept.");

                    continue;
                }

                DB::table('cda_report_templates')->where('id', $template->id)->delete();
                $this->audit('deleted', self::TEMPLATE_CLASS, (int) $template->id, ['report_type' => $template->report_type, 'version' => self::VERSION, 'status' => $template->status], null,
                    sprintf('System change: CDA template %s %s removed (the 2026.2 update was rolled back).', self::TITLES[$template->report_type] ?? $template->report_type, self::VERSION));

                $previous = DB::table('cda_report_templates')
                    ->where('report_type', $template->report_type)->where('version', self::PREVIOUS)
                    ->where('status', 'retired')->where('reason', self::RETIRE_REASON)
                    ->first(['id']);

                $otherActive = DB::table('cda_report_templates')
                    ->where('report_type', $template->report_type)->where('status', 'active')->exists();

                if ($previous !== null && ! $otherActive) {
                    DB::table('cda_report_templates')->where('id', $previous->id)->update(['status' => 'active', 'reason' => null, 'updated_at' => now()]);
                    $this->audit('updated', self::TEMPLATE_CLASS, (int) $previous->id, ['status' => 'retired', 'reason' => self::RETIRE_REASON], ['status' => 'active', 'reason' => null],
                        sprintf('System change: CDA template %s %s active again (the 2026.2 update was rolled back).', self::TITLES[$template->report_type] ?? $template->report_type, self::PREVIOUS));
                }
            }
        });
    }

    /**
     * Whether a 2026.2 row is the one this migration inserted: no creator, and
     * the shipped definition (compared as data, since MySQL stores JSON with
     * its keys reordered).
     *
     * @param  array<string, mixed>  $shipped
     */
    private static function isOwn(object $row, array $shipped): bool
    {
        if ($row->created_by !== null) {
            return false;
        }

        $stored = json_decode((string) $row->definition, true);

        return is_array($stored) && self::canonical($stored) === self::canonical($shipped);
    }

    /** Associative arrays with their keys sorted, at every depth; lists keep their order. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn (mixed $item) => self::canonical($item), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @return array<string, array<string, mixed>> report type => the shipped 2026.2 definition */
    private function shippedDefinitions(): array
    {
        $definitions = [];

        foreach (glob(database_path('cda-templates/'.self::VERSION.'/*.json')) ?: [] as $file) {
            $template = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $definitions[(string) $template['report_type']] = (array) $template['definition'];
        }

        return $definitions;
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(string $action, string $class, int $id, ?array $old, ?array $new, string $description): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => null,
            'action' => $action,
            'auditable_type' => $class,
            'auditable_id' => $id,
            'old_values' => $old === null ? null : json_encode($old),
            'new_values' => $new === null ? null : json_encode($new),
            'ip_address' => null,
            'user_agent' => null,
            'description' => $description,
            'created_at' => now(),
        ]);
    }
};

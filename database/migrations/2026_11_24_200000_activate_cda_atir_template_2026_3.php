<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * The ATIR template 2026.3 (finish run, 2026-11-24; Phase 3 task "ATIR and
 * ABR"): 2026.2 with the sector, subsector, region, province and city read
 * from the regulatory profile (`profile.field` sector, subsector,
 * address_region, address_province and address_city) instead of typed, from
 * database/cda-templates/2026.3/atir.json. Every 2026.2 field keeps its key
 * and type. Still marked `meta.verified: false`: to verify against the
 * official form.
 *
 * The 2026.2 update's pattern (2026_11_13_130000), for ATIR alone:
 * - 2026.3 is inserted as active (effective 2026-01-01) and 2026.2, when
 *   active, is retired with RETIRE_REASON;
 * - the draft ATIR reports on 2026.2 of the years 2026.3 covers move to
 *   2026.3, each marked stale so the screen asks for a refresh, with one
 *   system audit row each (`cda_workflow`, no user);
 * - an ATIR report that isn't a draft (in review, approved, finalized and
 *   later) keeps 2026.2: its figures and snapshot follow the form it was
 *   prepared on.
 *
 * A template is this migration's own (or the 2026.2 update's) only when it
 * has no creator and its definition equals the shipped JSON (isOwn()). ATIR
 * is left as it is, with a note the dry run lists, when its 2026.3 isn't
 * this migration's, when its 2026.2 isn't the 2026.2 update's (one made in
 * Settings → CDA), when another ATIR template is active, or when its 2026.3
 * is no longer active. Each template change writes an audit row too. A report
 * is re-read under a lock and moved only while it's still a draft on 2026.2,
 * so a second run changes nothing.
 *
 * Written with the query builder alone, and its titles spelled out, so it
 * writes on its last deployment what it wrote on its first. The JSON file is
 * frozen: a later form is a new version folder, never an edit of it.
 * `php artisan cda:activate-atir-template-2026-3 --dry-run` runs run(true) on
 * a database and lists what this will change there, writing nothing.
 *
 * down() moves back the drafts this moved that are still drafts on its own
 * 2026.3, then deletes its own 2026.3 when no report or snapshot uses it, and
 * reactivates the 2026.2 it retired when no other ATIR template is active.
 * Audit rows are never removed.
 */
return new class extends Migration
{
    public const TYPE = 'atir';

    public const VERSION = '2026.3';

    public const PREVIOUS = '2026.2';

    public const EFFECTIVE_FROM = '2026-01-01';

    public const RETIRE_REASON = 'Replaced by 2026.3, which reads the sector, subsector, region, province and city from the regulatory profile (still to verify against the official form).';

    /** Marks the audit rows of a draft this moved, so down() finds them. */
    public const MARK = 'cda_atir_template_2026_3';

    private const TEMPLATE_CLASS = 'App\Models\CdaReportTemplate';

    private const REPORT_CLASS = 'App\Models\CdaReport';

    private const TITLE = 'Annual Tax Incentives Report (ATIR)';

    public function up(): void
    {
        $result = $this->run(false);
        $summary = sprintf(
            'CDA ATIR template 2026.3: %s, %d draft report(s) moved',
            $result['type']['note'] === null ? 'active' : 'left as it is ('.$result['type']['note'].')',
            count($result['moved']),
        );

        Log::info($summary, $result);

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    /**
     * @return array{type: array{type: string, changed: bool, retired: bool, activated: bool, note: string|null}, moved: list<array{id: int, part: string, year: int}>}
     */
    public function run(bool $dryRun): array
    {
        $apply = fn (): array => $this->apply(self::shipped(self::VERSION), $dryRun);

        return $dryRun ? $apply() : DB::transaction($apply);
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array{type: array{type: string, changed: bool, retired: bool, activated: bool, note: string|null}, moved: list<array{id: int, part: string, year: int}>}
     */
    private function apply(array $template, bool $dryRun): array
    {
        $entry = ['type' => self::TYPE, 'changed' => false, 'retired' => false, 'activated' => false, 'note' => null];
        $templates = DB::table('cda_report_templates')->where('report_type', self::TYPE);

        if (! $dryRun) {
            $templates->lockForUpdate();
        }

        $rows = $templates->get(['id', 'version', 'status', 'created_by', 'definition'])->keyBy('version');
        $old = $rows->get(self::PREVIOUS);
        $new = $rows->get(self::VERSION);
        $other = $rows->first(fn ($row) => $row->status === 'active' && ! in_array($row->version, [self::PREVIOUS, self::VERSION], true));

        $entry['note'] = match (true) {
            $new !== null && ! self::isOwn($new, $template['definition']) => 'a 2026.3 made in Settings → CDA is already there',
            $old !== null && ! self::isOwn($old, self::shipped(self::PREVIOUS)['definition']) => 'its 2026.2 was made in Settings → CDA',
            $new === null && $other !== null => "the template {$other->version}, not 2026.2, is active",
            $new !== null && $new->status !== 'active' => "its 2026.3 is {$new->status}",
            default => null,
        };

        if ($entry['note'] !== null) {
            return ['type' => $entry, 'moved' => []];
        }

        $newId = $new?->id;

        if ($new === null) {
            $entry['activated'] = $entry['changed'] = true;
            $newId = $dryRun ? null : $this->insert($template);
        }

        if ($old !== null && $old->status === 'active') {
            $entry['retired'] = $entry['changed'] = true;

            if (! $dryRun) {
                $this->retire((int) $old->id);
            }
        }

        $moved = $old === null ? [] : $this->moveDrafts((int) $old->id, $newId, $dryRun);

        return ['type' => $entry, 'moved' => $moved];
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
            'report_type' => self::TYPE,
            'version' => self::VERSION,
            'status' => 'active',
            'effective_from' => $template['effective_from'],
        ], sprintf('System change: CDA template %s %s activated (still to verify against the official form).', self::TITLE, self::VERSION));

        return $id;
    }

    private function retire(int $oldId): void
    {
        DB::table('cda_report_templates')->where('id', $oldId)->update(['status' => 'retired', 'reason' => self::RETIRE_REASON, 'updated_at' => now()]);

        $this->audit('updated', self::TEMPLATE_CLASS, $oldId, ['status' => 'active'], ['status' => 'retired', 'reason' => self::RETIRE_REASON],
            sprintf('System change: CDA template %s %s retired: %s', self::TITLE, self::PREVIOUS, self::RETIRE_REASON));
    }

    /** @return list<array{id: int, part: string, year: int}> */
    private function moveDrafts(int $oldId, ?int $newId, bool $dryRun): array
    {
        $query = DB::table('cda_reports')
            ->join('cda_reporting_periods', 'cda_reporting_periods.id', '=', 'cda_reports.period_id')
            ->where('cda_reports.report_type', self::TYPE)
            ->where('cda_reports.status', 'draft')
            ->where('cda_reports.template_id', $oldId)
            ->where('cda_reporting_periods.period_end', '>=', self::EFFECTIVE_FROM)
            ->orderBy('cda_reports.id');

        if (! $dryRun) {
            $query->lockForUpdate();
        }

        $moved = [];

        foreach ($query->get(['cda_reports.id', 'cda_reports.part', 'cda_reporting_periods.year']) as $report) {
            $entry = ['id' => (int) $report->id, 'part' => (string) $report->part, 'year' => (int) $report->year];

            if (! $dryRun) {
                DB::table('cda_reports')->where('id', $report->id)->update(['template_id' => $newId, 'stale_at' => now(), 'updated_at' => now()]);

                $this->audit('cda_workflow', self::REPORT_CLASS, (int) $report->id,
                    ['status' => 'draft', 'template_id' => $oldId, 'template_version' => self::PREVIOUS],
                    ['status' => 'draft', 'template_id' => $newId, 'template_version' => self::VERSION, 'system_change' => self::MARK],
                    sprintf('System change: the %s %d draft moved from template %s to %s, which reads the sector, subsector, region, province and city from the '
                        .'regulatory profile (still to verify against the official form). Refresh the report to fill them from the profile in Settings → CDA.',
                        self::TITLE, $entry['year'], self::PREVIOUS, self::VERSION),
                );
            }

            $moved[] = $entry;
        }

        return $moved;
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $own = DB::table('cda_report_templates')->where('report_type', self::TYPE)->where('version', self::VERSION)->lockForUpdate()
                ->first(['id', 'status', 'created_by', 'definition']);

            if ($own === null || ! self::isOwn($own, self::shipped(self::VERSION)['definition'])) {
                return;
            }

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
                    ->first(['cda_reports.id', 'cda_reports.status', 'cda_reports.template_id', 'cda_reporting_periods.year']);

                if ($report === null || $report->status !== 'draft' || (int) $report->template_id !== (int) $own->id
                    || (int) ($new['template_id'] ?? 0) !== (int) $own->id
                    || ! DB::table('cda_report_templates')->where('id', $old['template_id'] ?? 0)->exists()) {
                    continue;
                }

                DB::table('cda_reports')->where('id', $report->id)->update(['template_id' => $old['template_id'], 'stale_at' => now(), 'updated_at' => now()]);

                $this->audit('cda_workflow', self::REPORT_CLASS, (int) $report->id,
                    ['status' => 'draft', 'template_id' => (int) $own->id, 'template_version' => self::VERSION],
                    ['status' => 'draft', 'template_id' => (int) $old['template_id'], 'template_version' => self::PREVIOUS, 'system_change' => self::MARK.'_rollback'],
                    sprintf('System change: the %s %d draft moved back from template %s to %s (the 2026.3 update was rolled back).',
                        self::TITLE, (int) $report->year, self::VERSION, self::PREVIOUS),
                );
            }

            $used = DB::table('cda_reports')->where('template_id', $own->id)->exists()
                || DB::table('cda_report_snapshots')->where('template_id', $own->id)->exists();

            if ($used) {
                Log::warning('CDA template atir 2026.3 is used by a report and was kept.');

                return;
            }

            DB::table('cda_report_templates')->where('id', $own->id)->delete();
            $this->audit('deleted', self::TEMPLATE_CLASS, (int) $own->id, ['report_type' => self::TYPE, 'version' => self::VERSION, 'status' => $own->status], null,
                sprintf('System change: CDA template %s %s removed (the 2026.3 update was rolled back).', self::TITLE, self::VERSION));

            $previous = DB::table('cda_report_templates')
                ->where('report_type', self::TYPE)->where('version', self::PREVIOUS)
                ->where('status', 'retired')->where('reason', self::RETIRE_REASON)
                ->first(['id']);

            $otherActive = DB::table('cda_report_templates')->where('report_type', self::TYPE)->where('status', 'active')->exists();

            if ($previous !== null && ! $otherActive) {
                DB::table('cda_report_templates')->where('id', $previous->id)->update(['status' => 'active', 'reason' => null, 'updated_at' => now()]);
                $this->audit('updated', self::TEMPLATE_CLASS, (int) $previous->id, ['status' => 'retired', 'reason' => self::RETIRE_REASON], ['status' => 'active', 'reason' => null],
                    sprintf('System change: CDA template %s %s active again (the 2026.3 update was rolled back).', self::TITLE, self::PREVIOUS));
            }
        });
    }

    /**
     * Whether a template row is the one shipped as `$shipped`: no creator,
     * and the same definition (compared as data, since MySQL stores JSON with
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

    /** @return array<string, mixed> the shipped ATIR template of `$version` */
    private static function shipped(string $version): array
    {
        return json_decode((string) file_get_contents(database_path("cda-templates/{$version}/".self::TYPE.'.json')), true, flags: JSON_THROW_ON_ERROR);
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

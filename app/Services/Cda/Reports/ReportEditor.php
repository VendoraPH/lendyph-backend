<?php

namespace App\Services\Cda\Reports;

use App\Models\CdaReport;
use App\Models\CdaReportValue;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Cda\Sources\SourceCatalog;
use App\Services\LoanWriteTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * PUT /cda/reports/{id} (design §3, §4): saves what staff type on a draft.
 *
 * - A manual field takes a value of its type (FieldValue), remarks and
 *   evidence.
 * - A sourced field takes remarks and evidence; its value only when the
 *   template marks it overridable (never a loans, accounting, fs, share
 *   capital or savings figure), with an `override_reason`. `revert: true`
 *   drops the override and puts the source's figure back.
 * - A calculated field is never typed.
 * - The report row is locked and must still be a draft (409 otherwise).
 *
 * Then the calculations, issues and readiness are recomputed at once, and
 * one audit row lists the fields that changed, old and new.
 */
final class ReportEditor
{
    private const STATUS_WORDS = ['for_review' => 'for review', 'approved' => 'approved', 'finalized' => 'finalized', 'submitted' => 'submitted', 'accepted' => 'accepted', 'for_correction' => 'for correction'];

    public function __construct(private readonly ReportEngine $engine) {}

    /** @param  list<array<string, mixed>>  $fields */
    public function save(CdaReport $report, array $fields, User $user): CdaReport
    {
        return DB::transaction(function () use ($report, $fields, $user): CdaReport {
            $locked = CdaReport::query()->with(['template', 'period'])->whereKey($report->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CdaReport::STATUS_DRAFT) {
                throw LoanWriteTransaction::conflict('This report is '.(self::STATUS_WORDS[$locked->status] ?? $locked->status).', so it can\'t be changed. '.($locked->isLocked() ? 'Reopen it first, with a reason.' : 'Return it to draft first.'));
            }

            $definition = $this->engine->definition($locked);
            $stored = CdaReportValue::query()->where('report_id', $locked->id)->get()->keyBy('field_key');
            $errors = [];
            $old = [];
            $new = [];

            foreach ($fields as $n => $input) {
                $key = (string) $input['field_key'];
                $field = $definition->field($key);

                if ($field === null) {
                    $errors["fields.{$n}.field_key"][] = "\"{$key}\" isn't a field of this report.";

                    continue;
                }

                $row = $stored[$key] ?? new CdaReportValue(['report_id' => $locked->id, 'field_key' => $key]);
                $label = ReportValidator::label($field);
                $changes = [];

                if (array_key_exists('remarks', $input)) {
                    $changes['remarks'] = $input['remarks'];
                }

                if (array_key_exists('evidence', $input)) {
                    $changes['evidence'] = $input['evidence'];
                }

                if (($input['revert'] ?? false) === true) {
                    if (! $row->exists || ! $row->is_override) {
                        $errors["fields.{$n}.revert"][] = "{$label} isn't overridden.";

                        continue;
                    }

                    $changes += ['value' => FieldValue::fromSource($field, $row->source_value['value'] ?? null), 'is_override' => false, 'override_reason' => null];
                } elseif (array_key_exists('value', $input)) {
                    $problem = $this->valueProblem($field, $definition, $input, $row);

                    if ($problem !== null) {
                        $errors["fields.{$n}.".$problem[0]][] = $problem[1];

                        continue;
                    }

                    try {
                        $value = FieldValue::fromInput($field, $input['value']);
                    } catch (InvalidArgumentException $e) {
                        $errors["fields.{$n}.value"][] = $e->getMessage();

                        continue;
                    }

                    $changes['value'] = $value;

                    if (isset($field['source'])) {
                        $changes += ['is_override' => true, 'override_reason' => trim((string) $input['override_reason'])];
                    }
                }

                if ($changes === []) {
                    continue;
                }

                $before = $row->exists ? $row->value : null;
                $row->fill([...$changes, 'updated_by' => $user->id]);

                if ($row->exists && ! $row->isDirty()) {
                    continue;
                }

                $row->save();
                $stored[$key] = $row;
                $old[$key] = $before;
                $new[$key] = $row->value;
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            if ($new !== []) {
                AuditLogService::log(
                    'cda_report_saved',
                    $locked,
                    ['values' => $old],
                    ['values' => $new],
                    sprintf('Saved %s %d: %d %s.', ReportValidator::reportName($locked), $locked->period->year, count($new), count($new) === 1 ? 'field' : 'fields'),
                );
            }

            $this->engine->recompute($locked);

            return $locked;
        });
    }

    /** @return array{0: string, 1: string}|null the error's key and message */
    private function valueProblem(array $field, ReportDefinition $definition, array $input, CdaReportValue $row): ?array
    {
        $label = ReportValidator::label($field);

        if (isset($field['calc'])) {
            return ['field_key', "{$label} is calculated from other fields and can't be typed."];
        }

        if (isset($field['source'])) {
            $sourceKey = (string) $field['source']['key'];

            if (($field['overridable'] ?? false) !== true || ! SourceCatalog::overridable($sourceKey)) {
                $from = $row->source_label ?? SourceCatalog::get($sourceKey)['label'];

                return ['field_key', "{$label} comes from {$from} and can't be changed here. Fix it at its source."];
            }

            if (! is_string($input['override_reason'] ?? null) || trim($input['override_reason']) === '') {
                return ['override_reason', "Give a reason for overriding {$label}."];
            }

            return null;
        }

        if ($field['type'] === 'table' && ! $definition->isManual($field)) {
            return ['field_key', "{$label} is filled from its source and can't be typed."];
        }

        return null;
    }
}

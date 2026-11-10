<?php

namespace App\Services\Cda\Reports;

use App\Http\Resources\Cda\CdaPresenter;
use App\Models\Branch;
use App\Models\BrandingSetting;
use App\Models\CdaRegulatoryProfile;
use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use App\Models\CdaReportValue;
use App\Models\User;
use App\Services\Cda\ReportTypes;
use Illuminate\Support\Carbon;

/**
 * Freezes a report on finalize (design §5): the regulatory profile and the
 * cooperative details pulled from Settings, the template and its definition
 * hash, every value with its source, override and remarks (table rows
 * included, so the register rows used are kept), the issues and readiness,
 * the stage, who finalized it and when. Stored as canonical JSON with its
 * SHA-256, version by version; a reopen supersedes a version, never changes
 * or deletes it. Exports and the screen read a finalized report from here,
 * so later member, Branding or profile changes leave it as it was filed.
 */
final class SnapshotWriter
{
    public const SCHEMA = 1;

    public function write(CdaReport $report, ReportDefinition $definition, User $user, bool $warningsConfirmed): CdaReportSnapshot
    {
        $version = (int) CdaReportSnapshot::query()->where('report_id', $report->id)->max('version') + 1;
        $finalizedAt = Carbon::now();
        $template = $report->template;
        $rows = CdaReportValue::query()->where('report_id', $report->id)->orderBy('field_key')->get();

        $values = [];

        foreach ($rows as $row) {
            if (str_starts_with($row->field_key, '_')) {
                continue;
            }

            $values[$row->field_key] = [
                'value' => $row->value,
                'source_key' => $row->source_key,
                'source_label' => $row->source_label,
                'source_params' => $row->source_params,
                'source_value' => $row->source_value,
                'is_override' => (bool) $row->is_override,
                'override_reason' => $row->override_reason,
                'remarks' => $row->remarks,
                'evidence' => $row->evidence,
            ];
        }

        $payload = [
            'schema' => self::SCHEMA,
            'version' => $version,
            'report' => [
                'id' => (int) $report->id,
                'period_id' => (int) $report->period_id,
                'year' => (int) $report->period->year,
                'report_type' => $report->report_type,
                'part' => $report->part,
                'title' => ReportTypes::title($report->report_type),
                'title_part' => ReportTypes::PART_TITLES[$report->part] ?? $report->part,
            ],
            'template' => $template === null ? null : [
                'id' => (int) $template->id,
                'name' => $template->name,
                'version' => $template->version,
                'verified' => $template->isVerified(),
                'definition_hash' => $definition->hash(),
            ],
            'profile' => $this->profile(),
            'values' => $values,
            'stage' => ReportStages::current($report),
            'issues' => $report->issues ?? [],
            'readiness' => (int) $report->readiness,
            'readiness_breakdown' => $report->readiness_breakdown ?? [],
            'warnings_confirmed' => $warningsConfirmed,
            'finalized_by' => CdaPresenter::user($user),
            'finalized_at' => $finalizedAt->toIso8601String(),
        ];

        $canonical = CanonicalJson::encode($payload);

        return CdaReportSnapshot::query()->create([
            'report_id' => $report->id,
            'version' => $version,
            'template_id' => $template?->id,
            'payload' => $canonical,
            'checksum' => CanonicalJson::checksum($canonical),
            'finalized_by' => $user->id,
            'finalized_at' => $finalizedAt,
        ]);
    }

    /** Whether the stored payload still hashes to its checksum, and is still canonical. */
    public static function verify(CdaReportSnapshot $snapshot): bool
    {
        $decoded = json_decode($snapshot->payload, true);

        return is_array($decoded)
            && hash_equals($snapshot->checksum, CanonicalJson::checksum($snapshot->payload))
            && CanonicalJson::encode($decoded) === $snapshot->payload;
    }

    /** @return array<string, mixed> */
    private function profile(): array
    {
        $profile = CdaRegulatoryProfile::current();
        $stored = [];

        foreach (CdaRegulatoryProfile::FIELDS as $field) {
            $value = $profile->{$field};
            $stored[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }

        $branding = BrandingSetting::current();

        return [
            'stored' => $stored,
            'pulled' => [
                'name' => $branding->organization_name,
                'address' => $branding->organization_address,
                'contact' => $branding->organization_contact,
                'branch_count' => Branch::query()->where('is_active', true)->count(),
            ],
        ];
    }
}

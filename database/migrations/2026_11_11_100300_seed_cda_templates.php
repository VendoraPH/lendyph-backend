<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the v1 CDA report templates (CDA design §2) from
 * database/cda-templates/*.json: one active template per report type,
 * version 2026.1, effective from 2026-01-01, for every cooperative type and
 * asset class. Every one is marked `meta.verified: false`: they follow the
 * CDA forms as understood in v1 and are still to be checked against the
 * official CY2026 CAPR, the April 2026 ATIR and ABR templates and the
 * Enhanced PESOS tool. A verified form is a new version made by super_admin
 * in the template manager, never an edit of these.
 *
 * Only inserts: a (report type, version) already present is left as it is,
 * so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $files = glob(database_path('cda-templates/*.json')) ?: [];
        sort($files);

        foreach ($files as $file) {
            $template = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

            $exists = DB::table('cda_report_templates')
                ->where('report_type', $template['report_type'])
                ->where('version', $template['version'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('cda_report_templates')->insert([
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
        }
    }

    /**
     * Removes only seeded templates no report uses.
     */
    public function down(): void
    {
        DB::table('cda_report_templates')
            ->where('version', '2026.1')
            ->whereNotIn('id', DB::table('cda_reports')->whereNotNull('template_id')->select('template_id'))
            ->delete();
    }
};

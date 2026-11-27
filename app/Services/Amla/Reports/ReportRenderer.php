<?php

namespace App\Services\Amla\Reports;

use App\Models\BrandingSetting;
use App\Models\User;
use App\Services\Cda\Exports\ExportContent;
use App\Services\Cda\Exports\PdfRenderer;
use App\Services\Cda\Exports\XlsxWriter;
use Carbon\CarbonImmutable;

/**
 * Renders an AMLA report on the server, as the CDA exports are: PDF through
 * the CDA PdfRenderer and Excel through its XlsxWriter (one table section, ₱
 * with two decimals, negative money in parentheses), and CSV here. Each
 * export marks DRAFT while its period is still open and says it is an
 * internal, confidential AMLA report. CSV cells that a spreadsheet would read
 * as a formula are written as text.
 */
final class ReportRenderer
{
    public const CONFIDENTIAL = 'Internal AMLA report · confidential';

    public function __construct(private readonly PdfRenderer $pdf, private readonly XlsxWriter $xlsx) {}

    public function render(ReportTable $table, string $format, User $user): string
    {
        return match ($format) {
            'pdf' => $this->pdf->render($this->content($table, $user)),
            'xlsx' => $this->xlsx->write($this->content($table, $user)),
            default => $this->csv($table, $user),
        };
    }

    public function content(ReportTable $table, User $user): ExportContent
    {
        $rows = $table->rows;
        $display = $table->displayRows();

        if ($table->totals !== null) {
            $rows[] = $table->totals;
            $display[] = $table->displayTotals();
        }

        $keys = array_column($table->columns, 'key');
        $generatedAt = CarbonImmutable::now('Asia/Manila')->format('M j, Y g:i A');
        $by = ReportNames::user($user) ?? 'Unknown';

        return new ExportContent(
            reportId: 0,
            reportType: 'amla_'.$table->report->key(),
            title: $table->report->title(),
            periodLabel: $table->periodLabel(),
            coopName: $this->coopName(),
            coopAddress: null,
            registrationNo: null,
            statusLabel: self::CONFIDENTIAL,
            stageLabel: null,
            isDraft: $table->isDraft(),
            snapshotId: null,
            snapshotVersion: null,
            checksum: null,
            templateId: null,
            templateVersion: 'AMLA reports v1',
            excelTemplateDocumentId: null,
            generatedAt: $generatedAt,
            generatedBy: $by,
            orientation: count($table->columns) > 6 ? 'landscape' : 'portrait',
            sections: [[
                'key' => $table->report->key(),
                'title' => $table->report->title(),
                'kind' => 'table',
                'fields' => [],
                'columns' => array_map(fn (array $column) => ['key' => $column['key'], 'label' => $column['label'], 'format' => $column['format']], $table->columns),
                'rows' => array_map(fn (array $row) => array_map(fn (string $key) => $row[$key] ?? '—', $keys), $display),
                'raw_rows' => array_map(fn (array $row) => array_map(fn (string $key) => $row[$key] ?? null, $keys), $rows),
            ]],
            values: [],
            types: [],
            profile: [],
            excel: [],
            footerText: $table->report->title().' · '.$table->periodLabel().' · Generated '.$generatedAt.' (Manila) by '.$by.' · '.self::CONFIDENTIAL,
        );
    }

    private function csv(ReportTable $table, User $user): string
    {
        $lines = [];

        if ($table->isDraft()) {
            $lines[] = [ExportContent::DRAFT_MARK];
        }

        $lines[] = [$this->coopName()];
        $lines[] = [$table->report->title()];
        $lines[] = [$table->periodLabel().' · '.self::CONFIDENTIAL];
        $lines[] = array_column($table->columns, 'label');

        foreach ($table->displayRows() as $row) {
            $lines[] = array_values($row);
        }

        if ($table->totals !== null) {
            $lines[] = array_values((array) $table->displayTotals());
        }

        $lines[] = ['Generated '.CarbonImmutable::now('Asia/Manila')->format('M j, Y g:i A').' (Manila) by '.(ReportNames::user($user) ?? 'Unknown')];

        return "\u{FEFF}".implode("\r\n", array_map(fn (array $cells) => implode(',', array_map(fn ($cell) => $this->cell((string) $cell), $cells)), $lines))."\r\n";
    }

    private function cell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "'".$value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }

    private function coopName(): string
    {
        $name = BrandingSetting::query()->first()?->organization_name;

        return filled($name) ? (string) $name : (string) config('app.name');
    }
}

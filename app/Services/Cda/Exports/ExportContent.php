<?php

namespace App\Services\Cda\Exports;

/**
 * What one export shows (CDA design §9), the same for PDF and Excel: the
 * report's header, its sections with display values, the footer facts and the
 * DRAFT mark. Built by ExportContentBuilder from live values (drafts) or the
 * snapshot (finalized and later).
 *
 * `sections`: each `{key, title, kind: fields|table, fields: [{key, label,
 * type, display, raw}], columns: [{key, label, format}], rows: [[display…]],
 * raw_rows: [[raw…]]}`. `values` holds the raw value of every field key for
 * the official workbook's cell map, `types` each field key's template type
 * (money, percent, number, text, …, table), and `profile` the stored CDA
 * profile fields.
 */
final class ExportContent
{
    public const DRAFT_MARK = 'DRAFT — not for submission';

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $types
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $excel  the template's `export.excel` block
     */
    public function __construct(
        public readonly int $reportId,
        public readonly string $reportType,
        public readonly string $title,
        public readonly string $periodLabel,
        public readonly string $coopName,
        public readonly ?string $coopAddress,
        public readonly ?string $registrationNo,
        public readonly string $statusLabel,
        public readonly bool $isDraft,
        public readonly ?int $snapshotId,
        public readonly ?int $snapshotVersion,
        public readonly ?string $checksum,
        public readonly ?int $templateId,
        public readonly string $templateVersion,
        public readonly ?int $excelTemplateDocumentId,
        public readonly string $generatedAt,
        public readonly string $generatedBy,
        public readonly string $orientation,
        public readonly array $sections,
        public readonly array $values,
        public readonly array $types,
        public readonly array $profile,
        public readonly array $excel,
    ) {}

    /** The footer's facts, one line ("Page X of Y" is added per page by the renderer). */
    public function footer(): string
    {
        $parts = [
            $this->title.' · '.$this->periodLabel,
            'Generated '.$this->generatedAt.' (Manila) by '.$this->generatedBy,
            'Template '.$this->templateVersion,
        ];

        $parts[] = $this->snapshotVersion === null
            ? 'Not finalized'
            : 'Snapshot v'.$this->snapshotVersion.' · checksum '.$this->checksum;

        return implode(' · ', $parts);
    }
}

<?php

namespace App\Services\Portal\Pdf;

/**
 * One printed portal document (a statement or a certificate), as plain
 * values the view prints escaped: a heading, the member's lines, then
 * sections of label-value fields, tables or paragraphs.
 *
 * A section is one of:
 * - `['type' => 'fields', 'heading' => ?string, 'fields' => list<array{0: string, 1: string|null}>]`
 * - `['type' => 'table', 'heading' => ?string, 'columns' => list<string>, 'numeric' => list<int>, 'rows' => list<list<string|null>>, 'totals' => list<string|null>|null, 'empty' => string]`
 * - `['type' => 'text', 'heading' => ?string, 'paragraphs' => list<string>]`
 */
final readonly class PortalDocument
{
    /**
     * @param  list<array{0: string, 1: string|null}>  $memberLines
     * @param  list<array<string, mixed>>  $sections
     */
    public function __construct(
        public string $title,
        public string $subtitle,
        public string $coopName,
        public ?string $coopAddress,
        public array $memberLines,
        public array $sections,
        public string $footer,
        public string $fileName,
        public string $orientation = 'portrait',
    ) {}
}

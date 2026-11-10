<?php

namespace App\Services\Cda\Exports;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Fills the official workbook a template carries (CDA design §9,
 * `export.excel.mode: template`): each mapped cell ("Sheet1!C5") gets its
 * value, everything else in the workbook is kept as uploaded.
 *
 * - A cell map value is `profile.<field>` (the CDA profile) or a report
 *   field reference ("section.field", or a field key only one section has).
 *   A table reference can't fill one cell and is left alone.
 * - Text goes in as an inline string (never a formula); a whole or decimal
 *   number as a number. An existing cell keeps its style.
 * - `calcChain.xml` is dropped (with its content type and relationship) and
 *   the workbook is set to recalculate on open (`fullCalcOnLoad`), so Excel
 *   rebuilds its formulas around the new values.
 * - A draft writes the DRAFT mark into `draft_cell`.
 */
final class XlsxTemplateFiller
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const PKG_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** Elements that come after calcPr in a workbook, in schema order. */
    private const AFTER_CALC_PR = ['oleSize', 'customWorkbookViews', 'pivotCaches', 'smartTagPr', 'smartTagTypes', 'webPublishing', 'fileRecoveryPr', 'webPublishObjects', 'extLst'];

    public function fill(string $workbook, ExportContent $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cda-fill');

        if ($path === false || file_put_contents($path, $workbook) === false) {
            throw new RuntimeException('The official workbook could not be read.');
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($path) !== true) {
                abort(422, 'The official workbook attached to this template is not a valid .xlsx file. A super admin can attach it again in CDA Settings.');
            }

            $sheets = $this->sheetPaths($zip);
            $cells = $this->cellValues($content);

            if ($content->isDraft && is_string($content->excel['draft_cell'] ?? null)) {
                $cells[$content->excel['draft_cell']] = ExportContent::DRAFT_MARK;
            }

            $bySheet = [];

            foreach ($cells as $address => $value) {
                [$sheet, $ref] = explode('!', (string) $address, 2);
                $bySheet[$sheet][strtoupper($ref)] = $value;
            }

            foreach ($bySheet as $sheet => $values) {
                $part = $sheets[$sheet] ?? null;

                if ($part === null) {
                    continue;
                }

                $zip->addFromString($part, $this->fillSheet((string) $zip->getFromName($part), $values));
            }

            $this->dropCalcChain($zip);
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The value of each mapped cell, null for a reference with no value.
     *
     * @return array<string, mixed>
     */
    private function cellValues(ExportContent $content): array
    {
        $out = [];

        foreach ((array) ($content->excel['cells'] ?? []) as $address => $reference) {
            $reference = (string) $reference;
            $value = str_starts_with($reference, 'profile.')
                ? ($content->profile[substr($reference, 8)] ?? null)
                : $this->fieldValue($content->values, $reference);

            if (! is_array($value)) {
                $out[(string) $address] = $value;
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $values */
    private function fieldValue(array $values, string $reference): mixed
    {
        if (array_key_exists($reference, $values)) {
            return $values[$reference];
        }

        $matches = array_filter(array_keys($values), fn (string $key) => str_ends_with($key, '.'.$reference));

        return count($matches) === 1 ? $values[array_values($matches)[0]] : null;
    }

    /**
     * Sheet name → its part path, through the workbook's relationships.
     *
     * @return array<string, string>
     */
    private function sheetPaths(ZipArchive $zip): array
    {
        $workbook = $this->dom((string) $zip->getFromName('xl/workbook.xml'));
        $rels = $this->dom((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        $targets = [];

        foreach ($rels->getElementsByTagNameNS(self::PKG_REL_NS, 'Relationship') as $rel) {
            $target = ltrim((string) $rel->getAttribute('Target'), '/');
            $targets[$rel->getAttribute('Id')] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        $paths = [];

        foreach ($workbook->getElementsByTagNameNS(self::NS, 'sheet') as $sheet) {
            $id = $sheet->getAttributeNS(self::REL_NS, 'id');

            if (isset($targets[$id])) {
                $paths[$sheet->getAttribute('name')] = $targets[$id];
            }
        }

        return $paths;
    }

    /** @param  array<string, mixed>  $values  cell ref → value */
    private function fillSheet(string $xml, array $values): string
    {
        $dom = $this->dom($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('s', self::NS);
        $sheetData = $xpath->query('//s:sheetData')->item(0);

        if (! $sheetData instanceof DOMElement) {
            return $xml;
        }

        foreach ($values as $ref => $value) {
            if (preg_match('/^([A-Z]{1,3})(\d+)$/', $ref, $m) !== 1) {
                continue;
            }

            $cell = $this->cell($dom, $sheetData, $m[1], (int) $m[2]);
            $this->setValue($dom, $cell, $value);
        }

        return (string) $dom->saveXML();
    }

    private function cell(DOMDocument $dom, DOMElement $sheetData, string $column, int $rowNumber): DOMElement
    {
        $row = null;

        foreach ($sheetData->childNodes as $candidate) {
            if (! $candidate instanceof DOMElement || $candidate->localName !== 'row') {
                continue;
            }

            $number = (int) $candidate->getAttribute('r');

            if ($number === $rowNumber) {
                $row = $candidate;

                break;
            }

            if ($number > $rowNumber) {
                $row = $sheetData->insertBefore($this->element($dom, 'row', ['r' => (string) $rowNumber]), $candidate);

                break;
            }
        }

        $row ??= $sheetData->appendChild($this->element($dom, 'row', ['r' => (string) $rowNumber]));
        $ref = $column.$rowNumber;
        $index = self::columnIndex($column);

        foreach ($row->childNodes as $candidate) {
            if (! $candidate instanceof DOMElement || $candidate->localName !== 'c') {
                continue;
            }

            preg_match('/^([A-Z]+)/', $candidate->getAttribute('r'), $m);
            $existing = self::columnIndex($m[1] ?? 'A');

            if ($existing === $index) {
                return $candidate;
            }

            if ($existing > $index) {
                return $row->insertBefore($this->element($dom, 'c', ['r' => $ref]), $candidate);
            }
        }

        return $row->appendChild($this->element($dom, 'c', ['r' => $ref]));
    }

    private function setValue(DOMDocument $dom, DOMElement $cell, mixed $value): void
    {
        while ($cell->firstChild !== null) {
            $cell->removeChild($cell->firstChild);
        }

        $cell->removeAttribute('t');

        if ($value === null || $value === '') {
            return;
        }

        if (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        }

        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1)) {
            $cell->appendChild($this->element($dom, 'v', [], (string) $value));

            return;
        }

        $cell->setAttribute('t', 'inlineStr');
        $text = $this->element($dom, 't', [], (string) $value);
        $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $cell->appendChild($this->element($dom, 'is'))->appendChild($text);
    }

    private function dropCalcChain(ZipArchive $zip): void
    {
        $zip->deleteName('xl/calcChain.xml');

        $types = $this->dom((string) $zip->getFromName('[Content_Types].xml'));
        foreach (iterator_to_array($types->getElementsByTagName('Override')) as $override) {
            if (str_ends_with((string) $override->getAttribute('PartName'), '/calcChain.xml')) {
                $override->parentNode?->removeChild($override);
            }
        }
        $zip->addFromString('[Content_Types].xml', (string) $types->saveXML());

        $rels = $this->dom((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        foreach (iterator_to_array($rels->getElementsByTagNameNS(self::PKG_REL_NS, 'Relationship')) as $rel) {
            if (str_ends_with((string) $rel->getAttribute('Type'), '/calcChain')) {
                $rel->parentNode?->removeChild($rel);
            }
        }
        $zip->addFromString('xl/_rels/workbook.xml.rels', (string) $rels->saveXML());

        $workbook = $this->dom((string) $zip->getFromName('xl/workbook.xml'));
        $root = $workbook->documentElement;
        $calcPr = $workbook->getElementsByTagNameNS(self::NS, 'calcPr')->item(0);

        if (! $calcPr instanceof DOMElement && $root !== null) {
            $calcPr = $this->element($workbook, 'calcPr');
            $before = null;

            foreach ($root->childNodes as $child) {
                if ($child instanceof DOMElement && in_array($child->localName, self::AFTER_CALC_PR, true)) {
                    $before = $child;

                    break;
                }
            }

            $before === null ? $root->appendChild($calcPr) : $root->insertBefore($calcPr, $before);
        }

        $calcPr?->setAttribute('fullCalcOnLoad', '1');
        $zip->addFromString('xl/workbook.xml', (string) $workbook->saveXML());
    }

    /** @param  array<string, string>  $attributes */
    private function element(DOMDocument $dom, string $name, array $attributes = [], ?string $text = null): DOMElement
    {
        $element = $dom->createElementNS(self::NS, $name);

        foreach ($attributes as $attribute => $value) {
            $element->setAttribute($attribute, $value);
        }

        if ($text !== null) {
            $element->appendChild($dom->createTextNode($text));
        }

        return $element;
    }

    private function dom(string $xml): DOMDocument
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;

        if ($xml === '' || ! @$dom->loadXML($xml, LIBXML_NONET)) {
            abort(422, 'The official workbook attached to this template is not a valid .xlsx file. A super admin can attach it again in CDA Settings.');
        }

        $dom->encoding = 'UTF-8';

        return $dom;
    }

    /** A → 1, Z → 26, AA → 27. */
    private static function columnIndex(string $column): int
    {
        $index = 0;

        foreach (str_split($column) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index;
    }
}

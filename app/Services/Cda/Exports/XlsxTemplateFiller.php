<?php

namespace App\Services\Cda\Exports;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Fills the official workbook a template carries (CDA design §9,
 * `export.excel.mode: template`): each mapped cell ("Sheet1!C5", "Sheet1!$C$5"
 * or "'Annex A'!C5") gets its value, and everything else in the workbook is
 * kept as uploaded.
 *
 * - A cell map value is `profile.<field>` (the CDA profile, a chosen value
 *   in words) or a report field
 *   reference ("section.field", or a field key only one section has). A table
 *   reference can't fill one cell and is left alone.
 * - Only a money, percent or number field with a numeric value becomes a
 *   number (negative money stays signed). Profile values and every other
 *   field are inline strings, never formulas, so "00123" keeps its zeros.
 *   An existing cell keeps its style. Characters XML can't hold are dropped.
 * - A draft writes the DRAFT mark into `draft_cell`; a draft export is
 *   refused (422) when the template has no such cell on a sheet that exists.
 * - Mapped sheets the workbook doesn't have are skipped and named in the
 *   returned notes.
 * - `calcChain.xml` is dropped (with its content type and relationship) and
 *   the workbook is set to recalculate on open (`fullCalcOnLoad`).
 * - Refused in words (422): a part that isn't XML, holds a DOCTYPE, or
 *   unpacks to more than MAX_PART_BYTES, and rows or cells with no `r`.
 */
final class XlsxTemplateFiller
{
    public const MAX_PART_BYTES = 50 * 1024 * 1024;

    public const NUMERIC_TYPES = ['money', 'percent', 'number'];

    public const NO_DRAFT_CELL = "This template's official workbook has no cell for the DRAFT mark, so a draft can't be exported from it. A super admin can set export.excel.draft_cell in a new template version; a finalized report exports without it.";

    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const PKG_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** Sheet!Cell, the sheet name optionally quoted, the cell optionally absolute. */
    private const ADDRESS = "/^(?:'((?:[^']|'')+)'|([^!']+))!\\$?([A-Za-z]{1,3})\\$?(\\d{1,7})$/";

    private const INVALID = 'The official workbook attached to this template is not a valid .xlsx file. A super admin can attach it again to a new template version.';

    private const DOCTYPE = 'The official workbook attached to this template holds a document type declaration, which exports refuse. A super admin can save it again in Excel and attach it to a new template version.';

    private const TOO_LARGE = 'The official workbook attached to this template is too large to fill (a part is over 50 MB unpacked). A super admin can attach a smaller workbook to a new template version.';

    private const NO_REFERENCE = 'The official workbook attached to this template has rows or cells without a reference (r), so its cells can\'t be found. A super admin can save it again in Excel and attach it to a new template version.';

    /** Elements that come after calcPr in a workbook, in schema order. */
    private const AFTER_CALC_PR = ['oleSize', 'customWorkbookViews', 'pivotCaches', 'smartTagPr', 'smartTagTypes', 'webPublishing', 'fileRecoveryPr', 'webPublishObjects', 'extLst'];

    public function __construct(private readonly int $maxPartBytes = self::MAX_PART_BYTES) {}

    /** @return array{0: string, 1: string|null} the filled workbook and a note about what was skipped */
    public function fill(string $workbook, ExportContent $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'cda-fill');

        if ($path === false || file_put_contents($path, $workbook) === false) {
            throw new RuntimeException('The official workbook could not be read.');
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($path) !== true) {
                abort(422, self::INVALID);
            }

            $sheets = $this->sheetPaths($zip);
            $bySheet = [];
            $missing = [];

            foreach ($this->cellValues($content) as $address => [$value, $numeric]) {
                $parsed = self::address($address);

                if ($parsed === null) {
                    continue;
                }

                if (! isset($sheets[$parsed[0]])) {
                    $missing[$parsed[0]] = true;

                    continue;
                }

                $bySheet[$parsed[0]][$parsed[1]] = [$value, $numeric];
            }

            if ($content->isDraft) {
                $draft = self::address((string) ($content->excel['draft_cell'] ?? ''));
                abort_if($draft === null || ! isset($sheets[$draft[0]]), 422, self::NO_DRAFT_CELL);
                $bySheet[$draft[0]][$draft[1]] = [ExportContent::DRAFT_MARK, false];
            }

            foreach ($bySheet as $sheet => $values) {
                $part = $sheets[$sheet];
                $zip->addFromString($part, $this->fillSheet($this->part($zip, $part), $values));
            }

            $this->dropCalcChain($zip);
            $zip->close();

            $names = array_keys($missing);
            sort($names);

            return [
                (string) file_get_contents($path),
                $names === [] ? null : 'These mapped sheets are not in the workbook, so their cells were left empty: '.implode(', ', $names).'.',
            ];
        } finally {
            @unlink($path);
        }
    }

    /**
     * "Sheet1!$C$5" → ["Sheet1", "C5"]; null when it isn't a cell address.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function address(string $address): ?array
    {
        if (preg_match(self::ADDRESS, trim($address), $m) !== 1) {
            return null;
        }

        $sheet = $m[1] !== '' ? str_replace("''", "'", $m[1]) : $m[2];

        return [$sheet, strtoupper($m[3]).$m[4]];
    }

    /**
     * The value of each mapped cell and whether it goes in as a number.
     *
     * @return array<string, array{0: mixed, 1: bool}>
     */
    private function cellValues(ExportContent $content): array
    {
        $out = [];

        foreach ((array) ($content->excel['cells'] ?? []) as $address => $reference) {
            $reference = (string) $reference;

            if (str_starts_with($reference, 'profile.')) {
                $value = $content->profileValue(substr($reference, 8));
                $out[(string) $address] = [is_array($value) ? null : $value, false];

                continue;
            }

            $key = $this->fieldKey($content->values + $content->types, $reference);
            $value = $key === null ? null : ($content->values[$key] ?? null);

            if (is_array($value)) {
                continue;
            }

            $out[(string) $address] = [$value, $key !== null && in_array($content->types[$key] ?? null, self::NUMERIC_TYPES, true)];
        }

        return $out;
    }

    /**
     * The field key a reference names: itself, or the one key ending in
     * ".reference".
     *
     * @param  array<string, mixed>  $known
     */
    private function fieldKey(array $known, string $reference): ?string
    {
        if (array_key_exists($reference, $known)) {
            return $reference;
        }

        $matches = array_values(array_filter(array_keys($known), fn (string $key) => str_ends_with($key, '.'.$reference)));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Sheet name → its part path, through the workbook's relationships.
     *
     * @return array<string, string>
     */
    private function sheetPaths(ZipArchive $zip): array
    {
        $workbook = $this->dom($this->part($zip, 'xl/workbook.xml'));
        $rels = $this->dom($this->part($zip, 'xl/_rels/workbook.xml.rels'));
        $targets = [];

        foreach ($rels->getElementsByTagNameNS(self::PKG_REL_NS, 'Relationship') as $rel) {
            $target = ltrim((string) $rel->getAttribute('Target'), '/');
            $targets[$rel->getAttribute('Id')] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        $paths = [];

        foreach ($workbook->getElementsByTagNameNS(self::NS, 'sheet') as $sheet) {
            $id = $sheet->getAttributeNS(self::REL_NS, 'id');

            if (isset($targets[$id]) && $zip->locateName($targets[$id]) !== false) {
                $paths[$sheet->getAttribute('name')] = $targets[$id];
            }
        }

        return $paths;
    }

    /** A part's XML, refused when it unpacks to more than the cap. */
    private function part(ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);

        if ($stat === false) {
            abort(422, self::INVALID);
        }

        abort_if((int) $stat['size'] > $this->maxPartBytes, 422, self::TOO_LARGE);

        return (string) $zip->getFromName($name);
    }

    /** @param  array<string, array{0: mixed, 1: bool}>  $values  cell ref → [value, numeric] */
    private function fillSheet(string $xml, array $values): string
    {
        $dom = $this->dom($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('s', self::NS);
        $sheetData = $xpath->query('//s:sheetData')->item(0);

        if (! $sheetData instanceof DOMElement) {
            abort(422, self::INVALID);
        }

        abort_if($xpath->query('s:row[not(@r)] | s:row/s:c[not(@r)]', $sheetData)->length > 0, 422, self::NO_REFERENCE);

        foreach ($values as $ref => [$value, $numeric]) {
            preg_match('/^([A-Z]{1,3})(\d+)$/', $ref, $m);
            $this->setValue($dom, $this->cell($dom, $sheetData, $m[1], (int) $m[2]), $value, $numeric);
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

            preg_match('/^([A-Z]+)/', strtoupper($candidate->getAttribute('r')), $m);
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

    private function setValue(DOMDocument $dom, DOMElement $cell, mixed $value, bool $numeric): void
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

        $text = XmlText::clean((string) $value);

        if ($numeric && preg_match('/^-?\d+(\.\d+)?$/', $text) === 1) {
            $cell->appendChild($this->element($dom, 'v', [], $text));

            return;
        }

        $cell->setAttribute('t', 'inlineStr');
        $node = $this->element($dom, 't', [], $text);
        $node->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $cell->appendChild($this->element($dom, 'is'))->appendChild($node);
    }

    private function dropCalcChain(ZipArchive $zip): void
    {
        $zip->deleteName('xl/calcChain.xml');

        $types = $this->dom($this->part($zip, '[Content_Types].xml'));
        foreach (iterator_to_array($types->getElementsByTagName('Override')) as $override) {
            if (str_ends_with((string) $override->getAttribute('PartName'), '/calcChain.xml')) {
                $override->parentNode?->removeChild($override);
            }
        }
        $zip->addFromString('[Content_Types].xml', (string) $types->saveXML());

        $rels = $this->dom($this->part($zip, 'xl/_rels/workbook.xml.rels'));
        foreach (iterator_to_array($rels->getElementsByTagNameNS(self::PKG_REL_NS, 'Relationship')) as $rel) {
            if (str_ends_with((string) $rel->getAttribute('Type'), '/calcChain')) {
                $rel->parentNode?->removeChild($rel);
            }
        }
        $zip->addFromString('xl/_rels/workbook.xml.rels', (string) $rels->saveXML());

        $workbook = $this->dom($this->part($zip, 'xl/workbook.xml'));
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

    /**
     * The part as a DOM, refusing a DOCTYPE: first by its bytes, then on the
     * parsed document, so one in another encoding (UTF-16) is refused too.
     * Entities are never substituted and nothing is loaded from outside.
     */
    private function dom(string $xml): DOMDocument
    {
        abort_if(preg_match('/<!DOCTYPE/i', $xml) === 1, 422, self::DOCTYPE);

        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;

        if ($xml === '' || ! @$dom->loadXML($xml, LIBXML_NONET)) {
            abort(422, self::INVALID);
        }

        abort_if($dom->doctype !== null, 422, self::DOCTYPE);

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

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
 * - An unverified template (ExportContent::$unverified: `meta.verified`, or
 *   PesosSource::verified() for PESOS, the other exports' rule) gets a
 *   separate "Notes" sheet at the end ("Notes (2)" when the form already has
 *   one) whose first line says so, listed in `docProps/app.xml` when the
 *   workbook has one, and the document properties' Subject reads "To
 *   verify". The official form's own sheets are never touched for
 *   it.
 * - Refused in words (422): a part that isn't XML, holds a DOCTYPE, or
 *   unpacks to more than MAX_PART_BYTES, and rows or cells with no `r`.
 */
final class XlsxTemplateFiller
{
    public const MAX_PART_BYTES = 50 * 1024 * 1024;

    public const NUMERIC_TYPES = ['money', 'percent', 'number'];

    public const NO_DRAFT_CELL = "This template's official workbook has no cell for the DRAFT mark, so a draft can't be exported from it. A super admin can set export.excel.draft_cell in a new template version; a finalized report exports without it.";

    /** The sheet an unverified template's export adds at the end. */
    public const NOTES_SHEET = 'Notes';

    /** The Notes sheet's first line. */
    public const NOT_VERIFIED_LINE = 'Template not yet verified against the official CDA form';

    /** The document properties' Subject on an unverified template's export. */
    public const TO_VERIFY_SUBJECT = 'To verify';

    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const CONTENT_TYPES_NS = 'http://schemas.openxmlformats.org/package/2006/content-types';

    private const CORE_NS = 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties';

    private const DC_NS = 'http://purl.org/dc/elements/1.1/';

    private const WORKSHEET_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml';

    private const WORKSHEET_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet';

    private const CORE_TYPE = 'application/vnd.openxmlformats-package.core-properties+xml';

    private const CORE_REL = 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties';

    private const APP_PART = 'docProps/app.xml';

    private const APP_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/extended-properties';

    private const VT_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes';

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

            if ($content->unverified) {
                // libzip can't read back a part changed in the same session,
                // so each step that reads what the last one wrote reopens it.
                $this->reopen($zip, $path);
                $this->addNotesSheet($zip);
                $zip->close();

                $this->reopen($zip, $path);
                $this->markToVerify($zip);
                $zip->close();
            }

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

    /**
     * Appends the Notes sheet: its own part, relationship, content type and
     * place last in the workbook. Existing sheets, their ids and order stay.
     */
    private function addNotesSheet(ZipArchive $zip): void
    {
        $workbook = $this->dom($this->part($zip, 'xl/workbook.xml'));
        $sheetsElement = $workbook->getElementsByTagNameNS(self::NS, 'sheets')->item(0);

        if (! $sheetsElement instanceof DOMElement) {
            abort(422, self::INVALID);
        }

        $names = [];
        $maxSheetId = 0;

        foreach ($workbook->getElementsByTagNameNS(self::NS, 'sheet') as $sheet) {
            $names[] = mb_strtolower($sheet->getAttribute('name'));
            $maxSheetId = max($maxSheetId, (int) $sheet->getAttribute('sheetId'));
        }

        $name = self::NOTES_SHEET;

        for ($n = 2; in_array(mb_strtolower($name), $names, true); $n++) {
            $name = self::NOTES_SHEET." ({$n})";
        }

        $n = 1;

        while ($zip->locateName("xl/worksheets/sheet{$n}.xml") !== false) {
            $n++;
        }

        $part = "xl/worksheets/sheet{$n}.xml";
        $zip->addFromString($part, self::notesSheetXml());

        $rels = $this->dom($this->part($zip, 'xl/_rels/workbook.xml.rels'));
        $relId = $this->addRelationship($rels, self::WORKSHEET_REL, "worksheets/sheet{$n}.xml");
        $zip->addFromString('xl/_rels/workbook.xml.rels', (string) $rels->saveXML());

        $sheet = $this->element($workbook, 'sheet', ['name' => $name, 'sheetId' => (string) ($maxSheetId + 1)]);
        $sheet->setAttributeNS(self::REL_NS, 'r:id', $relId);
        $sheetsElement->appendChild($sheet);
        $zip->addFromString('xl/workbook.xml', (string) $workbook->saveXML());

        $this->addContentType($zip, '/'.$part, self::WORKSHEET_TYPE);
        $this->listInAppProperties($zip, $name);
    }

    /**
     * Adds the sheet to the extended properties' sheet list
     * (`docProps/app.xml`), when the workbook has one: one more in the
     * "Worksheets" heading pair (the only pair, when it goes by another
     * name), and its title after the last worksheet title, before any named
     * ranges. Left as it is when the worksheets' pair can't be told apart.
     */
    private function listInAppProperties(ZipArchive $zip, string $name): void
    {
        if ($zip->locateName(self::APP_PART) === false) {
            return;
        }

        $app = $this->dom($this->part($zip, self::APP_PART));
        $pairs = $app->getElementsByTagNameNS(self::APP_NS, 'HeadingPairs')->item(0);
        $titles = $app->getElementsByTagNameNS(self::APP_NS, 'TitlesOfParts')->item(0);
        $pairVector = $pairs?->getElementsByTagNameNS(self::VT_NS, 'vector')->item(0);
        $titleVector = $titles?->getElementsByTagNameNS(self::VT_NS, 'vector')->item(0);

        if (! $pairVector instanceof DOMElement || ! $titleVector instanceof DOMElement) {
            return;
        }

        $variants = array_values(array_filter(iterator_to_array($pairVector->childNodes), fn ($node): bool => $node instanceof DOMElement && $node->localName === 'variant'));
        $groups = [];

        for ($i = 0; $i + 1 < count($variants); $i += 2) {
            $count = $variants[$i + 1]->getElementsByTagNameNS(self::VT_NS, 'i4')->item(0);

            if (! $count instanceof DOMElement) {
                return;
            }

            $groups[] = ['name' => trim((string) $variants[$i]->textContent), 'count' => $count];
        }

        $sheetGroups = array_keys(array_filter($groups, fn (array $group): bool => in_array($group['name'], ['Worksheets', 'Sheets'], true)));
        $index = count($sheetGroups) === 1 ? $sheetGroups[0] : (count($groups) === 1 ? 0 : null);

        if ($index === null) {
            return;
        }

        $offset = 0;

        foreach (array_slice($groups, 0, $index + 1) as $group) {
            $offset += (int) $group['count']->textContent;
        }

        $titleNodes = array_values(array_filter(iterator_to_array($titleVector->childNodes), fn ($node): bool => $node instanceof DOMElement && $node->localName === 'lpstr'));
        $title = $app->createElementNS(self::VT_NS, 'vt:lpstr');
        $title->appendChild($app->createTextNode($name));
        isset($titleNodes[$offset]) ? $titleVector->insertBefore($title, $titleNodes[$offset]) : $titleVector->appendChild($title);
        $titleVector->setAttribute('size', (string) (count($titleNodes) + 1));
        $groups[$index]['count']->textContent = (string) ((int) $groups[$index]['count']->textContent + 1);

        $zip->addFromString(self::APP_PART, (string) $app->saveXML());
    }

    private function reopen(ZipArchive $zip, string $path): void
    {
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The filled workbook could not be reopened.');
        }
    }

    /** The Notes sheet: the "not yet verified" line, then the exports' own note. */
    private static function notesSheetXml(): string
    {
        $rows = '';

        foreach ([self::NOT_VERIFIED_LINE, ExportContent::UNVERIFIED_NOTE] as $i => $line) {
            $r = $i + 1;
            $rows .= "<row r=\"{$r}\"><c r=\"A{$r}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">"
                .htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c></row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<worksheet xmlns="'.self::NS.'"><sheetData>'.$rows.'</sheetData></worksheet>';
    }

    /**
     * Sets the document properties' Subject to "To verify", in the workbook's
     * own core properties part, or in a new one when it has none.
     */
    private function markToVerify(ZipArchive $zip): void
    {
        $rels = $this->dom($this->part($zip, '_rels/.rels'));
        $target = null;

        foreach ($rels->getElementsByTagNameNS(self::PKG_REL_NS, 'Relationship') as $rel) {
            if ($rel->getAttribute('Type') === self::CORE_REL) {
                $target = ltrim((string) $rel->getAttribute('Target'), '/');
            }
        }

        $target ??= 'docProps/core.xml';

        if ($zip->locateName($target) !== false) {
            $core = $this->dom($this->part($zip, $target));
        } else {
            $core = $this->dom('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<cp:coreProperties xmlns:cp="'.self::CORE_NS.'" xmlns:dc="'.self::DC_NS.'"/>');
            $this->addContentType($zip, '/'.$target, self::CORE_TYPE);
        }

        if (! $this->hasRelationship($rels, self::CORE_REL)) {
            $this->addRelationship($rels, self::CORE_REL, $target);
            $zip->addFromString('_rels/.rels', (string) $rels->saveXML());
        }

        $root = $core->documentElement;

        if ($root === null) {
            abort(422, self::INVALID);
        }

        $subject = $core->getElementsByTagNameNS(self::DC_NS, 'subject')->item(0);

        if (! $subject instanceof DOMElement) {
            $subject = $root->appendChild($core->createElementNS(self::DC_NS, 'dc:subject'));
        }

        $subject->textContent = self::TO_VERIFY_SUBJECT;
        $zip->addFromString($target, (string) $core->saveXML());
    }

    private function hasRelationship(DOMDocument $rels, string $type): bool
    {
        foreach ($rels->getElementsByTagNameNS(self::PKG_REL_NS, 'Relationship') as $rel) {
            if ($rel->getAttribute('Type') === $type) {
                return true;
            }
        }

        return false;
    }

    /** Adds a relationship under an unused id and returns the id. */
    private function addRelationship(DOMDocument $rels, string $type, string $target): string
    {
        $ids = [];

        foreach ($rels->getElementsByTagNameNS(self::PKG_REL_NS, 'Relationship') as $rel) {
            $ids[] = $rel->getAttribute('Id');
        }

        $n = count($ids) + 1;

        while (in_array("rId{$n}", $ids, true)) {
            $n++;
        }

        $root = $rels->documentElement;

        if ($root === null) {
            abort(422, self::INVALID);
        }

        $rel = $rels->createElementNS(self::PKG_REL_NS, 'Relationship');
        $rel->setAttribute('Id', "rId{$n}");
        $rel->setAttribute('Type', $type);
        $rel->setAttribute('Target', $target);
        $root->appendChild($rel);

        return "rId{$n}";
    }

    private function addContentType(ZipArchive $zip, string $partName, string $contentType): void
    {
        $types = $this->dom($this->part($zip, '[Content_Types].xml'));
        $root = $types->documentElement;

        if ($root === null) {
            abort(422, self::INVALID);
        }

        $override = $types->createElementNS(self::CONTENT_TYPES_NS, 'Override');
        $override->setAttribute('PartName', $partName);
        $override->setAttribute('ContentType', $contentType);
        $root->appendChild($override);
        $zip->addFromString('[Content_Types].xml', (string) $types->saveXML());
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

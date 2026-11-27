<?php

namespace App\Services\Cda\Exports;

use RuntimeException;
use XMLWriter;
use ZipArchive;

/**
 * A CDA report as a generated .xlsx workbook (CDA design §9), written with
 * ZipArchive and XMLWriter, no library: one sheet with the header, each
 * section (fields as label and value rows, tables with their columns) and the
 * footer facts, plus a printed footer with "Page X of Y" on every page.
 *
 * Text is always an inline string, never a formula, so a value such as
 * "=HYPERLINK(…)" stays text, and characters XML can't hold are dropped
 * (XmlText). Money, percents and counts are numbers with a ₱, % or plain
 * format; negative money is a signed number whose format shows it in
 * parentheses, "(₱400.00)", and as text it reads the same. Drafts carry the
 * DRAFT mark in A1, and an unverified template's export the "to verify" note
 * under the title.
 */
final class XlsxWriter
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const STYLE_MONEY = 1;

    private const STYLE_PERCENT = 2;

    private const STYLE_BOLD = 3;

    /** @var list<list<array{value: mixed, type: string, style?: int}>> */
    private array $rows = [];

    public function write(ExportContent $content): string
    {
        $this->rows = [];

        if ($content->isDraft) {
            $this->row([$this->text(ExportContent::DRAFT_MARK, self::STYLE_BOLD)]);
        }

        $this->row([$this->text($content->coopName, self::STYLE_BOLD)]);

        if ($content->coopAddress !== null) {
            $this->row([$this->text($content->coopAddress)]);
        }

        if ($content->registrationNo !== null) {
            $this->row([$this->text('CDA Registration No.'), $this->text($content->registrationNo)]);
        }

        $this->row([$this->text($content->title, self::STYLE_BOLD)]);
        $this->row([$this->text($content->periodLabel.' · '.$content->statusLabel.($content->stageLabel === null ? '' : ' · Stage: '.$content->stageLabel))]);

        if ($content->unverified) {
            $this->row([$this->text(ExportContent::UNVERIFIED_NOTE, self::STYLE_BOLD)]);
        }

        foreach ($content->sections as $section) {
            $this->row([]);
            $this->row([$this->text($section['title'], self::STYLE_BOLD)]);

            if ($section['kind'] === 'table') {
                $this->row(array_map(fn (array $column) => $this->text($column['label'], self::STYLE_BOLD), $section['columns']));

                foreach ($section['raw_rows'] as $i => $raw) {
                    $this->row(array_map(
                        fn (array $column, int $c) => $this->cell($raw[$c] ?? null, $column['format'], $section['rows'][$i][$c] ?? ValueFormatter::EMPTY),
                        $section['columns'],
                        array_keys($section['columns']),
                    ));
                }

                continue;
            }

            foreach ($section['fields'] as $field) {
                $this->row([$this->text($field['label']), $this->cell($field['raw'], $field['type'], $field['display'])]);
            }
        }

        $this->row([]);
        $this->row([$this->text($content->footer())]);

        return $this->package($this->sheet($content));
    }

    /**
     * A value as a typed cell: a number for numeric money, percents and
     * numbers, else the display text.
     *
     * @return array{value: mixed, type: string, style?: int}
     */
    private function cell(mixed $raw, string $type, string $display): array
    {
        $numeric = is_int($raw) || (is_string($raw) && preg_match('/^-?\d+(\.\d+)?$/', $raw) === 1);

        return match (true) {
            $numeric && $type === 'money' => ['value' => (string) $raw, 'type' => 'n', 'style' => self::STYLE_MONEY],
            $numeric && $type === 'percent' => ['value' => (string) $raw, 'type' => 'n', 'style' => self::STYLE_PERCENT],
            $numeric && $type === 'number' => ['value' => (string) $raw, 'type' => 'n'],
            default => $this->text($display),
        };
    }

    /** @return array{value: string, type: string, style?: int} */
    private function text(string $value, ?int $style = null): array
    {
        $value = XmlText::clean($value);

        return $style === null ? ['value' => $value, 'type' => 's'] : ['value' => $value, 'type' => 's', 'style' => $style];
    }

    /** @param  list<array{value: mixed, type: string, style?: int}>  $cells */
    private function row(array $cells): void
    {
        $this->rows[] = $cells;
    }

    private function sheet(ExportContent $content): string
    {
        $w = new XMLWriter;
        $w->openMemory();
        $w->startDocument('1.0', 'UTF-8', 'yes');
        $w->startElement('worksheet');
        $w->writeAttribute('xmlns', self::NS);
        $w->startElement('cols');
        $w->startElement('col');
        $w->writeAttribute('min', '1');
        $w->writeAttribute('max', '1');
        $w->writeAttribute('width', '48');
        $w->writeAttribute('customWidth', '1');
        $w->endElement();
        $w->startElement('col');
        $w->writeAttribute('min', '2');
        $w->writeAttribute('max', '8');
        $w->writeAttribute('width', '22');
        $w->writeAttribute('customWidth', '1');
        $w->endElement();
        $w->endElement();
        $w->startElement('sheetData');

        foreach ($this->rows as $r => $cells) {
            $w->startElement('row');
            $w->writeAttribute('r', (string) ($r + 1));

            foreach ($cells as $c => $cell) {
                $w->startElement('c');
                $w->writeAttribute('r', self::column($c).($r + 1));

                if (isset($cell['style'])) {
                    $w->writeAttribute('s', (string) $cell['style']);
                }

                if ($cell['type'] === 'n') {
                    $w->writeElement('v', (string) $cell['value']);
                } else {
                    $w->writeAttribute('t', 'inlineStr');
                    $w->startElement('is');
                    $w->startElement('t');
                    $w->writeAttribute('xml:space', 'preserve');
                    $w->text((string) $cell['value']);
                    $w->endElement();
                    $w->endElement();
                }

                $w->endElement();
            }

            $w->endElement();
        }

        $w->endElement();
        $w->startElement('pageSetup');
        $w->writeAttribute('paperSize', '9');
        $w->writeAttribute('orientation', $content->orientation);
        $w->endElement();
        $w->startElement('headerFooter');
        $w->writeElement('oddFooter', '&L'.self::headerText(XmlText::clean(mb_substr($content->title.' · '.$content->periodLabel, 0, 120))).'&RPage &P of &N');
        $w->endElement();
        $w->endElement();
        $w->endDocument();

        return $w->outputMemory();
    }

    /** "&" starts a code in a header or footer, so a literal one is doubled. */
    private static function headerText(string $text): string
    {
        return str_replace('&', '&&', $text);
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    public static function column(int $index): string
    {
        $name = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    private function package(string $sheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cda-xlsx');
        $zip = new ZipArchive;

        if ($path === false || $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The Excel workbook could not be written.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="'.self::NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets><calcPr fullCalcOnLoad="1"/></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="'.self::NS.'">'
            .'<numFmts count="2"><numFmt numFmtId="164" formatCode="&quot;₱&quot;#,##0.00;(&quot;₱&quot;#,##0.00)"/><numFmt numFmtId="165" formatCode="0.00&quot;%&quot;"/></numFmts>'
            .'<fonts count="2"><font><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="10"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}

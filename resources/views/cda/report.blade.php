{{-- A CDA report export (CDA design §9), rendered by dompdf: no remote assets, no PHP, no JavaScript. Every value is escaped. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $content->title }} · {{ $content->periodLabel }}</title>
    <style>
        @page { margin: 22mm {{ \App\Services\Cda\Exports\PdfRenderer::MARGIN_RIGHT_MM }}mm {{ \App\Services\Cda\Exports\PdfRenderer::MARGIN_BOTTOM_MM }}mm 14mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #1f2933; }
        h1 { font-size: 13pt; margin: 0 0 2pt 0; }
        {{-- A heading is never left at the foot of a page: dompdf honours page-break-after: avoid between blocks, so it moves to the page where its content starts. --}}
        h2 { font-size: 10pt; margin: 12pt 0 4pt 0; padding-bottom: 2pt; border-bottom: 0.6pt solid #9aa5b1; page-break-after: avoid; }
        {{-- A table section's heading is the first row of the table's header group, which dompdf repeats on every page the table runs to. No break is allowed after a header row, so the heading and column headers never stay at the foot of a page without the first rows (dompdf would leave them there, unrepeated). --}}
        thead { display: table-header-group; }
        thead tr { page-break-after: avoid; }
        table.grid { margin-top: 12pt; }
        table.grid th.section-title { font-size: 10pt; padding: 0 0 2pt 0; background: none; border: 0; border-bottom: 0.6pt solid #9aa5b1; }
        table.grid tr.section-gap th { padding: 0; height: 4pt; background: none; border: 0; }
        .coop { font-size: 10pt; font-weight: bold; }
        .muted { color: #52606d; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 3pt 4pt; vertical-align: top; text-align: left; }
        table.fields td.label { width: 48%; color: #3e4c59; }
        table.fields tr { border-bottom: 0.4pt solid #e4e7eb; }
        table.grid th { background: #f0f4f8; border: 0.4pt solid #cbd2d9; font-weight: bold; }
        table.grid td { border: 0.4pt solid #cbd2d9; }
        .watermark { position: fixed; top: 40%; left: 0; right: 0; text-align: center; font-size: 40pt; color: #d64545; opacity: 0.18; transform: rotate(-30deg); }
        .draft-banner { color: #d64545; font-weight: bold; margin-top: 3pt; }
        .unverified-note { color: #8d2b0b; font-weight: bold; margin-top: 3pt; }
        table.footer { position: fixed; bottom: -{{ \App\Services\Cda\Exports\PdfRenderer::FOOTER_DROP_MM }}mm; left: 0; right: 0; width: 100%; font-size: 6.5pt; color: #52606d; }
        table.footer td { padding: 0; vertical-align: top; }
        table.footer td.footer-text { padding-right: 4mm; }
        table.footer td.footer-page { width: {{ \App\Services\Cda\Exports\PdfRenderer::PAGE_COLUMN_MM }}mm; }
    </style>
</head>
<body>
@if ($content->isDraft)
    <div class="watermark">{{ \App\Services\Cda\Exports\ExportContent::DRAFT_MARK }}</div>
@endif
{{-- The footer's facts wrap in the left cell; the right cell stays empty for "Page X of Y", which PdfRenderer draws into it on every page. --}}
<table class="footer"><tr><td class="footer-text">{{ $content->footer() }}</td><td class="footer-page"></td></tr></table>

<div class="coop">{{ $content->coopName }}</div>
@if ($content->coopAddress)
    <div class="muted">{{ $content->coopAddress }}</div>
@endif
@if ($content->registrationNo)
    <div class="muted">CDA Registration No. {{ $content->registrationNo }}</div>
@endif
<h1>{{ $content->title }}</h1>
<div class="muted">{{ $content->periodLabel }} · {{ $content->statusLabel }}@if ($content->stageLabel) · Stage: {{ $content->stageLabel }}@endif</div>
@if ($content->unverified)
    <div class="unverified-note">{{ \App\Services\Cda\Exports\ExportContent::UNVERIFIED_NOTE }}</div>
@endif
@if ($content->isDraft)
    <div class="draft-banner">{{ \App\Services\Cda\Exports\ExportContent::DRAFT_MARK }}</div>
@endif

@foreach ($content->sections as $section)
    @if ($section['kind'] === 'table' && $section['rows'] !== [])
        <table class="grid">
            <thead>
            <tr><th class="section-title" colspan="{{ count($section['columns']) }}">{{ $section['title'] }}</th></tr>
            <tr class="section-gap"><th colspan="{{ count($section['columns']) }}"></th></tr>
            <tr>
                @foreach ($section['columns'] as $column)
                    <th>{{ $column['label'] }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @foreach ($section['rows'] as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <h2>{{ $section['title'] }}</h2>
        @if ($section['kind'] === 'table')
            <div class="muted">None recorded.</div>
        @else
            <table class="fields">
                @foreach ($section['fields'] as $field)
                    <tr>
                        <td class="label">{{ $field['label'] }}</td>
                        <td>{{ $field['display'] }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    @endif
@endforeach
</body>
</html>

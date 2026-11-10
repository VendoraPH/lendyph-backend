{{-- A CDA report export (CDA design §9), rendered by dompdf: no remote assets, no PHP, no JavaScript. Every value is escaped. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $content->title }} · {{ $content->periodLabel }}</title>
    <style>
        @page { margin: 22mm 14mm 20mm 14mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #1f2933; }
        h1 { font-size: 13pt; margin: 0 0 2pt 0; }
        h2 { font-size: 10pt; margin: 12pt 0 4pt 0; padding-bottom: 2pt; border-bottom: 0.6pt solid #9aa5b1; }
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
        .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 6.5pt; color: #52606d; }
    </style>
</head>
<body>
@if ($content->isDraft)
    <div class="watermark">{{ \App\Services\Cda\Exports\ExportContent::DRAFT_MARK }}</div>
@endif
<div class="footer">{{ $content->footer() }}</div>

<div class="coop">{{ $content->coopName }}</div>
@if ($content->coopAddress)
    <div class="muted">{{ $content->coopAddress }}</div>
@endif
@if ($content->registrationNo)
    <div class="muted">CDA Registration No. {{ $content->registrationNo }}</div>
@endif
<h1>{{ $content->title }}</h1>
<div class="muted">{{ $content->periodLabel }} · {{ $content->statusLabel }}</div>
@if ($content->isDraft)
    <div class="draft-banner">{{ \App\Services\Cda\Exports\ExportContent::DRAFT_MARK }}</div>
@endif

@foreach ($content->sections as $section)
    <h2>{{ $section['title'] }}</h2>
    @if ($section['kind'] === 'table')
        @if ($section['rows'] === [])
            <div class="muted">None recorded.</div>
        @else
            <table class="grid">
                <thead>
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
        @endif
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
@endforeach
</body>
</html>

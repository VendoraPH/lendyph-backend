{{-- A Member Portal statement or certificate (portal design §1), rendered by dompdf: no remote assets, no PHP, no JavaScript. Every value is escaped. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $document->title }}</title>
    <style>
        @page { margin: 18mm 14mm 20mm 14mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #1f2933; }
        h1 { font-size: 13pt; margin: 8pt 0 2pt 0; }
        h2 { font-size: 9.5pt; margin: 12pt 0 4pt 0; padding-bottom: 2pt; border-bottom: 0.6pt solid #9aa5b1; }
        .coop { font-size: 10pt; font-weight: bold; }
        .muted { color: #52606d; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 3pt 4pt; vertical-align: top; text-align: left; }
        table.fields td.label { width: 38%; color: #3e4c59; }
        table.fields tr { border-bottom: 0.4pt solid #e4e7eb; }
        table.grid th { background: #f0f4f8; border: 0.4pt solid #cbd2d9; font-weight: bold; }
        table.grid td { border: 0.4pt solid #cbd2d9; }
        table.grid .num { text-align: right; white-space: nowrap; }
        table.grid tr.totals td { font-weight: bold; background: #f7f9fb; }
        p { margin: 0 0 6pt 0; line-height: 1.45; }
        .footer { position: fixed; bottom: -12mm; left: 0; right: 42mm; font-size: 6.5pt; color: #52606d; }
    </style>
</head>
<body>
<div class="footer">{{ $document->footer }}</div>

<div class="coop">{{ $document->coopName }}</div>
@if ($document->coopAddress)
    <div class="muted">{{ $document->coopAddress }}</div>
@endif

<h1>{{ $document->title }}</h1>
<div class="muted">{{ $document->subtitle }}</div>

<table class="fields" style="margin-top: 8pt;">
    @foreach ($document->memberLines as [$label, $value])
        <tr><td class="label">{{ $label }}</td><td>{{ $value ?? '—' }}</td></tr>
    @endforeach
</table>

@foreach ($document->sections as $section)
    @if (! empty($section['heading']))
        <h2>{{ $section['heading'] }}</h2>
    @endif

    @if ($section['type'] === 'fields')
        <table class="fields">
            @foreach ($section['fields'] as [$label, $value])
                <tr><td class="label">{{ $label }}</td><td>{{ $value ?? '—' }}</td></tr>
            @endforeach
        </table>
    @elseif ($section['type'] === 'table')
        <table class="grid">
            <thead>
                <tr>
                    @foreach ($section['columns'] as $i => $column)
                        <th class="{{ in_array($i, $section['numeric'], true) ? 'num' : '' }}">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($section['rows'] as $row)
                    <tr>
                        @foreach ($row as $i => $cell)
                            <td class="{{ in_array($i, $section['numeric'], true) ? 'num' : '' }}">{{ $cell ?? '' }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($section['columns']) }}" class="muted">{{ $section['empty'] }}</td></tr>
                @endforelse
                @if (! empty($section['totals']))
                    <tr class="totals">
                        @foreach ($section['totals'] as $i => $cell)
                            <td class="{{ in_array($i, $section['numeric'], true) ? 'num' : '' }}">{{ $cell ?? '' }}</td>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>
    @else
        @foreach ($section['paragraphs'] as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach
    @endif
@endforeach
</body>
</html>

<?php

use App\Services\TimezoneShift;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

/*
 * The CIC tables came well after the one-shot UTC → Manila shift, so every
 * datetime column on them is in TimezoneShift::EXCLUDED_COLUMNS, and no DATE
 * column (the reference dates) is.
 */

it('excludes every CIC datetime column from the shift, and no date column', function () {
    $columns = DB::table('information_schema.columns')
        ->where('table_schema', DB::connection()->getDatabaseName())
        ->where('table_name', 'like', 'cic\\_%')
        ->whereIn('data_type', ['datetime', 'timestamp'])
        ->orderBy('table_name')->orderBy('column_name')
        ->get(['table_name as t', 'column_name as c']);

    $expected = [];

    foreach ($columns as $column) {
        $expected[$column->t][] = $column->c;
    }

    $listed = array_filter(TimezoneShift::EXCLUDED_COLUMNS, fn (string $table) => str_starts_with($table, 'cic_'), ARRAY_FILTER_USE_KEY);
    ksort($listed);

    expect($expected)->toHaveCount(9)
        ->and($listed)->toBe($expected)
        ->and(collect($listed)->flatten()->all())->not->toContain('reference_date')
        ->and(collect($listed)->flatten()->all())->not->toContain('month_end_key');
});

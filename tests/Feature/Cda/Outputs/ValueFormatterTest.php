<?php

namespace Tests\Feature\Cda\Outputs;

use App\Services\Cda\Exports\ValueFormatter;
use Tests\TestCase;

/**
 * How exports show values (review fixes H1 and L10): negative money in
 * parentheses, as the accounting screens show it, and anything that can't be
 * read as its type falls back to its raw text, never an error.
 */
class ValueFormatterTest extends TestCase
{
    public function test_money_shows_negatives_in_parentheses(): void
    {
        $this->assertSame('(₱400.00)', ValueFormatter::display('-400.00', 'money'));
        $this->assertSame('(₱1,500.50)', ValueFormatter::display('-1500.5', 'money'));
        $this->assertSame('(₱400.50)', ValueFormatter::display(-400.5, 'money'));
        $this->assertSame('(₱12.00)', ValueFormatter::display(-12, 'money'));
        $this->assertSame('₱1,500.00', ValueFormatter::display('1500', 'money'));
        $this->assertSame('₱0.00', ValueFormatter::display('-0.00', 'money'));
        $this->assertSame('₱0.00', ValueFormatter::display(0, 'money'));
        $this->assertSame('n/a', ValueFormatter::display('n/a', 'money'));
    }

    public function test_bad_dates_and_odd_compliance_values_fall_back_to_raw_text(): void
    {
        $this->assertSame('2026-13-45', ValueFormatter::display('2026-13-45', 'date'));
        $this->assertSame('Dec 31, 2026', ValueFormatter::display('2026-12-31', 'date'));
        $this->assertSame('Compliant (["minutes","photos"])', ValueFormatter::display(['rating' => 'compliant', 'remarks' => ['minutes', 'photos']], 'compliance'));
        $this->assertSame('{"x":1}', ValueFormatter::display(['rating' => ['x' => 1]], 'compliance'));
        $this->assertSame('Partly compliant (Late)', ValueFormatter::display(['rating' => 'partial', 'remarks' => 'Late'], 'compliance'));
    }
}

<?php

namespace Tests\Feature\NumericDates;

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Audit Trail's date filter picks whole Manila calendar days: from the
 * start day's 00:00:00 up to, but not including, the day after the end day,
 * on the list and its CSV export alike. The days are written year-month-day.
 */
class AuditTrailManilaDayFilterTest extends TestCase
{
    use SetupLendyPH;

    /** @var array<string, int> Manila wall clock => audit row id */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        foreach ([
            '2026-11-23 23:59:59',
            '2026-11-24 00:00:00',
            // 2026-11-23 23:59:59 in UTC: still the 24th in Manila.
            '2026-11-24 07:59:59',
            '2026-11-24 23:59:59',
            '2026-11-25 00:00:00',
        ] as $manila) {
            $this->travelTo(Carbon::parse($manila, 'Asia/Manila'));
            $this->rows[$manila] = AuditLogService::log(
                action: 'manila_day_probe',
                auditable: $this->admin,
                description: "Probe at {$manila}.",
            )->id;
        }

        $this->travelTo(Carbon::parse('2026-11-26 09:00', 'Asia/Manila'));
    }

    public function test_a_single_day_lists_exactly_that_manila_day(): void
    {
        $ids = $this->getJson('/api/audit-logs?action=manila_day_probe&date_from=2026-11-24&date_to=2026-11-24&per_page=50')
            ->assertOk()
            ->json('data.*.id');

        $this->assertEqualsCanonicalizing([
            $this->rows['2026-11-24 00:00:00'],
            $this->rows['2026-11-24 07:59:59'],
            $this->rows['2026-11-24 23:59:59'],
        ], $ids);
    }

    public function test_the_start_and_end_days_bound_separately_across_midnight(): void
    {
        $from = $this->getJson('/api/audit-logs?action=manila_day_probe&date_from=2026-11-25&per_page=50')->assertOk()->json('data.*.id');
        $this->assertSame([$this->rows['2026-11-25 00:00:00']], $from);

        $to = $this->getJson('/api/audit-logs?action=manila_day_probe&date_to=2026-11-23&per_page=50')->assertOk()->json('data.*.id');
        $this->assertSame([$this->rows['2026-11-23 23:59:59']], $to);
    }

    public function test_the_export_uses_the_same_days(): void
    {
        $csv = $this->get('/api/audit-logs/export?action=manila_day_probe&date_from=2026-11-24&date_to=2026-11-24')
            ->assertOk()
            ->streamedContent();

        $this->assertSame(3, substr_count($csv, 'Probe at 2026-11-24'));
        $this->assertStringNotContainsString('Probe at 2026-11-23', $csv);
        $this->assertStringNotContainsString('Probe at 2026-11-25', $csv);
    }

    public function test_a_day_written_as_digits_is_refused_in_words(): void
    {
        $this->getJson('/api/audit-logs?date_from=20261124')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from' => 'The start date must be a date written as year-month-day, such as 2026-11-24.']);

        $this->getJson('/api/audit-logs?date_to=2026-11-24T00:00:00Z')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to' => 'The end date must be a date written as year-month-day, such as 2026-11-24.']);

        $this->assertSame(5, AuditLog::where('action', 'manila_day_probe')->count());
    }
}

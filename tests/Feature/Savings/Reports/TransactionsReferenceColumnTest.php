<?php

namespace Tests\Feature\Savings\Reports;

use App\Models\SavingsProduct;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Tests\Feature\Savings\Interest\SavingsHistory;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 7 (batch): the Reports savings register shows the typed
 * reference beside the SVT- number, as the Savings Transactions list does
 * since round 6. The screen, PDF and Excel read the JSON's
 * `external_reference`; the CSV the server writes gets its own "Reference"
 * column beside "Number", with a dash when none was typed.
 */
class TransactionsReferenceColumnTest extends TestCase
{
    use SavingsHistory, SetupLendyPH;

    private SavingsProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->cooperativeBooks();
        $this->onDay('2026-11-01');
        $this->product = $this->regularProduct(['name' => 'Regular Savings']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_csv_has_the_typed_reference_beside_the_number_and_a_dash_when_none(): void
    {
        $account = $this->openAccount($this->product, 100_000);
        $this->onDay('2026-11-02');
        $typed = $this->deposit($account, 20_000, ['method' => 'bank_transfer', 'external_reference' => 'BDO-55120']);
        $cash = $this->deposit($account, 5_000);
        $exporter = $this->userWith(['reports:export', 'savings:view']);
        $this->withoutMiddleware(ThrottleRequests::class);

        $csv = $this->actingAs($exporter)->get('/api/reports/savings/transactions/export?date_from=2026-11-02&date_to=2026-11-02')
            ->assertOk()->streamedContent();
        $lines = array_values(array_filter(preg_split('/\r?\n/', $csv) ?: [], fn (string $line): bool => trim($line) !== ''));
        $rows = array_map('str_getcsv', $lines);

        $this->assertSame(['Date', 'Value Date', 'Number', 'Reference', 'Account #', 'Member', 'Branch', 'Type', 'Method', 'In', 'Out', 'Balance'], array_map(
            fn (string $cell): string => preg_replace('/^\xEF\xBB\xBF/', '', $cell) ?? $cell,
            $rows[0],
        ));
        $byNumber = collect(array_slice($rows, 1))->keyBy(2);
        $this->assertSame('BDO-55120', $byNumber[$typed->reference][3]);
        $this->assertSame('—', $byNumber[$cash->reference][3]);
    }

    public function test_the_json_row_sends_the_typed_reference(): void
    {
        $account = $this->openAccount($this->product, 100_000);
        $this->onDay('2026-11-02');
        $this->deposit($account, 20_000, ['method' => 'bank_transfer', 'external_reference' => 'BDO-55120']);
        $reader = $this->userWith(['reports:view', 'savings:view']);

        $row = collect($this->actingAs($reader)->getJson('/api/reports/savings/transactions?date_from=2026-11-02&date_to=2026-11-02')->assertOk()->json('data'))
            ->firstWhere('method', 'bank_transfer');
        $this->assertSame('BDO-55120', $row['external_reference']);
    }
}

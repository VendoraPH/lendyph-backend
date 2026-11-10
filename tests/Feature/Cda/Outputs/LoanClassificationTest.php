<?php

namespace Tests\Feature\Cda\Outputs;

use App\Models\CdaReportingPeriod;
use App\Models\User;
use App\Services\Accounting\AllowanceCalculator;
use App\Services\Cda\PeriodOpener;
use App\Services\Cda\Sources\SourceContext;
use App\Services\Cda\Sources\SourceRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cda\Foundation\CdaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Loan classification and the provision gap (CDA design §7): read-only, from
 * the year-end capture when there is one (its provenance shown), otherwise
 * computed live from current balances with a Warning. The figures equal
 * AllowanceCalculator; the gap is required − the 1200 balance. Loan rows need
 * `loans:view`; there are no write routes.
 */
class LoanClassificationTest extends TestCase
{
    use CdaTestHelpers, SetupLendyPH;

    private const URI = '/api/cda/periods/2026/loan-classification';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** One loan over 120 days late and one 1–30 days late on Dec 31, 2026. */
    private function loanBook(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 09:00:00', 'Asia/Manila'));
        $old = $this->createReleasedLoan(['principal_amount' => 60000, 'start_date' => '2026-06-01']);
        Carbon::setTestNow(Carbon::parse('2026-11-15 09:00:00', 'Asia/Manila'));
        $recent = $this->createReleasedLoan(['principal_amount' => 30000, 'start_date' => '2026-11-15']);

        return [$old, $recent];
    }

    private function capture(): CdaReportingPeriod
    {
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:58:00', 'Asia/Manila'));
        $this->artisan('cda:capture-year-end')->assertSuccessful();

        return CdaReportingPeriod::query()->where('year', 2026)->firstOrFail();
    }

    private function viewer(bool $loans = false): User
    {
        return $this->userWith(array_values(array_filter(['cda:view', 'branches:view_all', $loans ? 'loans:view' : null])));
    }

    public function test_with_a_capture_it_reads_the_capture_and_shows_its_provenance(): void
    {
        $this->loanBook();
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:58:00', 'Asia/Manila'));
        $expected = app(AllowanceCalculator::class)->forDate('2026-12-31');
        $period = $this->capture();

        Carbon::setTestNow(Carbon::parse('2027-01-05 09:00:00', 'Asia/Manila'));
        $this->createReleasedLoan(['principal_amount' => 99000, 'start_date' => '2027-01-05']);

        $data = $this->actingAs($this->viewer())->getJson(self::URI)->assertOk()->json('data');

        $this->assertSame('year_end_capture', $data['basis']);
        $this->assertSame('2026-12-31', $data['as_of']);
        $this->assertTrue($period->year_end_captured_at->equalTo(Carbon::parse($data['captured_at'])), 'The capture time is shown.');
        $this->assertFalse($data['provisional']);
        $this->assertNull($data['warning']);
        $this->assertSame(['current', '1_30', '31_60', '61_90', '91_120', 'over_120'], array_column($data['rows'], 'bucket'));
        $this->assertSame(2, $data['total_loan_count'], 'The loan released after Dec 31 is not in the capture.');
        $this->assertSame('90000.00', $data['total_outstanding_principal']);
        $this->assertSame(sprintf('%.2f', $expected['totals']['required'] / 100), $data['required_allowance']);
        $this->assertSame('0.00', $data['existing_allowance']);
        $this->assertSame($data['required_allowance'], $data['provision_gap']);

        $rows = collect($data['rows'])->keyBy('bucket');
        $this->assertSame(1, $rows['over_120']['loan_count']);
        $this->assertSame('60000.00', $rows['over_120']['outstanding_principal']);
        $this->assertSame('50.00', $rows['over_120']['required_rate']);
        $this->assertSame('30000.00', $rows['over_120']['required_allowance']);
        $this->assertSame('5.00', $rows['1_30']['required_rate']);
        $this->assertSame('Over 120 days', $rows['over_120']['classification']);
        $this->assertSame('0.00', $rows['current']['required_rate']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $data['unpaid_interest_due']);
    }

    public function test_the_gap_is_required_less_the_1200_balance(): void
    {
        $this->loanBook();
        $period = $this->capture();
        $capture = $period->year_end_capture;
        $branchId = (string) $this->branch->id;
        $capture['balances'] = ['branches' => [$branchId => 10_000], 'unassigned' => 2_550];
        $period->forceFill(['year_end_capture' => $capture])->saveQuietly();

        $data = $this->actingAs($this->viewer())->getJson(self::URI)->assertOk()->json('data');

        $required = (int) $capture['allowance']['totals']['required'];
        $this->assertSame('125.50', $data['existing_allowance']);
        $this->assertSame(sprintf('%.2f', ($required - 12_550) / 100), $data['provision_gap']);
    }

    public function test_without_a_capture_it_computes_live_from_current_balances_with_a_warning(): void
    {
        $this->loanBook();
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));
        app(PeriodOpener::class)->ensure(2026);
        $expected = app(AllowanceCalculator::class)->forDate('2026-11-20');

        $data = $this->actingAs($this->viewer())->getJson(self::URI)->assertOk()->json('data');

        $this->assertSame('live', $data['basis']);
        $this->assertSame('2026-11-20', $data['as_of'], 'Live figures are as of today until the year ends.');
        $this->assertNull($data['captured_at']);
        $this->assertTrue($data['provisional']);
        $this->assertStringContainsString('current balances', $data['warning']);
        $this->assertSame(sprintf('%.2f', $expected['totals']['required'] / 100), $data['required_allowance']);
        $this->assertSame(2, $data['total_loan_count']);
    }

    public function test_loan_rows_need_loans_view(): void
    {
        [$old] = $this->loanBook();
        $this->capture();

        $hidden = $this->actingAs($this->viewer())->getJson(self::URI)->assertOk()->json('data');
        $this->assertFalse($hidden['loans_visible']);
        $this->assertNull($hidden['loans']);
        $this->assertNotEmpty($hidden['rows'], 'The bucket summary still shows.');

        $response = $this->actingAs($this->viewer(loans: true))->getJson(self::URI.'?per_page=1&bucket=over_120')->assertOk();
        $data = $response->json('data');
        $this->assertTrue($data['loans_visible']);
        $this->assertCount(1, $data['loans']);
        $this->assertSame($old->id, $data['loans'][0]['loan_id']);
        $this->assertSame($old->borrower->full_name, $data['loans'][0]['member']['name']);
        $this->assertSame('60000.00', $data['loans'][0]['outstanding_principal']);
        $this->assertSame('over_120', $data['loans'][0]['bucket']);
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertArrayNotHasKey('contact_number', $data['loans'][0]['member']);
    }

    public function test_a_bad_bucket_or_page_size_answers_422_in_words(): void
    {
        $this->loanBook();
        $this->capture();

        $this->actingAs($this->viewer(loans: true))->getJson(self::URI.'?bucket=nope&per_page=500')
            ->assertUnprocessable()
            ->assertJsonPath('errors.bucket.0', 'Pick one of the aging buckets: current, 1_30, 31_60, 61_90, 91_120, over_120.')
            ->assertJsonPath('errors.per_page.0', 'Show at most 100 loans a page.');
    }

    public function test_it_needs_cda_view_and_branches_view_all(): void
    {
        app(PeriodOpener::class)->ensure(2026);

        $this->actingAs($this->userWith(['branches:view_all', 'loans:view']))->getJson(self::URI)->assertForbidden();
        $this->actingAs($this->userWith(['cda:view', 'loans:view']))->getJson(self::URI)
            ->assertForbidden()
            ->assertJsonPath('message', 'CDA reports cover the whole cooperative, so they need access to every branch (branches:view_all).');
        $this->actingAs($this->viewer())->getJson('/api/cda/periods/2031/loan-classification')
            ->assertNotFound()
            ->assertJsonPath('message', 'There is no CDA reporting period for 2031.');
    }

    public function test_there_are_no_write_routes(): void
    {
        app(PeriodOpener::class)->ensure(2026);
        $this->actingAs($this->viewer());

        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->{$method}(self::URI, [])->assertStatus(405);
        }
    }

    public function test_the_sources_read_the_capture_and_are_provisional_when_live(): void
    {
        $this->loanBook();
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));
        $period = app(PeriodOpener::class)->ensure(2026);
        $registry = app(SourceRegistry::class);

        $live = $registry->resolve('loans.provision_required', [], new SourceContext($period));
        $this->assertTrue($live->provisional);
        $this->assertSame('live', $live->meta['basis']);

        Auth::logout();
        $period = $this->capture();
        $capture = $period->year_end_capture;
        $context = new SourceContext($period);

        $required = $registry->resolve('loans.provision_required', [], $context);
        $this->assertFalse($required->provisional);
        $this->assertSame(sprintf('%.2f', $capture['totals']['required'] / 100), $required->value);
        $this->assertSame('2026-12-31', $required->asOf);
        $this->assertStringContainsString('as of Dec 31, 2026', $required->label);
        $this->assertSame('year_end_capture', $required->meta['basis']);
        $this->assertSame('0.00', $registry->resolve('loans.allowance_booked', [], $context)->value);
        $this->assertSame($required->value, $registry->resolve('loans.provision_gap', [], $context)->value);

        $table = $registry->resolve('loans.classification', [], $context);
        $this->assertSame('table', $table->unit);
        $over = collect($table->value)->firstWhere('bucket', 'over_120');
        $this->assertSame(['bucket', 'label', 'dpd_range', 'loan_count', 'outstanding_principal', 'rate_percent', 'required'], array_keys($over));
        $this->assertSame('60000.00', $over['outstanding_principal']);
        $this->assertSame('30000.00', $over['required']);
    }

    public function test_a_live_classification_ages_the_loans_once(): void
    {
        $this->loanBook();
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00:00', 'Asia/Manila'));
        app(PeriodOpener::class)->ensure(2026);
        $viewer = $this->viewer(loans: true);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($viewer)->getJson(self::URI)->assertOk();

        $agings = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'as oldest_unpaid_due'));
        DB::disableQueryLog();
        $this->assertCount(1, $agings, 'The aging query runs once; the allowance is worked out from the same rows.');
    }
}

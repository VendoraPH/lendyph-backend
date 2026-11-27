<?php

namespace Tests\Feature\Savings\Interest;

use App\Models\AuditLog;
use App\Models\SavingsInterestRunLine;
use App\Models\SavingsProduct;
use App\Models\SavingsProductRate;
use App\Services\Portal\Read\SavingsReader;
use App\Services\Savings\RateBook;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Tiered savings rates (gap round S2, TASK 16 "Tiered Rate"):
 *
 * - a product's rate set on a day (its ladder) is the rows sharing the latest
 *   `effective_from` on or before it; within that date, the highest id per
 *   minimum balance wins (a same-day correction);
 * - `min_balance` null is the base tier, and every ladder has exactly one;
 * - time deposit products take no tiers;
 * - each day's whole closing balance earns the tier it reaches, and the run's
 *   line records the ladder and the tier each day used, so posting works the
 *   figures out again and matches.
 */
class TieredRatesTest extends TestCase
{
    use SavingsHistory, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->cooperativeBooks();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tier(SavingsProduct $product, ?string $minBalance, string $rate, string $from = '2026-01-01'): SavingsProductRate
    {
        return SavingsProductRate::factory()->create(['product_id' => $product->id, 'min_balance' => $minBalance, 'annual_rate' => $rate, 'effective_from' => $from]);
    }

    /** @return array<string, mixed> the product as GET /savings/products lists it */
    private function listed(SavingsProduct $product): array
    {
        return collect($this->getJson('/api/savings/products')->assertOk()->json('data'))->firstWhere('id', $product->id);
    }

    /** A quarterly product: base 3.65%, ₱10,000.00 and up 7.3%. */
    private function tieredProduct(): SavingsProduct
    {
        $product = $this->regularProduct(['minimum_adb_for_interest' => '0.00'], null);
        $this->tier($product, null, '3.6500');
        $this->tier($product, '10000.00', '7.3000');

        return $product;
    }

    public function test_legacy_rows_keep_the_newest_row_per_minimum_balance(): void
    {
        $product = $this->regularProduct([], null);
        $this->tier($product, null, '1.0000');
        $this->tier($product, '10000.00', '2.0000');
        $this->tier($product, '10000.00', '2.5000');
        $book = app(RateBook::class);

        $this->assertSame([
            ['min_balance' => null, 'annual_rate' => '1.0000'],
            ['min_balance' => '10000.00', 'annual_rate' => '2.5000'],
        ], array_map(fn (array $t) => ['min_balance' => $t['min_balance'], 'annual_rate' => $t['annual_rate']], $book->ladderOn($product->id, null, '2026-06-01')));
        $this->assertSame('1.0000', $book->rateOn($product->id, null, '2026-06-01'), 'Without a balance, the base tier.');
        $this->assertSame('1.0000', $book->rateOn($product->id, null, '2026-06-01', 999_999));
        $this->assertSame('2.5000', $book->rateOn($product->id, null, '2026-06-01', 1_000_000));
    }

    public function test_a_same_date_correction_replaces_the_whole_ladder(): void
    {
        $this->onDay('2026-11-10');
        $product = $this->regularProduct([], '1.0000');
        $url = "/api/savings/products/{$product->id}/rates";

        $this->postJson($url, ['effective_from' => '2026-12-01', 'tiers' => [
            ['min_balance' => null, 'annual_rate' => '1'],
            ['min_balance' => '50000', 'annual_rate' => '2'],
            ['min_balance' => '100000', 'annual_rate' => '3'],
        ]])->assertCreated()->assertJsonCount(3, 'data');

        $corrected = $this->postJson($url, ['effective_from' => '2026-12-01', 'tiers' => [
            ['min_balance' => null, 'annual_rate' => '1'],
            ['min_balance' => '50000', 'annual_rate' => '2.25'],
        ]])->assertCreated();

        $this->assertSame([[null, '1.0000'], ['50000.00', '2.2500']], array_map(fn (array $row) => [$row['min_balance'], $row['annual_rate']], $corrected->json('data')),
            'The response is the full ladder in force for the date.');

        $book = app(RateBook::class);
        $this->assertSame([[null, '1.0000'], ['50000.00', '2.2500']],
            array_map(fn (array $tier) => [$tier['min_balance'], $tier['annual_rate']], $book->ladderOn($product->id, null, '2026-12-01')));
        $this->assertSame('2.2500', $book->rateOn($product->id, null, '2026-12-01', 10_000_000), 'The ₱100,000.00 tier is gone.');
        $this->assertSame(5, SavingsProductRate::query()->where('product_id', $product->id)->whereDate('effective_from', '2026-12-01')->count(), 'Nothing is deleted.');
    }

    public function test_a_single_rate_on_the_same_date_replaces_a_ladder(): void
    {
        $this->onDay('2026-11-10');
        $product = $this->regularProduct([], '1.0000');
        $url = "/api/savings/products/{$product->id}/rates";

        $this->postJson($url, ['effective_from' => '2026-12-01', 'tiers' => [
            ['min_balance' => null, 'annual_rate' => '1'],
            ['min_balance' => '50000', 'annual_rate' => '2'],
        ]])->assertCreated();
        $this->postJson($url, ['annual_rate' => '1.75', 'effective_from' => '2026-12-01'])
            ->assertCreated()->assertJsonPath('data.annual_rate', '1.7500')->assertJsonPath('data.min_balance', null);

        $book = app(RateBook::class);
        $this->assertCount(1, $book->ladderOn($product->id, null, '2026-12-01'));
        $this->assertSame('1.7500', $book->rateOn($product->id, null, '2026-12-01', 10_000_000));
        $this->assertSame([['from' => '2026-12-01', 'to' => '2026-12-31', 'rate' => '1.7500']], $book->segments($product->id, null, '2026-12-01', '2026-12-31'));
    }

    public function test_creating_a_product_writes_its_first_rates_as_one_post(): void
    {
        $this->onDay('2026-11-10');

        $id = $this->postJson('/api/savings/products', [
            'code' => 'TD-TIER', 'name' => 'Tier Time Deposit', 'kind' => 'time_deposit', 'interest_crediting' => 'annual',
            'minimum_opening_balance' => '1000.00', 'maintaining_balance' => '0', 'minimum_adb_for_interest' => '0',
            'term_options_days' => [90, 180], 'annual_interest_rate' => '3',
        ])->assertCreated()->json('data.id');

        $posts = SavingsProductRate::query()->where('product_id', $id)->pluck('rate_post_id');
        $this->assertCount(2, $posts);
        $this->assertNotNull($posts[0], 'The first rates belong to a post, so only rows from before tiers use the old rule.');
        $this->assertSame([$posts[0]], $posts->unique()->values()->all());

        $this->postJson("/api/savings/products/{$id}/rates", ['annual_rate' => '3.5', 'term_days' => 90, 'effective_from' => '2026-11-10'])->assertCreated();
        $this->assertSame('3.5000', app(RateBook::class)->rateOn($id, 90, '2026-11-10'));
        $this->assertSame('3.0000', app(RateBook::class)->rateOn($id, 180, '2026-11-10'), 'A post on one term leaves the other terms alone.');
    }

    public function test_a_tier_sent_without_a_minimum_balance_key_is_the_base_tier(): void
    {
        $this->onDay('2026-11-10');
        $product = $this->regularProduct([], '1.0000');

        $this->postJson("/api/savings/products/{$product->id}/rates", ['effective_from' => '2026-12-01', 'tiers' => [
            ['annual_rate' => '1.1'],
            ['min_balance' => '10000', 'annual_rate' => '2'],
        ]])->assertCreated()->assertJsonPath('data.0.min_balance', null)->assertJsonPath('data.0.annual_rate', '1.1000');
    }

    public function test_a_later_ladder_replaces_the_whole_earlier_one(): void
    {
        $product = $this->tieredProduct();
        $this->tier($product, null, '1.5000', '2026-08-01');
        $book = app(RateBook::class);

        $this->assertSame('7.3000', $book->rateOn($product->id, null, '2026-07-31', 2_000_000));
        $this->assertSame('1.5000', $book->rateOn($product->id, null, '2026-08-01', 2_000_000), 'The ₱10,000.00 tier ended with its date.');

        $this->assertSame([
            ['from' => '2026-07-01', 'to' => '2026-07-31', 'rate' => '3.6500', 'tiers' => [
                ['min_balance' => null, 'rate' => '3.6500'],
                ['min_balance' => '10000.00', 'rate' => '7.3000'],
            ]],
            ['from' => '2026-08-01', 'to' => '2026-08-31', 'rate' => '1.5000'],
        ], $book->segments($product->id, null, '2026-07-01', '2026-08-31'));
    }

    public function test_the_api_takes_a_ladder_for_one_date_and_audits_each_tier_in_words(): void
    {
        $this->onDay('2026-11-10');
        $product = $this->regularProduct(['name' => 'Regular Savings'], '1.0000');

        $response = $this->postJson("/api/savings/products/{$product->id}/rates", [
            'effective_from' => '2026-12-01',
            'tiers' => [
                ['min_balance' => null, 'annual_rate' => '1.25'],
                ['min_balance' => '10000', 'annual_rate' => '2'],
                ['min_balance' => '50000.00', 'annual_rate' => '2.5'],
            ],
        ])->assertCreated();

        $this->assertSame([[null, '1.2500'], ['10000.00', '2.0000'], ['50000.00', '2.5000']],
            array_map(fn (array $row) => [$row['min_balance'], $row['annual_rate']], $response->json('data')));
        $this->assertSame(['2026-12-01'], array_values(array_unique(array_column($response->json('data'), 'effective_from'))));

        $descriptions = AuditLog::query()->where('auditable_type', SavingsProductRate::class)->where('action', 'created')
            ->whereIn('auditable_id', array_column($response->json('data'), 'id'))->orderBy('auditable_id')->pluck('description')->all();
        $this->assertSame([
            'Regular Savings rate from Dec 1, 2026. Below ₱10,000.00: 1.25% a year.',
            'Regular Savings rate from Dec 1, 2026. ₱10,000.00 and up: 2% a year.',
            'Regular Savings rate from Dec 1, 2026. ₱50,000.00 and up: 2.5% a year.',
        ], $descriptions);

        $this->onDay('2026-12-01');
        $current = $this->listed($product)['current_rates'][0];
        $this->assertSame('1.2500', $current['annual_rate'], 'annual_rate stays the base tier.');
        $this->assertSame([
            ['min_balance' => null, 'annual_rate' => '1.2500', 'effective_from' => '2026-12-01'],
            ['min_balance' => '10000.00', 'annual_rate' => '2.0000', 'effective_from' => '2026-12-01'],
            ['min_balance' => '50000.00', 'annual_rate' => '2.5000', 'effective_from' => '2026-12-01'],
        ], $current['tiers']);

        $this->getJson("/api/savings/products/{$product->id}/rates")->assertOk()->assertJsonPath('data.0.min_balance', '50000.00');
    }

    public function test_a_single_rate_keeps_todays_shape(): void
    {
        $this->onDay('2026-11-10');
        $product = $this->regularProduct(['name' => 'Regular Savings'], '1.0000');

        $response = $this->postJson("/api/savings/products/{$product->id}/rates", ['annual_rate' => '1.5', 'effective_from' => '2026-12-01'])
            ->assertCreated()
            ->assertJsonPath('data.annual_rate', '1.5000')
            ->assertJsonPath('data.min_balance', null);

        $this->assertSame('Regular Savings rate from Dec 1, 2026. 1.5% a year.',
            AuditLog::query()->where('auditable_type', SavingsProductRate::class)->where('auditable_id', $response->json('data.id'))->value('description'));

        $current = $this->listed($product)['current_rates'][0];
        $this->assertSame('1.0000', $current['annual_rate']);
        $this->assertSame([['min_balance' => null, 'annual_rate' => '1.0000', 'effective_from' => '2026-01-01']], $current['tiers']);
    }

    public function test_every_ladder_needs_exactly_one_base_tier(): void
    {
        $this->onDay('2026-11-10');
        $product = $this->regularProduct([], '1.0000');
        $url = "/api/savings/products/{$product->id}/rates";

        $this->postJson($url, ['effective_from' => '2026-12-01', 'tiers' => [['min_balance' => '10000.00', 'annual_rate' => '2']]])
            ->assertUnprocessable()->assertJsonValidationErrors('tiers');
        $this->postJson($url, ['effective_from' => '2026-12-01', 'tiers' => [['min_balance' => null, 'annual_rate' => '1'], ['min_balance' => null, 'annual_rate' => '2']]])
            ->assertUnprocessable()->assertJsonValidationErrors('tiers');

        $this->assertSame(1, SavingsProductRate::query()->where('product_id', $product->id)->count());
    }

    public function test_tiers_must_ascend_without_duplicates_and_rates_stay_within_0_to_100(): void
    {
        $this->onDay('2026-11-10');
        $product = $this->regularProduct([], '1.0000');
        $url = "/api/savings/products/{$product->id}/rates";
        $base = ['min_balance' => null, 'annual_rate' => '1'];

        $this->postJson($url, ['effective_from' => '2026-12-01', 'tiers' => [$base, ['min_balance' => '50000', 'annual_rate' => '3'], ['min_balance' => '10000', 'annual_rate' => '2']]])
            ->assertUnprocessable()->assertJsonValidationErrors('tiers');
        $this->postJson($url, ['effective_from' => '2026-12-01', 'tiers' => [$base, ['min_balance' => '10000', 'annual_rate' => '2'], ['min_balance' => '10000.00', 'annual_rate' => '3']]])
            ->assertUnprocessable()->assertJsonValidationErrors('tiers');
        $this->postJson($url, ['effective_from' => '2026-12-01', 'tiers' => [$base, ['min_balance' => '10000', 'annual_rate' => '100.0001']]])
            ->assertUnprocessable()->assertJsonValidationErrors('tiers.1.annual_rate');
        $this->postJson($url, ['effective_from' => '2026-12-01', 'tiers' => [$base, ['min_balance' => '0', 'annual_rate' => '2']]])
            ->assertUnprocessable()->assertJsonValidationErrors('tiers.1.min_balance');
        $this->postJson($url, ['effective_from' => '2026-12-01', 'annual_rate' => '1', 'tiers' => [$base]])
            ->assertUnprocessable()->assertJsonValidationErrors('annual_rate');

        $this->postJson($url, ['effective_from' => '2026-12-01', 'tiers' => [$base, ['min_balance' => '10000', 'annual_rate' => '2.12345']]])
            ->assertUnprocessable()->assertJsonValidationErrors('tiers.1.annual_rate');
        $this->postJson($url, ['effective_from' => '2026-12-01', 'tiers' => [$base, ['min_balance' => '10000', 'annual_rate' => '2.1234']]])
            ->assertCreated()->assertJsonPath('data.1.annual_rate', '2.1234');
    }

    public function test_time_deposit_products_refuse_tiers(): void
    {
        $this->onDay('2026-11-10');
        $product = SavingsProduct::factory()->timeDeposit()->create(['term_options_days' => [90]]);

        $this->postJson("/api/savings/products/{$product->id}/rates", [
            'effective_from' => '2026-12-01', 'term_days' => 90,
            'tiers' => [['min_balance' => null, 'annual_rate' => '3'], ['min_balance' => '50000', 'annual_rate' => '4']],
        ])->assertUnprocessable()->assertJsonValidationErrors('tiers');

        $this->assertSame(0, SavingsProductRate::query()->where('product_id', $product->id)->count());
    }

    public function test_a_run_pays_each_day_its_tier_records_the_ladder_and_posts_after_recomputing(): void
    {
        $maker = $this->userWith(['savings:view', 'savings:post']);
        $checker = $this->userWith(['savings:view', 'savings:approve']);

        $this->onDay('2026-07-01');
        $product = $this->tieredProduct();
        $account = $this->openAccount($product, 500_000);
        $this->onDay('2026-08-15');
        $this->deposit($account, 1_000_000);
        $this->onDay('2026-10-01');

        // Jul 1–Aug 14: 45 days × ₱5,000.00 at 3.65%; Aug 15–Sep 30: 47 days × ₱15,000.00 at 7.3%.
        // 22,500,000 × 36,500 + 70,500,000 × 73,000 = 5,967,750,000,000 ÷ 365,000,000 = 16,350 centavos.
        $body = ['product_id' => $product->id, 'period_start' => '2026-07-01'];
        $preview = $this->actingAs($maker)->postJson('/api/savings/interest-runs/preview', $body)->assertOk()->json('data.runs.0.lines.0');

        $this->assertSame('163.50', $preview['gross_interest']);
        $this->assertSame('7.3000', $preview['rate']);
        $this->assertSame([
            'from' => '2026-07-01',
            'to' => '2026-09-30',
            'rate' => '7.3000',
            'tiers' => [
                ['min_balance' => null, 'rate' => '3.6500'],
                ['min_balance' => '10000.00', 'rate' => '7.3000'],
            ],
            'by_tier' => [
                ['min_balance' => null, 'rate' => '3.6500', 'days' => 45, 'balance_days' => 22_500_000],
                ['min_balance' => '10000.00', 'rate' => '7.3000', 'days' => 47, 'balance_days' => 70_500_000],
            ],
        ], $preview['rate_segments'][0]);

        $id = $this->actingAs($maker)->postJson('/api/savings/interest-runs', $body)->assertCreated()->json('data.0.id');
        $this->actingAs($checker)->postJson("/api/savings/interest-runs/{$id}/approve")->assertOk();
        $posted = $this->actingAs($maker)->postJson("/api/savings/interest-runs/{$id}/post")->assertOk()->json('data');

        $this->assertSame('posted', $posted['status']);
        $this->assertSame('163.50', $posted['total_gross']);
        $this->assertSame($preview['rate_segments'], $posted['lines'][0]['rate_segments']);
        $stored = SavingsInterestRunLine::query()->where('run_id', $id)->sole()->rate_segments;
        $this->assertSame(47, $stored[0]['by_tier'][1]['days']);
        $this->assertSame('163.50', $posted['lines'][0]['gross_interest']);
    }

    public function test_a_tier_only_ladder_above_a_zero_base_is_not_read_as_no_rate(): void
    {
        $maker = $this->userWith(['savings:view', 'savings:post']);

        $this->onDay('2026-07-01');
        $product = $this->regularProduct(['minimum_adb_for_interest' => '0.00'], null);
        $this->tier($product, null, '0.0000');
        $this->tier($product, '10000.00', '3.6500');
        $this->openAccount($product, 2_000_000);
        $this->onDay('2026-10-01');

        // 92 days × ₱20,000.00 at 3.65% = 184,000,000 ÷ 10,000 = 18,400 centavos.
        $line = $this->actingAs($maker)->postJson('/api/savings/interest-runs/preview', ['product_id' => $product->id, 'period_start' => '2026-07-01'])
            ->assertOk()->json('data.runs.0.lines.0');

        $this->assertNull($line['skip_reason']);
        $this->assertSame('184.00', $line['gross_interest']);
    }

    public function test_a_line_that_stays_in_a_0_percent_base_tier_says_why_it_earns_nothing(): void
    {
        $maker = $this->userWith(['savings:view', 'savings:post']);

        $this->onDay('2026-07-01');
        $product = $this->regularProduct(['minimum_adb_for_interest' => '0.00'], null);
        $this->tier($product, null, '0.0000');
        $this->tier($product, '10000.00', '3.6500');
        $low = $this->openAccount($product, 999_999);
        $this->openAccount($product, 2_000_000);
        $this->onDay('2026-10-01');

        $lines = collect($this->actingAs($maker)->postJson('/api/savings/interest-runs/preview', ['product_id' => $product->id, 'period_start' => '2026-07-01'])
            ->assertOk()->json('data.runs.0.lines'));
        $line = $lines->firstWhere('account_id', $low->id);

        $this->assertSame('0.00', $line['gross_interest']);
        $this->assertSame('below_earning_tier', $line['skip_reason']);
    }

    public function test_a_single_rate_product_gives_the_same_figures_and_segments_as_before(): void
    {
        $maker = $this->userWith(['savings:view', 'savings:post']);

        $this->onDay('2026-07-01');
        $product = $this->regularProduct(['minimum_adb_for_interest' => '0.00']);
        $account = $this->openAccount($product, 500_000);
        $this->onDay('2026-08-15');
        $this->deposit($account, 1_000_000);
        $this->onDay('2026-10-01');

        // 93,000,000 centavo-days at 3.65% = 9,300 centavos, exactly as before tiers.
        $line = $this->actingAs($maker)->postJson('/api/savings/interest-runs/preview', ['product_id' => $product->id, 'period_start' => '2026-07-01'])
            ->assertOk()->json('data.runs.0.lines.0');

        $this->assertSame('93.00', $line['gross_interest']);
        $this->assertSame('3.6500', $line['rate']);
        $this->assertSame([['from' => '2026-07-01', 'to' => '2026-09-30', 'rate' => '3.6500']], $line['rate_segments']);
    }

    public function test_the_portal_reads_the_tier_its_balance_earns_and_the_ladder(): void
    {
        $this->onDay('2026-07-01');
        $product = $this->tieredProduct();
        $low = $this->openAccount($product, 999_999);
        $high = $this->openAccount($product, 1_000_000);

        $rows = app(SavingsReader::class)->present(collect([$low->refresh(), $high->refresh()]));

        $this->assertSame('3.6500', $rows[0]['annual_rate']);
        $this->assertSame('7.3000', $rows[1]['annual_rate']);
        $this->assertSame([['min_balance' => null, 'annual_rate' => '3.6500'], ['min_balance' => '10000.00', 'annual_rate' => '7.3000']], $rows[1]['rate_tiers']);
    }
}

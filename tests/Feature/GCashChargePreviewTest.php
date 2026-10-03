<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /api/gcash/transactions/preview quotes the charge and total the counter
 * is about to collect, so the Cash In / Cash Out dialog never works them out
 * in the browser. It must agree with what POST /api/gcash/transactions then
 * records for the same type and amount, to the centavo.
 */
class GCashChargePreviewTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        GCashTier::query()->delete();
        GCashTier::create(['min_amount' => 1, 'max_amount' => 1500, 'cash_in_rate' => 15, 'cash_out_rate' => 10, 'display_order' => 1]);
        GCashTier::create(['min_amount' => 1500.01, 'max_amount' => 5000, 'cash_in_rate' => 30.50, 'cash_out_rate' => 25.25, 'display_order' => 2]);
        GCashTier::create(['min_amount' => 5500, 'max_amount' => 50000, 'cash_in_rate' => 100, 'cash_out_rate' => 75.75, 'display_order' => 3]);
    }

    /**
     * @return array<string, array{string, int|float|string}>
     */
    public static function quotedAmounts(): array
    {
        $cases = [];

        foreach (['cash_in', 'cash_out'] as $type) {
            foreach ([1, 750.5, 1500, '1500.01', 2750.55, 5000, 5500, '5500.00', 49999.99, 50000] as $amount) {
                $cases["{$type} {$amount}"] = [$type, $amount];
            }
        }

        return $cases;
    }

    #[DataProvider('quotedAmounts')]
    public function test_preview_matches_what_the_store_records(string $type, int|float|string $amount): void
    {
        $preview = $this->getJson('/api/gcash/transactions/preview?'.http_build_query([
            'type' => $type,
            'amount' => $amount,
        ]));

        $preview->assertOk();

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $stored = $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $borrower->id,
            'type' => $type,
            'amount' => $amount,
        ])->assertCreated();

        $this->assertSame([
            'type' => $stored->json('data.type'),
            'amount' => $stored->json('data.amount'),
            'charge_amount' => $stored->json('data.charge_amount'),
            'total_amount' => $stored->json('data.total_amount'),
        ], $preview->json('data'));
    }

    public function test_preview_answers_with_the_resource_field_names_and_number_format(): void
    {
        $cases = [
            ['cash_in', '1500', '"type":"cash_in","amount":1500,"charge_amount":15,"total_amount":1515'],
            ['cash_out', '2750.55', '"type":"cash_out","amount":2750.55,"charge_amount":25.25,"total_amount":2725.3'],
        ];

        foreach ($cases as [$type, $amount, $fields]) {
            $this->getJson("/api/gcash/transactions/preview?type={$type}&amount={$amount}")
                ->assertOk()
                ->assertContent('{"data":{'.$fields.'}}');

            $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
            $stored = $this->postJson('/api/gcash/transactions', [
                'borrower_id' => $borrower->id,
                'type' => $type,
                'amount' => $amount,
            ])->assertCreated();

            $this->assertStringContainsString($fields, $stored->getContent());
        }
    }

    /**
     * @return array<string, array{int|float|string}>
     */
    public static function amountsOutsideEveryTier(): array
    {
        return [
            'below the first tier' => [0.5],
            'just past a tier, in a gap' => ['5000.01'],
            'inside a gap' => [5250],
            'above the last tier' => [50000.01],
        ];
    }

    #[DataProvider('amountsOutsideEveryTier')]
    public function test_preview_returns_the_store_s_422_when_no_tier_matches(int|float|string $amount): void
    {
        $preview = $this->getJson('/api/gcash/transactions/preview?'.http_build_query([
            'type' => 'cash_out',
            'amount' => $amount,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $store = $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $borrower->id,
            'type' => 'cash_out',
            'amount' => $amount,
        ])->assertUnprocessable();

        $this->assertSame($store->json('errors'), $preview->json('errors'));
        $this->assertSame($store->json('message'), $preview->json('message'));
        $this->assertStringContainsString('No tier matches amount', $preview->json('errors.amount.0'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInput(): array
    {
        return [
            'type missing' => [['amount' => 100], 'type'],
            'type unknown' => [['type' => 'transfer', 'amount' => 100], 'type'],
            'amount missing' => [['type' => 'cash_in'], 'amount'],
            'amount not numeric' => [['type' => 'cash_in', 'amount' => 'abc'], 'amount'],
            'amount zero' => [['type' => 'cash_in', 'amount' => 0], 'amount'],
            'amount negative' => [['type' => 'cash_out', 'amount' => -5], 'amount'],
            'amount with three decimals' => [['type' => 'cash_in', 'amount' => '100.125'], 'amount'],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_preview_validates_type_and_amount_like_the_store(array $query, string $field): void
    {
        $preview = $this->getJson('/api/gcash/transactions/preview?'.http_build_query($query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $store = $this->postJson('/api/gcash/transactions', ['borrower_id' => $borrower->id] + $query)
            ->assertUnprocessable();

        $this->assertSame($store->json("errors.{$field}"), $preview->json("errors.{$field}"));
    }

    public function test_preview_requires_the_transact_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        $this->assertTrue($viewer->can('gcash:view'));
        $this->assertFalse($viewer->can('gcash:transact'));
        $this->actingAs($viewer);

        $this->getJson('/api/gcash/transactions/preview?type=cash_in&amount=1000')
            ->assertForbidden();
    }

    public function test_preview_writes_nothing(): void
    {
        $transactions = GCashTransaction::count();
        $audits = AuditLog::count();

        $this->getJson('/api/gcash/transactions/preview?type=cash_in&amount=1000')->assertOk();
        $this->getJson('/api/gcash/transactions/preview?type=cash_out&amount=3000')->assertOk();
        $this->getJson('/api/gcash/transactions/preview?type=cash_in&amount=99999')->assertUnprocessable();

        $this->assertSame($transactions, GCashTransaction::count());
        $this->assertSame($audits, AuditLog::count());
    }
}

<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTaxRule;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The server quote and the placement (design §2, §5, §8 "Time deposits"):
 * the quote is the placement's own figures, a placement snapshots its rates
 * and posts Dr the money account (or savings deposits) / Cr time deposits,
 * and a savings-funded placement never takes more than the account's
 * available balance.
 */
class TimeDepositPlacementTest extends TestCase
{
    use TimeDepositFixtures;

    private const QUOTE_FIELDS = ['annual_rate', 'withholding_tax_rate', 'term_days', 'maturity_date', 'gross_interest', 'withholding_tax', 'net_interest', 'maturity_amount'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTimeDeposits();
    }

    protected function tearDown(): void
    {
        $this->tearDownTimeDeposits();
        parent::tearDown();
    }

    public function test_the_quote_is_worked_out_on_the_server_half_up_on_actual_365(): void
    {
        SavingsTaxRule::query()->create(['holder_type' => 'member', 'rate' => '20.0000', 'effective_from' => now()->toDateString()]);

        $quote = $this->postJson('/api/savings/time-deposits/quote', [
            'borrower_id' => $this->member->id,
            'product_id' => $this->product->id,
            'principal' => '100000.00',
            'term_days' => 90,
            'placement_date' => '2026-11-10',
        ])->assertOk()->json('data');

        // 10,000,000 × 3% × 90 ÷ 365 = 73,972.60 centavos → ₱739.73; 20% of it = 14,794.6 → ₱147.95.
        $this->assertSame([
            'annual_rate' => '3.0000',
            'withholding_tax_rate' => '20.0000',
            'term_days' => 90,
            'maturity_date' => '2027-02-08',
            'gross_interest' => '739.73',
            'withholding_tax' => '147.95',
            'net_interest' => '591.78',
            'maturity_amount' => '100591.78',
        ], $quote);
    }

    public function test_the_quote_equals_the_placement(): void
    {
        $body = $this->placementBody(['principal' => '123456.78', 'term_days' => 180]);
        $quote = $this->postJson('/api/savings/time-deposits/quote', collect($body)->only(['borrower_id', 'product_id', 'principal', 'term_days', 'placement_date'])->all())
            ->assertOk()->json('data');

        $placed = $this->idem('/api/savings/time-deposits', $body)->assertCreated()->json('data');

        foreach (self::QUOTE_FIELDS as $field) {
            $this->assertSame($quote[$field], $placed[$field], "The placement's {$field} differs from the quote's.");
        }

        $deposit = TimeDeposit::query()->findOrFail($placed['id']);
        $this->assertSame($quote['gross_interest'], $deposit->gross_interest);
        $this->assertSame($quote['maturity_amount'], $deposit->maturity_amount);
        $this->assertSame('1.0000', $deposit->pretermination_rate);
        $this->assertSame('active', $deposit->status);
        $this->assertMatchesRegularExpression('/^TD-2026-\d{6}$/', $deposit->certificate_number);
    }

    public function test_a_cash_placement_posts_dr_cash_cr_time_deposits_and_answers_in_the_screens_shape(): void
    {
        $data = $this->place()->assertCreated()->json('data');

        $journal = $this->journalFor(TimeDeposit::class, $data['id'], 'time_deposit');
        $this->assertNotNull($journal);
        $this->assertSame([['1010', 10_000_000, 0], ['2060', 0, 10_000_000]], $this->lines($journal));
        $this->assertSame($this->branch->id, $journal->branch_id);

        $this->assertSame($this->member->id, $data['borrower_id']);
        $this->assertSame($this->member->full_name, $data['borrower_name']);
        $this->assertSame($this->member->borrower_code, $data['borrower_code']);
        $this->assertSame($this->branch->name, $data['branch_name']);
        $this->assertSame(['id' => $this->product->id, 'code' => $this->product->code, 'name' => $this->product->name], $data['product']);
        $this->assertSame('100000.00', $data['principal']);
        $this->assertSame('cash', $data['funding_method']);
        $this->assertSame(['id' => $this->admin->id, 'name' => $this->admin->full_name], $data['created_by']);
        $this->assertNull($data['settled_by']);
        $this->assertNull($data['rolled_over_to_id']);
    }

    public function test_an_idempotent_replay_places_once(): void
    {
        $key = (string) Str::uuid();
        $first = $this->idem('/api/savings/time-deposits', $this->placementBody(), $key)->assertCreated();
        $this->idem('/api/savings/time-deposits', $this->placementBody(), $key)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, TimeDeposit::query()->count());
        $this->assertSame(1, AccountingJournal::query()->where('postable_type', TimeDeposit::class)->count());
    }

    public function test_a_placement_from_savings_debits_the_account_and_posts_dr_savings_deposits(): void
    {
        $account = $this->savingsAccount(15_000_000);

        $data = $this->place(['payment_method' => 'savings', 'funding_account_id' => $account->id])->assertCreated()->json('data');

        $deposit = TimeDeposit::query()->findOrFail($data['id']);
        $funding = SavingsTransaction::query()->findOrFail($deposit->funding_transaction_id);
        $this->assertSame($account->id, $funding->account_id);
        $this->assertSame('withdrawal', $funding->type);
        $this->assertSame('savings', $funding->method);
        $this->assertSame($deposit->id, $funding->time_deposit_id);
        $this->assertSame('100000.00', $funding->debit);
        $this->assertSame('50000.00', $account->refresh()->ledger_balance);

        $journal = $this->journalFor(TimeDeposit::class, $deposit->id, 'time_deposit');
        $this->assertSame([['2050', 10_000_000, 0], ['2060', 0, 10_000_000]], $this->lines($journal));
        $this->assertNull($this->journalFor(SavingsTransaction::class, $funding->id, 'savings'));
    }

    public function test_a_funding_shortfall_is_422_and_writes_nothing(): void
    {
        $account = $this->savingsAccount(5_000_000);
        $journals = AccountingJournal::query()->count();

        $this->place(['payment_method' => 'savings', 'funding_account_id' => $account->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('principal')
            ->assertJsonFragment(['principal' => ["The savings account's available balance is ₱50,000.00, short of the ₱100,000.00 to place."]]);

        $this->assertSame(0, TimeDeposit::query()->count());
        $this->assertSame(1, $this->savingsRows($account));
        $this->assertSame($journals, AccountingJournal::query()->count());
        $this->assertSame('50000.00', $account->refresh()->ledger_balance);
    }

    public function test_savings_funding_needs_the_members_own_account(): void
    {
        $this->place(['payment_method' => 'savings'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('funding_account_id');

        $other = $this->savingsAccount(20_000_000, Borrower::factory()->create(['branch_id' => $this->branch->id]));

        $this->place(['payment_method' => 'savings', 'funding_account_id' => $other->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('funding_account_id');
    }

    public function test_a_term_the_product_does_not_offer_is_422_in_words(): void
    {
        $this->place(['term_days' => 120])
            ->assertUnprocessable()
            ->assertJsonFragment(['term_days' => ["{$this->product->name} offers terms of 90, 180 and 360 days, not 120."]]);

        $this->postJson('/api/savings/time-deposits/quote', collect($this->placementBody(['term_days' => 120]))->only(['product_id', 'principal', 'term_days', 'placement_date'])->all())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('term_days');
    }

    public function test_a_regular_or_inactive_product_and_an_inactive_member_are_refused(): void
    {
        $this->place(['product_id' => $this->regular->id])->assertUnprocessable()->assertJsonValidationErrors('product_id');

        $off = SavingsProduct::factory()->timeDeposit()->inactive()->create();
        $this->rate($off, 90, '3.0000');
        $this->place(['product_id' => $off->id])->assertUnprocessable()->assertJsonValidationErrors('product_id');

        $this->member->forceFill(['status' => 'inactive'])->save();
        $this->place()->assertUnprocessable()->assertJsonValidationErrors('borrower_id');

        $this->assertSame(0, TimeDeposit::query()->count());
    }

    public function test_the_principal_must_reach_the_products_minimum(): void
    {
        $this->product->forceFill(['minimum_opening_balance' => '5000.00'])->save();

        $this->place(['principal' => '4999.99'])
            ->assertUnprocessable()
            ->assertJsonFragment(['principal' => ["{$this->product->name} takes at least ₱5,000.00."]]);
    }

    public function test_the_placement_date_is_today_or_yesterday_only(): void
    {
        $this->place(['placement_date' => '2026-11-11'])->assertUnprocessable()->assertJsonValidationErrors('placement_date');
        $this->place(['placement_date' => '2026-11-08'])->assertUnprocessable()->assertJsonValidationErrors('placement_date');
        $this->place(['placement_date' => '2026-11-09'])->assertUnprocessable()
            ->assertJsonFragment(['term_days' => ["{$this->product->name} has no rate in force for 90 days on Nov 9, 2026. Add one in Savings → Products."]]);

        $this->rate($this->product, 90, '3.0000', '2026-11-09');
        $this->place(['placement_date' => '2026-11-09'])->assertCreated()->assertJsonPath('data.maturity_date', '2027-02-07');
    }

    public function test_a_payout_account_must_be_the_members_open_account(): void
    {
        $closed = SavingsAccount::factory()->closed()->create([
            'borrower_id' => $this->member->id, 'product_id' => $this->regular->id, 'branch_id' => $this->branch->id,
        ]);

        $this->place(['payout_account_id' => $closed->id])->assertUnprocessable()->assertJsonValidationErrors('payout_account_id');

        $open = $this->savingsAccount();
        $this->place(['payout_account_id' => $open->id])->assertCreated()
            ->assertJsonPath('data.payout_account_id', $open->id)
            ->assertJsonPath('data.payout_account_number', $open->account_number);
    }

    public function test_a_closed_period_is_422_and_writes_nothing(): void
    {
        $account = $this->savingsAccount(15_000_000);
        $journals = AccountingJournal::query()->count();
        $this->closePeriodOf('2026-11-10');

        $this->place()->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->place(['payment_method' => 'savings', 'funding_account_id' => $account->id])->assertUnprocessable()->assertJsonValidationErrors('date');

        $this->assertSame(0, TimeDeposit::query()->count());
        $this->assertSame(1, $this->savingsRows($account));
        $this->assertSame($journals, AccountingJournal::query()->count());
    }

    public function test_a_placement_needs_an_idempotency_key(): void
    {
        $this->postJson('/api/savings/time-deposits', $this->placementBody())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }
}

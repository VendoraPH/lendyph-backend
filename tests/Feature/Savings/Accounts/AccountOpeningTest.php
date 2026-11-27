<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * POST /savings/accounts (design §2): an active member opens one account per
 * regular product, with an opening deposit at least the product's minimum.
 * The deposit is a ledger row and a journal (Dr cash / Cr 2050).
 */
class AccountOpeningTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function body(array $overrides = []): array
    {
        return [
            'borrower_id' => $this->member->id,
            'product_id' => $this->product->id,
            'opened_at' => '2026-11-10',
            'initial_deposit' => '1500.00',
            'payment_method' => 'cash',
            'reference' => 'OR-1001',
            ...$overrides,
        ];
    }

    public function test_opening_writes_the_account_its_deposit_and_the_journal(): void
    {
        $response = $this->money('/api/savings/accounts', $this->body())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.borrower_id', $this->member->id)
            ->assertJsonPath('data.borrower_name', 'Maria Santos')
            ->assertJsonPath('data.borrower_code', $this->member->borrower_code)
            ->assertJsonPath('data.branch_id', $this->branch->id)
            ->assertJsonPath('data.branch_name', $this->branch->name)
            ->assertJsonPath('data.product.id', $this->product->id)
            ->assertJsonPath('data.product.kind', 'regular')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.balance', '1500.00')
            ->assertJsonPath('data.hold_amount', '0.00')
            ->assertJsonPath('data.pending_amount', '0.00')
            ->assertJsonPath('data.available_balance', '1500.00')
            ->assertJsonPath('data.accrued_interest', '0.00')
            ->assertJsonPath('data.opened_on', '2026-11-10')
            ->assertJsonPath('data.last_member_activity_on', '2026-11-10');

        $this->assertMatchesRegularExpression('/^SAV-2026-\d{6}$/', $response->json('data.account_number'));

        $account = SavingsAccount::query()->findOrFail($response->json('data.id'));
        $this->assertSame(SavingsAccount::openKey($this->member->id, $this->product->id), $account->open_key);
        $this->assertSame($this->admin->id, $account->created_by);

        $deposit = SavingsTransaction::query()->where('account_id', $account->id)->sole();
        $this->assertSame(['deposit', '1500.00', '1500.00', 'cash', 'OR-1001'], [$deposit->type, $deposit->credit, $deposit->balance_after, $deposit->method, $deposit->external_reference]);
        $this->assertSame([['1010', 150_000, 0], ['2050', 0, 150_000]], $this->journalLines($this->journalFor($deposit)));
    }

    public function test_an_opening_with_no_deposit_is_allowed_when_the_minimum_is_zero(): void
    {
        $this->product->update(['minimum_opening_balance' => '0.00']);

        $this->money('/api/savings/accounts', $this->body(['initial_deposit' => '0']))
            ->assertCreated()
            ->assertJsonPath('data.balance', '0.00');

        $this->assertSame(0, SavingsTransaction::query()->count());
        $this->assertSame(0, AccountingJournal::query()->where('postable_type', SavingsTransaction::class)->count());
    }

    public function test_refusals_in_words(): void
    {
        $this->money('/api/savings/accounts', $this->body(['initial_deposit' => '499.99']))
            ->assertUnprocessable()->assertJsonValidationErrors('initial_deposit');

        $this->member->update(['status' => 'inactive']);
        $this->money('/api/savings/accounts', $this->body())
            ->assertUnprocessable()->assertJsonValidationErrors('borrower_id');
        $this->member->update(['status' => 'active']);

        $inactive = SavingsProduct::factory()->inactive()->create();
        $this->money('/api/savings/accounts', $this->body(['product_id' => $inactive->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('product_id');

        $timeDeposit = SavingsProduct::factory()->timeDeposit()->create();
        $this->money('/api/savings/accounts', $this->body(['product_id' => $timeDeposit->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('product_id');

        $this->money('/api/savings/accounts', $this->body(['opened_at' => '2026-11-11']))
            ->assertUnprocessable()->assertJsonValidationErrors('opened_at');

        $this->money('/api/savings/accounts', $this->body(['payment_method' => 'savings']))
            ->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        $this->postJson('/api/savings/accounts', $this->body())
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(0, SavingsAccount::query()->count());
    }

    public function test_a_second_open_account_on_the_product_is_409_and_a_closed_one_does_not_count(): void
    {
        $this->money('/api/savings/accounts', $this->body())->assertCreated();
        $this->money('/api/savings/accounts', $this->body())->assertStatus(409);

        SavingsAccount::query()->update(['status' => 'closed', 'open_key' => null, 'closed_on' => '2026-11-10']);
        $this->money('/api/savings/accounts', $this->body())->assertCreated();
        $this->assertSame(2, SavingsAccount::query()->count());
    }

    public function test_a_member_in_a_hidden_branch_reads_as_not_found(): void
    {
        $other = Borrower::factory()->create(['status' => 'active']);
        $this->actingAs($this->teller());

        $this->money('/api/savings/accounts', $this->body(['borrower_id' => $other->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('borrower_id');
    }

    public function test_an_idempotent_replay_opens_once(): void
    {
        $key = '6f1f0d55-6d3f-4b3e-9a51-2a8f0c1f7a10';
        $first = $this->money('/api/savings/accounts', $this->body(), $key)->assertCreated();
        $again = $this->money('/api/savings/accounts', $this->body(), $key)->assertCreated();

        $again->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, SavingsAccount::query()->count());
        $this->assertSame(1, SavingsTransaction::query()->count());
    }
}

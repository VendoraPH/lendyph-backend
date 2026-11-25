<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsAutoCreditInstruction;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Savings\SavingsLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 5: a deposit, a withdrawal or an opening deposit paid by
 * bank transfer, GCash or Maya needs the reference of that payment (422 in
 * words that name the method and the entry), as loan payments and share
 * capital entries do. Cash needs none, and so does everything that never
 * comes through the counter's requests: transfers between accounts,
 * auto-credit runs, interest and reversals. New records only: an entry saved
 * without one, or a withdrawal already waiting for its checker, is never
 * re-checked. Round 2's duplicate-reference rule still runs after this.
 */
class ReferenceRequiredTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private const METHOD_WORDS = ['bank_transfer' => 'bank transfer', 'gcash' => 'GCash', 'maya' => 'Maya'];

    private SavingsAccount $mine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        $this->mine = $this->account();
        $this->fund($this->mine, 5_000_000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_deposit_by_bank_transfer_gcash_or_maya_without_a_reference_is_refused_in_words(): void
    {
        $before = SavingsTransaction::query()->count();

        foreach (self::METHOD_WORDS as $method => $words) {
            foreach ([[], ['reference' => null], ['reference' => '   ']] as $blank) {
                $this->money("/api/savings/accounts/{$this->mine->id}/deposits", $this->body($method, $blank))
                    ->assertUnprocessable()
                    ->assertJsonPath('errors.reference.0', "Enter the {$words} reference for this deposit.");
            }
        }

        $this->assertSame($before, SavingsTransaction::query()->count());
    }

    public function test_a_withdrawal_by_bank_transfer_gcash_or_maya_without_a_reference_is_refused_in_words(): void
    {
        foreach (self::METHOD_WORDS as $method => $words) {
            $this->money("/api/savings/accounts/{$this->mine->id}/withdrawals", $this->body($method))
                ->assertUnprocessable()
                ->assertJsonPath('errors.reference.0', "Enter the {$words} reference for this withdrawal.");
        }

        // Above tier 1 too: nothing waits for a checker without its reference.
        $this->money("/api/savings/accounts/{$this->mine->id}/withdrawals", $this->body('gcash', ['amount' => '10000.01']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Enter the GCash reference for this withdrawal.');

        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());
        $this->assertSame(0, SavingsApproval::query()->count());
    }

    public function test_with_its_reference_a_non_cash_deposit_and_withdrawal_save_it(): void
    {
        foreach (array_keys(self::METHOD_WORDS) as $i => $method) {
            $this->money("/api/savings/accounts/{$this->mine->id}/deposits", $this->body($method, ['reference' => "IN-{$i}"]))
                ->assertCreated()
                ->assertJsonPath('data.method', $method)
                ->assertJsonPath('data.external_reference', "IN-{$i}");
            $this->money("/api/savings/accounts/{$this->mine->id}/withdrawals", $this->body($method, ['reference' => "OUT-{$i}"]))
                ->assertCreated()
                ->assertJsonPath('data.external_reference', "OUT-{$i}");
        }
    }

    public function test_cash_needs_no_reference(): void
    {
        $this->money("/api/savings/accounts/{$this->mine->id}/deposits", $this->body('cash'))->assertCreated()->assertJsonPath('data.external_reference', null);
        $this->money("/api/savings/accounts/{$this->mine->id}/withdrawals", $this->body('cash'))->assertCreated()->assertJsonPath('data.external_reference', null);
    }

    public function test_round_twos_duplicate_rule_still_runs_on_a_reference_given(): void
    {
        $this->money("/api/savings/accounts/{$this->mine->id}/deposits", $this->body('gcash', ['reference' => 'GC-1']))->assertCreated();

        $this->money("/api/savings/accounts/{$this->mine->id}/deposits", $this->body('gcash', ['reference' => 'GC-1']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Reference "GC-1" is already on record for another GCash transaction. Check the reference and enter the right one.');
    }

    public function test_an_opening_deposit_by_a_non_cash_method_needs_its_reference(): void
    {
        foreach (self::METHOD_WORDS as $method => $words) {
            $this->open($this->product(), ['payment_method' => $method])
                ->assertUnprocessable()
                ->assertJsonPath('errors.reference.0', "Enter the {$words} reference for the opening deposit.");
        }
        $this->assertSame(1, SavingsAccount::query()->count());

        $this->open($product = $this->product(), ['payment_method' => 'maya', 'reference' => 'MY-OPEN-1'])->assertCreated();
        $this->assertSame('MY-OPEN-1', SavingsTransaction::query()->whereRelation('account', 'product_id', $product->id)->sole()->external_reference);
    }

    public function test_an_opening_paid_in_cash_or_with_nothing_to_deposit_needs_no_reference(): void
    {
        $this->open($this->product(), ['payment_method' => 'cash'])->assertCreated();

        // ₱0.00 moves no money, whatever method the form still shows.
        $this->open($this->product(), ['initial_deposit' => '0.00', 'payment_method' => 'gcash'])->assertCreated();
        $this->open($this->product(), ['initial_deposit' => '0', 'payment_method' => 'bank_transfer'])->assertCreated();
    }

    public function test_a_transfer_between_the_members_accounts_takes_no_reference(): void
    {
        $other = $this->account(['product_id' => $this->product()->id]);

        $this->money("/api/savings/accounts/{$this->mine->id}/transfers", ['to_account_id' => $other->id, 'amount' => '100.00', 'date' => '2026-11-10'])
            ->assertCreated();
    }

    public function test_an_auto_credit_run_by_bank_transfer_takes_no_reference(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'Asia/Manila'));
        SavingsAutoCreditInstruction::query()->create([
            'account_id' => $this->mine->id, 'amount' => '500.00', 'schedule' => '15', 'active' => true,
            'authorised_on' => '2026-11-01', 'authorised_by' => 'The member', 'created_by' => $this->admin->id,
        ]);

        $this->money('/api/savings/auto-credit/process', ['cycle_date' => '2026-11-15', 'method' => 'bank_transfer'])->assertCreated();

        $this->assertSame(1, SavingsTransaction::query()->where('method', 'bank_transfer')->whereNull('external_reference')->count());
    }

    public function test_an_old_non_cash_deposit_saved_without_a_reference_can_still_be_reversed(): void
    {
        $old = DB::transaction(function (): SavingsTransaction {
            $row = app(SavingsLedger::class)->credit($this->mine, 'deposit', 25_000, ['date' => '2026-11-10', 'method' => 'gcash']);
            app(AutomaticPoster::class)->savingsTransaction($row, $this->admin->id);

            return $row;
        });

        $this->actingAs($this->teller());
        $approval = $this->money("/api/savings/transactions/{$old->id}/reverse", ['reason' => 'Keyed twice'])->assertStatus(202)->json('data.approval');

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame($old->id, SavingsTransaction::query()->where('type', 'reversal')->sole()->reverses_transaction_id);
    }

    public function test_a_withdrawal_already_waiting_for_its_checker_without_a_reference_still_posts(): void
    {
        $this->actingAs($this->teller());
        $id = $this->money("/api/savings/accounts/{$this->mine->id}/withdrawals", $this->body('bank_transfer', ['amount' => '10000.01', 'reference' => 'BDO-1']))
            ->assertStatus(202)->json('data.approval.id');
        // Asked for before this rule, with no reference.
        $approval = SavingsApproval::query()->findOrFail($id);
        $approval->forceFill(['payload' => ['reference' => null] + $approval->payload])->save();

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $row = SavingsTransaction::query()->where('type', 'withdrawal')->sole();
        $this->assertSame(['bank_transfer', null], [$row->method, $row->external_reference]);
    }

    /** @return array<string, mixed> */
    private function body(string $method, array $overrides = []): array
    {
        return ['amount' => '250.00', 'date' => '2026-11-10', 'payment_method' => $method, ...$overrides];
    }

    private function product(): SavingsProduct
    {
        return SavingsProduct::factory()->create(['minimum_opening_balance' => '0.00']);
    }

    private function open(SavingsProduct $product, array $overrides = [])
    {
        return $this->money('/api/savings/accounts', [
            'borrower_id' => $this->member->id, 'product_id' => $product->id, 'opened_at' => '2026-11-10',
            'initial_deposit' => '1500.00', ...$overrides,
        ]);
    }
}

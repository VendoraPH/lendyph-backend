<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\Borrower;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\Savings\SavingsLedger;
use App\Services\Savings\TimeDeposits\Placement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Finish run round 2:
 *
 * - decision 5: a cash placement gets `TD-[id]-[year]-[0001]` when it saves
 *   (the primary super admin's id, the placement date's year), any typed
 *   reference ignored; the preview answers the next one and reserves nothing;
 * - the reference a placement by bank transfer, GCash, Maya or savings was
 *   given is saved on the placement (it was dropped);
 * - decision 11: a non-cash placement whose reference is already used on the
 *   same method in the cooperative is refused, naming only the reference.
 */
class TimeDepositCashReferenceTest extends TestCase
{
    use TimeDepositFixtures;

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

    public function test_a_cash_placement_gets_the_next_td_number_and_answers_it(): void
    {
        $owner = $this->owner();

        $data = $this->place()->assertCreated()->assertJsonPath('data.reference', "TD-{$owner}-2026-0001")->json('data');
        $this->assertSame("TD-{$owner}-2026-0001", TimeDeposit::query()->findOrFail($data['id'])->reference);
        $this->getJson("/api/savings/time-deposits/{$data['id']}")->assertOk()->assertJsonPath('data.reference', "TD-{$owner}-2026-0001");

        $this->place()->assertCreated()->assertJsonPath('data.reference', "TD-{$owner}-2026-0002");
    }

    public function test_a_typed_reference_is_ignored_for_cash(): void
    {
        $this->place(['reference' => 'OR-TYPED'])->assertCreated()->assertJsonPath('data.reference', "TD-{$this->owner()}-2026-0001");

        $this->assertFalse(TimeDeposit::query()->where('reference', 'OR-TYPED')->exists());
    }

    public function test_the_reference_of_a_placement_by_another_method_is_saved_and_takes_no_number(): void
    {
        foreach (['bank_transfer' => 'BDO-0001', 'gcash' => 'GC-0001', 'maya' => 'MY-0001'] as $method => $reference) {
            $data = $this->place(['payment_method' => $method, 'reference' => $reference])->assertCreated()->assertJsonPath('data.reference', $reference)->json('data');
            $this->assertSame($reference, TimeDeposit::query()->findOrFail($data['id'])->reference);
        }

        // A non-cash placement never falls back to a number: without its reference it is refused (round 5).
        $this->place(['payment_method' => 'gcash'])->assertUnprocessable()->assertJsonPath('errors.reference.0', 'Enter the GCash reference for this time deposit.');
        $this->assertSame(0, DB::table('cash_reference_sequences')->count());
    }

    public function test_a_placement_from_savings_keeps_its_reference_on_the_placement_and_on_the_funding_row(): void
    {
        $account = $this->savingsAccount(15_000_000);

        $data = $this->place(['payment_method' => 'savings', 'funding_account_id' => $account->id, 'reference' => 'MEMO-7'])->assertCreated()->json('data');

        $deposit = TimeDeposit::query()->findOrFail($data['id']);
        $this->assertSame('MEMO-7', $deposit->reference);
        $this->assertSame('MEMO-7', SavingsTransaction::query()->findOrFail($deposit->funding_transaction_id)->external_reference);
    }

    public function test_a_rollover_moves_no_money_and_carries_no_reference(): void
    {
        $from = TimeDeposit::query()->findOrFail($this->place()->json('data.id'));

        $rollover = DB::transaction(fn () => app(Placement::class)->rollover($from, 10_000_000, $this->admin->id, '2027-02-08'));

        $this->assertNull($rollover->reference);
        $this->assertSame(1, DB::table('cash_reference_sequences')->value('last_number'));
    }

    public function test_each_year_starts_again_at_0001_and_the_placement_date_decides_the_year(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-01 10:00:00', 'Asia/Manila'));
        $owner = $this->owner();

        $this->place(['placement_date' => '2026-12-31'])->assertCreated()->assertJsonPath('data.reference', "TD-{$owner}-2026-0001");
        $this->place(['placement_date' => '2027-01-01'])->assertCreated()->assertJsonPath('data.reference', "TD-{$owner}-2027-0001");
        $this->place(['placement_date' => '2026-12-31'])->assertCreated()->assertJsonPath('data.reference', "TD-{$owner}-2026-0002");
    }

    public function test_the_td_and_csh_series_are_counted_apart(): void
    {
        $owner = $this->owner();

        $this->postJson('/api/share-capital/ledger', ['borrower_id' => $this->member->id, 'date' => '2026-11-10', 'description' => 'Contribution', 'type' => 'credit', 'amount' => 500])
            ->assertCreated()->assertJsonPath('data.reference_number', "CSH-{$owner}-2026-0001");
        $this->place()->assertCreated()->assertJsonPath('data.reference', "TD-{$owner}-2026-0001");
    }

    public function test_the_preview_answers_the_next_number_and_reserves_nothing(): void
    {
        $owner = $this->owner();

        $this->getJson('/api/savings/time-deposits/cash-reference/next')
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['reference_number' => "TD-{$owner}-2026-0001"]]);
        $this->getJson('/api/savings/time-deposits/cash-reference/next')->assertJsonPath('data.reference_number', "TD-{$owner}-2026-0001");
        $this->assertSame(0, DB::table('cash_reference_sequences')->count());

        $this->place()->assertCreated();
        $this->getJson('/api/savings/time-deposits/cash-reference/next')->assertJsonPath('data.reference_number', "TD-{$owner}-2026-0002");
    }

    public function test_the_preview_takes_the_placement_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-01 10:00:00', 'Asia/Manila'));
        $owner = $this->owner();
        $this->place(['placement_date' => '2026-12-31'])->assertCreated()->assertJsonPath('data.reference', "TD-{$owner}-2026-0001");

        $this->getJson('/api/savings/time-deposits/cash-reference/next?date=2026-12-31')->assertOk()->assertJsonPath('data.reference_number', "TD-{$owner}-2026-0002");
        $this->getJson('/api/savings/time-deposits/cash-reference/next')->assertOk()->assertJsonPath('data.reference_number', "TD-{$owner}-2027-0001");
        $this->getJson('/api/savings/time-deposits/cash-reference/next?date=20261231')
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'The placement date must be a date written as year-month-day, such as 2026-11-24.');
    }

    public function test_the_preview_needs_the_permission_to_place(): void
    {
        $this->actingAs($this->userWith(['savings:view']));
        $this->getJson('/api/savings/time-deposits/cash-reference/next')->assertForbidden();

        $this->actingAs($this->userWith(['savings:view', 'savings:create']));
        $this->getJson('/api/savings/time-deposits/cash-reference/next')->assertOk();
    }

    public function test_a_placement_whose_reference_a_deposit_already_used_on_the_same_method_is_refused_naming_only_the_reference(): void
    {
        $other = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $this->gcashDeposit($this->savingsAccount(0, $other), 'GC-778899');

        $message = $this->place(['payment_method' => 'gcash', 'reference' => 'GC-778899'])
            ->assertUnprocessable()
            ->json('errors.reference.0');

        $this->assertSame('Reference "GC-778899" is already on record for another GCash transaction. Check the reference and enter the right one.', $message);
        $this->assertStringNotContainsString($other->last_name, $message);
        $this->assertSame(0, TimeDeposit::query()->count());
    }

    public function test_a_placement_whose_reference_another_placement_used_is_refused(): void
    {
        $this->place(['payment_method' => 'bank_transfer', 'reference' => 'BDO-55'])->assertCreated();

        $this->place(['payment_method' => 'bank_transfer', 'reference' => 'bdo-55'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Reference "bdo-55" is already on record for another bank transfer. Check the reference and enter the right one.');
        $this->assertSame(1, TimeDeposit::query()->count());
    }

    public function test_the_same_reference_on_another_method_or_in_cash_is_accepted(): void
    {
        $this->place(['payment_method' => 'gcash', 'reference' => 'REF-1'])->assertCreated();
        $this->place(['payment_method' => 'maya', 'reference' => 'REF-1'])->assertCreated();
        $this->place(['payment_method' => 'cash', 'reference' => 'REF-1'])->assertCreated();

        $this->assertSame(3, TimeDeposit::query()->count());
    }

    /** The primary super admin's id as the number shows it. */
    private function owner(): string
    {
        $id = User::query()->where('status', 'active')
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'))
            ->min('id');

        return str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    private function gcashDeposit($account, string $reference): SavingsTransaction
    {
        return DB::transaction(fn () => app(SavingsLedger::class)->credit($account, 'deposit', 50_000, ['method' => 'gcash', 'external_reference' => $reference]));
    }
}

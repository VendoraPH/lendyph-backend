<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\AccountingJournal;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\Savings\PayoutReferences;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Finish run round 6: a time deposit paid out at maturity or on
 * pre-termination by bank transfer, GCash or Maya without its reference can
 * have it recorded afterwards (`POST /savings/time-deposits/{id}/payout-reference`):
 * once, only while blank, under the posting rules (trimmed, 100 characters,
 * no reuse on the same method). It needs the permission that posted that
 * payout (settle: savings:transact; pre-terminate: savings:close) and the
 * placement's branch, moves no money, posts nothing, and is audited in words.
 */
class TimeDepositRecordPayoutReferenceTest extends TestCase
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

    public function test_a_gcash_payout_at_maturity_gets_its_reference_once(): void
    {
        $deposit = $this->placed();
        $this->toMaturity();
        $this->settle($deposit, ['payout_method' => 'gcash'])->assertCreated()->assertJsonPath('data.can_record_reference', true);
        $journals = AccountingJournal::query()->count();

        $this->record($deposit, ' GC-TD-1 ')
            ->assertOk()
            ->assertJsonPath('data.payout_reference', 'GC-TD-1')
            ->assertJsonPath('data.status', 'withdrawn')
            ->assertJsonPath('data.can_record_reference', false);
        $this->assertSame($journals, AccountingJournal::query()->count());

        $audit = AuditLog::query()->where('action', 'savings_payout_reference_recorded')->sole();
        $this->assertSame([TimeDeposit::class, $deposit->id], [$audit->auditable_type, $audit->auditable_id]);
        $this->assertSame(['payout_reference' => null], $audit->old_values);
        $this->assertSame(['payout_reference' => 'GC-TD-1'], $audit->new_values);
        $paid = '₱'.number_format((float) $deposit->refresh()->settled_amount, 2);
        $this->assertSame("Reference \"GC-TD-1\" recorded on the maturity payout of time deposit {$deposit->certificate_number} (GCash, {$paid}).", $audit->description);

        $this->record($deposit, 'GC-TD-2')->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', "{$deposit->certificate_number} already has its payout reference (GC-TD-1). A recorded reference can't be changed.");
    }

    public function test_a_pretermination_payout_needs_savings_close(): void
    {
        $deposit = $this->placed();
        $this->preterminate($deposit, ['payout_method' => 'maya'])->assertCreated();

        $this->actingAs($this->userWith(['savings:view', 'savings:transact']));
        $this->getJson("/api/savings/time-deposits/{$deposit->id}")->assertJsonPath('data.can_record_reference', false);
        $this->record($deposit, 'MAYA-PT-1')->assertForbidden();

        $this->actingAs($this->userWith(['savings:view', 'savings:close']));
        $this->getJson("/api/savings/time-deposits/{$deposit->id}")->assertJsonPath('data.can_record_reference', true);
        $this->record($deposit, 'MAYA-PT-1')->assertOk()->assertJsonPath('data.payout_reference', 'MAYA-PT-1');
        $this->assertStringStartsWith("Reference \"MAYA-PT-1\" recorded on the pre-termination payout of time deposit {$deposit->certificate_number} (Maya, ",
            AuditLog::query()->where('action', 'savings_payout_reference_recorded')->sole()->description);
    }

    public function test_a_maturity_payout_needs_savings_transact(): void
    {
        $deposit = $this->placed();
        $this->toMaturity();
        $this->settle($deposit, ['payout_method' => 'bank_transfer'])->assertCreated();

        $this->actingAs($this->userWith(['savings:view', 'savings:close']));
        $this->record($deposit, 'BDO-1')->assertForbidden();
        $this->actingAs($this->userWith(['savings:view', 'savings:transact']));
        $this->record($deposit, 'BDO-1')->assertOk();
    }

    public function test_another_branchs_placement_answers_404(): void
    {
        $deposit = $this->placed();
        $this->toMaturity();
        $this->settle($deposit, ['payout_method' => 'gcash'])->assertCreated();

        $elsewhere = Branch::factory()->create();
        $role = Role::create(['name' => 'td_probe_'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $role->givePermissionTo(['savings:view', 'savings:transact']);
        $user = User::factory()->create(['branch_id' => $elsewhere->id]);
        $user->branches()->sync([$elsewhere->id]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);
        $this->record($deposit, 'GC-1')->assertNotFound();
    }

    public function test_placements_without_a_non_cash_payout_take_no_reference(): void
    {
        $open = $this->placed();
        $cash = $this->placed();
        $account = $this->savingsAccount();
        $intoSavings = $this->placed(['payout_account_id' => $account->id]);
        $rolled = $this->placed();
        $this->toMaturity();
        $this->settle($cash)->assertCreated();
        $this->settle($intoSavings, ['payout_method' => 'savings'])->assertCreated();
        $this->settle($rolled, ['action' => 'rollover_all', 'payout_method' => 'gcash'])->assertCreated();

        $this->record($open, 'X-1')->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', "{$open->certificate_number} hasn't been paid out yet. Its payout reference is recorded when it's settled.");
        $this->record($cash, 'X-1')->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', "{$cash->certificate_number} was paid out in cash. Only a payout by bank transfer, GCash or Maya takes its reference afterwards.");
        $this->record($intoSavings, 'X-1')->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', "{$intoSavings->certificate_number} was paid into the member's savings. Only a payout by bank transfer, GCash or Maya takes its reference afterwards.");
        $this->record($rolled, 'X-1')->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', "{$rolled->certificate_number} paid nothing out. Its whole amount was placed again.");
    }

    public function test_the_reference_follows_the_posting_rules(): void
    {
        $deposit = $this->placed();
        $other = $this->placed();
        $this->toMaturity();
        $this->settle($other, ['payout_method' => 'gcash', 'payout_reference' => 'GC-TAKEN'])->assertCreated();
        $this->settle($deposit, ['payout_method' => 'gcash'])->assertCreated();

        $this->record($deposit, '  ')->assertUnprocessable()->assertJsonPath('errors.reference.0', 'Enter the reference of this payout.');
        $this->record($deposit, str_repeat('A', 101))->assertUnprocessable()->assertJsonPath('errors.reference.0', 'The reference can be at most 100 characters.');
        $this->record($deposit, 'GC-TAKEN')->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Reference "GC-TAKEN" is already on record for another GCash transaction. Check the reference and enter the right one.');
        $this->record($deposit, str_repeat('A', 100))->assertOk();
    }

    public function test_a_reference_recorded_by_someone_else_first_answers_409(): void
    {
        $deposit = $this->placed();
        $this->toMaturity();
        $this->settle($deposit, ['payout_method' => 'gcash'])->assertCreated();
        $stale = TimeDeposit::query()->findOrFail($deposit->id);
        DB::table('time_deposits')->where('id', $deposit->id)->update(['payout_reference' => 'GC-FIRST']);

        try {
            DB::transaction(fn () => app(PayoutReferences::class)->recordOnTimeDeposit($stale, 'GC-SECOND', $this->admin));
            $this->fail('A second reference was recorded.');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame("Someone recorded the payout reference of {$deposit->certificate_number} a moment ago. Refresh to see it.", $e->getResponse()->getData(true)['message']);
        }

        $this->assertSame('GC-FIRST', $deposit->refresh()->payout_reference);
    }

    private function placed(array $overrides = []): TimeDeposit
    {
        return TimeDeposit::query()->findOrFail($this->place(['principal' => '9000.00', ...$overrides])->assertCreated()->json('data.id'));
    }

    private function toMaturity(): void
    {
        $this->travelTo(Carbon::parse('2027-02-08 09:00:00', 'Asia/Manila'));
    }

    private function settle(TimeDeposit $deposit, array $body = []): TestResponse
    {
        return $this->idem("/api/savings/time-deposits/{$deposit->id}/settle", ['date' => now()->toDateString(), 'action' => 'payout', 'payout_method' => 'cash', ...$body]);
    }

    private function preterminate(TimeDeposit $deposit, array $body = []): TestResponse
    {
        return $this->idem("/api/savings/time-deposits/{$deposit->id}/preterminate", [
            'date' => now()->toDateString(), 'reason' => 'Member needs the money for tuition.', 'payout_method' => 'cash', ...$body,
        ]);
    }

    private function record(TimeDeposit $deposit, string $reference): TestResponse
    {
        return $this->postJson("/api/savings/time-deposits/{$deposit->id}/payout-reference", ['reference' => $reference]);
    }
}

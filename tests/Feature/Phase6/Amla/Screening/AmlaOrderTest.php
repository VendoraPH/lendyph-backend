<?php

namespace Tests\Feature\Phase6\Amla\Screening;

use App\Exceptions\AmlaRecordIsAppendOnlyException;
use App\Models\AmlaLogEntry;
use App\Models\AmlaOrder;
use App\Models\AmlaOrderHold;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\SavingsAccount;
use App\Models\SavingsHold;
use App\Models\User;
use App\Services\Savings\AvailableBalance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The freeze-order register (AMLA design §3.5, decision 5; task §11). A person
 * with `amla:sanctions` and head-office access records an official order and
 * places savings holds for it. Those holds never expire: `savings:expire`
 * skips them, a savings hold release refuses them, and unfreezing an account
 * leaves them in place. Only the order's own release, by `amla:sanctions`,
 * lifts them. Nothing freezes or holds by itself. The holds and their
 * ordinary savings audit rows are the only writes outside AMLA, and they say
 * only "legal order".
 */
class AmlaOrderTest extends TestCase
{
    use ScreeningTestHelpers, SetupLendyPH;

    private Borrower $subject;

    private SavingsAccount $account;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow('2026-11-21 10:00:00');
        $this->subject = $this->member();
        $this->account = SavingsAccount::factory()->create(['borrower_id' => $this->subject->id, 'branch_id' => $this->subject->branch_id, 'ledger_balance' => '80000.00']);
        $this->officer = $this->sanctionsOfficer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $overrides */
    private function place(array $overrides = [], ?string $key = null): TestResponse
    {
        return $this->actingAs($this->officer)->postJson('/api/amla/orders', [
            'reference' => 'AMLC Freeze Order 2026-0042',
            'authority' => 'amlc',
            'received_on' => '2026-11-21',
            'borrower_id' => $this->subject->id,
            'required_action' => 'Freeze the funds for 20 days and make a return.',
            'responsible_officer_id' => $this->officer->id,
            'return_due_on' => '2026-11-26',
            'holds' => [['account_id' => $this->account->id, 'amount' => '50000.00']],
            ...$overrides,
        ], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_placing_an_order_puts_holds_that_never_expire_and_writes_only_those_outside_amla(): void
    {
        $lastAudit = (int) AuditLog::query()->max('id');
        $notifications = DB::table('notifications')->count();

        $order = $this->place()->assertCreated()->json('data');

        $this->assertSame('active', $order['status']);
        $this->assertSame('50000.00', $order['holds'][0]['amount']);
        $this->assertSame(['id', 'status', 'holds'], array_keys($order), 'the reply kept for replays holds no order details');
        $hold = SavingsHold::query()->sole();
        $this->assertSame('legal', $hold->type);
        $this->assertNull($hold->expires_on);
        $this->assertSame('Held under a legal order.', $hold->reason);
        $this->assertNull($hold->source_type, 'the hold names no AMLA record');
        $this->assertSame($hold->id, AmlaOrderHold::query()->sole()->savings_hold_id);

        $newAudit = AuditLog::query()->where('id', '>', $lastAudit)->get();
        $this->assertCount(1, $newAudit, 'one audit row: the savings hold itself');
        $this->assertSame(SavingsHold::class, $newAudit[0]->auditable_type);
        $this->assertDoesNotMatchRegularExpression('/amla|freeze order|2026-0042|return/i', json_encode($newAudit[0]->toArray()));
        $this->assertSame($notifications, DB::table('notifications')->count());
        $this->assertSame(1, AmlaLogEntry::query()->where('action', 'order_placed')->count());
        $this->actingAs($this->officer)->getJson("/api/amla/orders/{$order['id']}")->assertOk()->assertJsonPath('data.authority_words', 'AMLC');
    }

    public function test_holds_must_fit_the_balance_not_already_held_and_be_the_members_own_accounts(): void
    {
        $this->place(['holds' => [['account_id' => $this->account->id, 'amount' => '80000.01']]])->assertUnprocessable();

        $someoneElse = SavingsAccount::factory()->create(['ledger_balance' => '1000.00']);
        $this->place(['holds' => [['account_id' => $someoneElse->id, 'amount' => '10.00']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['holds.0.account_id']);

        $this->place(['reference' => ''])->assertUnprocessable()->assertJsonValidationErrors(['reference']);

        $this->assertSame(0, SavingsHold::query()->count());
        $this->assertSame(0, AmlaOrder::query()->count());

        $this->place(['holds' => []])->assertCreated()->assertJsonPath('data.holds', []);
        $this->assertSame(0, SavingsHold::query()->count(), 'an order with no savings holds is still recorded');
    }

    public function test_a_savings_hold_release_refuses_an_order_hold_without_naming_amla(): void
    {
        $this->place()->assertCreated();
        $hold = SavingsHold::query()->sole();

        $message = $this->actingAs($this->admin)->postJson("/api/savings/holds/{$hold->id}/release", ['reason' => 'Lifted'])
            ->assertUnprocessable()->json('errors.hold.0');

        $this->assertSame('This hold was placed under a legal order. Only that order\'s release can lift it.', $message);
        $this->assertStringNotContainsStringIgnoringCase('amla', $message);
        $this->assertSame('active', $hold->fresh()->status);
    }

    public function test_savings_expire_skips_an_order_hold_even_with_an_end_date(): void
    {
        $this->place()->assertCreated();
        $hold = SavingsHold::query()->sole();
        DB::table('savings_holds')->where('id', $hold->id)->update(['expires_on' => '2026-11-01']);
        $ordinary = SavingsHold::query()->create([
            'account_id' => $this->account->id, 'type' => 'manual', 'amount' => '100.00', 'reason' => 'Teller hold',
            'placed_on' => '2026-10-01', 'expires_on' => '2026-11-01', 'status' => 'active', 'created_by' => $this->admin->id,
        ]);

        Artisan::call('savings:expire');

        $this->assertSame('active', $hold->fresh()->status);
        $this->assertSame('expired', $ordinary->fresh()->status);
    }

    public function test_unfreezing_the_account_leaves_the_order_holds_in_place(): void
    {
        $this->place()->assertCreated();
        $this->actingAs($this->admin)->postJson("/api/savings/accounts/{$this->account->id}/status", ['status' => 'frozen', 'reason' => 'Check'])->assertOk();
        $this->actingAs($this->admin)->postJson("/api/savings/accounts/{$this->account->id}/status", ['status' => 'active', 'reason' => 'Done'])->assertOk();

        $this->assertSame('active', SavingsHold::query()->sole()->status);
    }

    public function test_only_the_orders_release_lifts_its_holds_and_it_takes_two_people(): void
    {
        $id = $this->place()->assertCreated()->json('data.id');
        $branchOfficer = $this->amlaUser(['amla:sanctions']);

        $this->actingAs($branchOfficer)->postJson("/api/amla/orders/{$id}/release", ['reason' => 'x', 'release_reference' => 'y'])->assertForbidden();
        $this->actingAs($this->amlaUser(['amla:screening', 'branches:view_all']))->postJson("/api/amla/orders/{$id}/release", ['reason' => 'x', 'release_reference' => 'y'])->assertForbidden();
        $this->actingAs($this->officer)->postJson("/api/amla/orders/{$id}/release", [])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'release_reference']);

        $this->actingAs($this->officer)->postJson("/api/amla/orders/{$id}/release", ['reason' => 'AMLC lifted the freeze.', 'release_reference' => 'AMLC Resolution 2026-77'])
            ->assertStatus(202)->assertJsonPath('data.status', 'active')->assertJsonPath('data.pending_release.requested_by.id', $this->officer->id);
        $this->assertSame('active', SavingsHold::query()->sole()->status, 'asking lifts nothing');

        $this->actingAs($this->officer)->postJson("/api/amla/orders/{$id}/release/approve")->assertForbidden()
            ->assertJsonPath('message', 'You asked for this release, so another AMLA sanctions holder must approve it.');
        $this->actingAs($this->officer)->postJson("/api/amla/orders/{$id}/release", ['reason' => 'again', 'release_reference' => 'z'])
            ->assertUnprocessable()->assertJsonPath('message', 'A release of this order is already waiting for approval.');

        $checker = $this->sanctionsOfficer();
        $released = $this->actingAs($checker)->postJson("/api/amla/orders/{$id}/release/approve", ['note' => 'Resolution checked.'])->assertOk()->json('data');

        $this->assertSame('released', $released['status']);
        $this->assertSame($checker->id, $released['released_by']['id']);
        $this->assertSame($this->officer->id, $released['release_requested_by']['id']);
        $hold = SavingsHold::query()->sole();
        $this->assertSame('released', $hold->status);
        $this->assertSame('Released when the legal order was lifted.', $hold->release_reason);
        $this->assertNotSame('AMLC lifted the freeze.', AmlaOrder::query()->sole()->getRawOriginal('release_reason'));

        $this->actingAs($this->officer)->postJson("/api/amla/orders/{$id}/release", ['reason' => 'again', 'release_reference' => 'z'])
            ->assertConflict()->assertJsonPath('message', 'This order is already released.');
    }

    public function test_a_release_can_be_rejected_by_another_holder_or_withdrawn_by_its_asker_and_super_admin_never_approves_its_own(): void
    {
        $id = $this->place()->assertCreated()->json('data.id');
        $other = $this->sanctionsOfficer();
        $body = ['reason' => 'Lifted.', 'release_reference' => 'R-1'];

        $this->actingAs($this->officer)->postJson("/api/amla/orders/{$id}/release", $body)->assertStatus(202);
        $this->actingAs($this->officer)->postJson("/api/amla/orders/{$id}/release/reject", ['note' => 'Mine.'])->assertForbidden();
        $this->actingAs($other)->postJson("/api/amla/orders/{$id}/release/reject")->assertUnprocessable()->assertJsonValidationErrors(['note']);
        $this->actingAs($other)->postJson("/api/amla/orders/{$id}/release/reject", ['note' => 'No lifting order attached.'])->assertOk()->assertJsonPath('data.pending_release', null);

        $this->actingAs($this->officer)->postJson("/api/amla/orders/{$id}/release", $body)->assertStatus(202);
        $this->actingAs($other)->postJson("/api/amla/orders/{$id}/release/withdraw")->assertForbidden();
        $this->actingAs($this->officer)->postJson("/api/amla/orders/{$id}/release/withdraw")->assertOk();

        $this->actingAs($this->admin)->postJson("/api/amla/orders/{$id}/release", $body)->assertStatus(202);
        $this->actingAs($this->admin)->postJson("/api/amla/orders/{$id}/release/approve")->assertForbidden();
        $this->actingAs($this->officer)->postJson("/api/amla/orders/{$id}/release/approve")->assertOk()->assertJsonPath('data.status', 'released');
    }

    public function test_an_order_is_recorded_once_per_idempotency_key(): void
    {
        $key = (string) Str::uuid();

        $first = $this->place([], $key)->assertCreated()->json('data.id');
        $this->place([], $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first);

        $this->assertSame(1, AmlaOrder::query()->count());
        $this->assertSame(1, SavingsHold::query()->count());
        $this->actingAs($this->officer)->postJson('/api/amla/orders', [
            'reference' => 'Order 2', 'authority' => 'court', 'received_on' => '2026-11-21', 'borrower_id' => $this->subject->id,
            'required_action' => 'Freeze.', 'holds' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_an_order_holds_money_already_under_other_holds_and_keeps_it_through_their_release_and_expiry(): void
    {
        $staff = SavingsHold::query()->create([
            'account_id' => $this->account->id, 'type' => 'manual', 'amount' => '80000.00', 'reason' => 'Teller hold',
            'placed_on' => '2026-11-01', 'expires_on' => '2026-11-25', 'status' => 'active', 'created_by' => $this->admin->id,
        ]);

        $this->place(['holds' => [['account_id' => $this->account->id, 'amount' => '80000.00']]])->assertCreated();
        $this->place(['holds' => [['account_id' => $this->account->id, 'amount' => '0.01']]])->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', '₱0.01 is more than the ₱0.00 on '.$this->account->account_number.' not already held by another legal order.');

        $this->actingAs($this->admin)->postJson("/api/savings/holds/{$staff->id}/release", ['reason' => 'Done'])->assertOk();
        $this->assertSame(0, app(AvailableBalance::class)->for($this->account->fresh()));

        Carbon::setTestNow('2026-11-27 01:00:00');
        Artisan::call('savings:expire');
        $this->assertSame(0, app(AvailableBalance::class)->for($this->account->fresh()));
        $this->assertSame(1, SavingsHold::query()->where('status', 'active')->count());
    }

    public function test_a_teller_withdrawal_and_an_account_close_leave_the_orders_money_held(): void
    {
        $this->place()->assertCreated();

        $this->actingAs($this->admin)->postJson("/api/savings/accounts/{$this->account->id}/withdrawals", [
            'amount' => '30000.01', 'date' => '2026-11-21', 'payment_method' => 'cash',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->actingAs($this->admin)->postJson("/api/savings/accounts/{$this->account->id}/close", [
            'date' => '2026-11-21', 'reason' => 'Member leaving the cooperative.', 'payout_method' => 'cash',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();

        $this->assertSame('active', SavingsHold::query()->sole()->status);
        $this->assertSame('80000.00', $this->account->fresh()->ledger_balance);
    }

    public function test_orders_are_head_office_only_logged_on_view_and_kept_forever(): void
    {
        $id = $this->place()->assertCreated()->json('data.id');

        $this->actingAs($this->amlaUser(['amla:sanctions']))->getJson('/api/amla/orders')->assertForbidden();
        $this->actingAs($this->amlaUser(['amla:screening', 'branches:view_all']))->getJson("/api/amla/orders/{$id}")->assertForbidden();
        $this->actingAs($this->amlaUser(['amla:screening', 'branches:view_all']))->getJson('/api/amla/orders/999999')->assertForbidden();
        $this->actingAs($this->officer)->getJson('/api/amla/orders/999999')->assertNotFound();

        $this->actingAs($this->officer)->getJson("/api/amla/orders/{$id}")->assertOk()
            ->assertJsonPath('data.reference', 'AMLC Freeze Order 2026-0042')
            ->assertJsonPath('data.required_action', 'Freeze the funds for 20 days and make a return.');
        $this->assertSame(1, AmlaLogEntry::query()->where('action', 'order_viewed')->where('subject_id', $id)->count());

        $this->expectException(AmlaRecordIsAppendOnlyException::class);
        AmlaOrder::query()->findOrFail($id)->delete();
    }
}

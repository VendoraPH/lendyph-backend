<?php

namespace Tests\Feature\Savings\Servicing;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\PortalSetting;
use App\Models\PortalUser;
use App\Models\SavingsProduct;
use App\Models\SavingsSetting;
use App\Models\TimeDeposit;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The savings notices (gap round S4, Savings TASK 13 and 47), in-app only:
 * staff notifications and the member's portal notices where the portal is on.
 *
 * - The nightly `savings:notices`: time deposits at each `notice_days` mark
 *   and the dormancy warning, de-duplicated per deposit or account and
 *   day-mark, so a re-run sends nothing new.
 * - Account events from their audit rows: dormant, reactivated, frozen and
 *   closed.
 * - "Approval waiting" to savings:approve holders who can see the branch,
 *   never the maker.
 */
class SavingsNoticesTest extends TestCase
{
    use PortalTestHelpers, SavingsAccountsTestHelpers, SetupLendyPH;

    private User $viewer;

    private User $elsewhereViewer;

    private PortalUser $portalUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        $this->viewer = $this->userWith(['savings:view']);
        $this->elsewhereViewer = $this->userWith(['savings:view'], Branch::factory()->create());
        $this->enablePortal();
        $this->portalUser = PortalUser::factory()->create(['borrower_id' => $this->member->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<int> the staff told with `$type` */
    private function told(string $type): array
    {
        return DB::table('notifications')->where('type', $type)->where('notifiable_type', User::class)
            ->orderBy('notifiable_id')->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<string> the member's portal notice titles, oldest first */
    private function memberTitles(): array
    {
        return DB::table('notifications')->where('notifiable_type', PortalUser::class)->where('notifiable_id', $this->portalUser->id)
            ->orderBy('created_at')->orderBy('id')->get()->map(fn ($row) => json_decode($row->data, true)['title'])->all();
    }

    private function deposit(string $maturity, array $overrides = []): TimeDeposit
    {
        return TimeDeposit::factory()->create([
            'borrower_id' => $this->member->id,
            'product_id' => SavingsProduct::factory()->timeDeposit()->create()->id,
            'placement_date' => '2026-08-12',
            'maturity_date' => $maturity,
            'maturity_amount' => '50369.86',
            ...$overrides,
        ]);
    }

    // ── Time deposit maturing ──

    public function test_time_deposits_are_announced_at_each_notice_day_once(): void
    {
        $in30 = $this->deposit('2026-12-10');
        $in14 = $this->deposit('2026-11-24');
        $in31 = $this->deposit('2026-12-11');
        $today = $this->deposit('2026-11-10', ['status' => 'matured']);
        $this->deposit('2026-11-10', ['status' => 'preterminated']);

        $this->artisan('savings:notices')->expectsOutputToContain('Sent 3 time deposit maturing notice(s)')->assertSuccessful();

        $keys = DB::table('savings_notice_log')->where('type', 'savings_time_deposit_maturing')->pluck('notice_key')->sort()->values()->all();
        $this->assertSame(collect([
            "td_maturing:{$in14->id}:2026-11-24:15",
            "td_maturing:{$in30->id}:2026-12-10:30",
            "td_maturing:{$today->id}:2026-11-10:0",
        ])->sort()->values()->all(), $keys);

        $rows = DB::table('notifications')->where('type', 'savings_time_deposit_maturing')->where('notifiable_id', $this->viewer->id)->get();
        $this->assertCount(3, $rows);
        $bodies = $rows->map(fn ($row) => json_decode($row->data, true)['body'])->all();
        $this->assertContains("Time deposit {$in14->certificate_number} (Maria Santos) matures on Nov 24, 2026, in 14 days. Maturity amount: ₱50,369.86.", $bodies);
        $this->assertContains("Time deposit {$today->certificate_number} (Maria Santos) matures today. Maturity amount: ₱50,369.86.", $bodies);
        $this->assertNotContains($this->elsewhereViewer->id, $this->told('savings_time_deposit_maturing'));
        $this->assertContains($this->admin->id, $this->told('savings_time_deposit_maturing'));

        $this->assertEqualsCanonicalizing(['Time deposit maturing', 'Time deposit maturing', 'Time deposit matures today'], $this->memberTitles());

        $this->artisan('savings:notices')->expectsOutputToContain('Sent 0 time deposit maturing notice(s)')->assertSuccessful();
        $this->assertCount(3, $this->memberTitles());

        $this->on('2026-11-11', fn () => $this->artisan('savings:notices')->expectsOutputToContain('Sent 1 time deposit maturing notice(s)')->run());
        $this->assertSame(1, DB::table('savings_notice_log')->where('notice_key', "td_maturing:{$in31->id}:2026-12-11:30")->count());
    }

    public function test_the_day_marks_follow_the_settings_and_a_missed_night_catches_up_once(): void
    {
        SavingsSetting::current()->forceFill(['notice_days' => [7, 3]])->save();
        $in5 = $this->deposit('2026-11-15');
        $this->deposit('2026-11-10');

        $this->artisan('savings:notices')->expectsOutputToContain('Sent 1 time deposit maturing notice(s)');
        $this->assertSame(["td_maturing:{$in5->id}:2026-11-15:7"], DB::table('savings_notice_log')->pluck('notice_key')->all());

        $this->on('2026-11-13', fn () => $this->artisan('savings:notices')->expectsOutputToContain('Sent 1 time deposit maturing notice(s)')->run());
        $this->on('2026-11-14', fn () => $this->artisan('savings:notices')->expectsOutputToContain('Sent 0 time deposit maturing notice(s)')->run());
        $this->assertSame(2, DB::table('savings_notice_log')->count());
    }

    // ── Dormancy warning ──

    public function test_the_dormancy_warning_goes_out_once_when_an_account_enters_the_window(): void
    {
        $account = $this->account(['last_member_activity_on' => '2024-12-20', 'opened_on' => '2024-01-02']);
        $this->account(['borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id, 'last_member_activity_on' => '2025-02-01']);

        $this->artisan('savings:notices')->expectsOutputToContain('and 1 dormancy warning(s)')->assertSuccessful();

        $row = DB::table('notifications')->where('type', 'savings_dormancy_warning')->where('notifiable_id', $this->viewer->id)->first();
        $this->assertSame(
            "Savings account {$account->account_number} (Maria Santos) turns dormant on Dec 20, 2026, in 40 days, unless the member makes a deposit, withdrawal or transfer before then.",
            json_decode($row->data, true)['body'],
        );
        $this->assertNotContains($this->elsewhereViewer->id, $this->told('savings_dormancy_warning'));
        $this->assertSame(['Savings account inactive soon'], $this->memberTitles());

        $this->artisan('savings:notices')->expectsOutputToContain('and 0 dormancy warning(s)');
        $this->on('2026-11-20', fn () => $this->artisan('savings:notices')->expectsOutputToContain('and 0 dormancy warning(s)')->run());
        $this->assertSame(["dormancy_warning:{$account->id}:2026-12-20"], DB::table('savings_notice_log')->pluck('notice_key')->all());
    }

    public function test_with_the_portal_off_only_staff_are_told(): void
    {
        PortalSetting::query()->orderBy('id')->firstOrFail()->forceFill(['enabled' => false])->save();
        $this->account(['last_member_activity_on' => '2024-12-20', 'opened_on' => '2024-01-02']);
        $this->deposit('2026-11-17');

        $this->artisan('savings:notices')->expectsOutputToContain('Sent 1 time deposit maturing notice(s) and 1 dormancy warning(s)');

        $this->assertContains($this->viewer->id, $this->told('savings_dormancy_warning'));
        $this->assertContains($this->viewer->id, $this->told('savings_time_deposit_maturing'));
        $this->assertSame([], $this->memberTitles());
    }

    public function test_a_member_whose_portal_account_is_not_active_gets_nothing(): void
    {
        $this->portalUser->forceFill(['status' => PortalUser::STATUS_LOCKED])->save();
        $this->deposit('2026-11-17');

        $this->artisan('savings:notices')->assertSuccessful();
        $this->assertSame([], $this->memberTitles());
        $this->assertContains($this->viewer->id, $this->told('savings_time_deposit_maturing'));
    }

    // ── Account events ──

    public function test_dormant_reactivated_frozen_and_closed_tell_the_member(): void
    {
        $account = $this->account(['last_member_activity_on' => '2024-11-01', 'opened_on' => '2024-01-02']);

        $this->artisan('savings:mark-dormant')->assertSuccessful();
        $this->assertSame('dormant', $account->refresh()->status);
        $this->assertContains($this->viewer->id, $this->told('savings_account_dormant'));
        $this->assertNotContains($this->elsewhereViewer->id, $this->told('savings_account_dormant'));

        $this->fund($account, 100_000);
        $this->actingAs($this->teller());
        $this->postJson("/api/savings/accounts/{$account->id}/status", ['status' => 'frozen', 'reason' => 'Signature check'])->assertOk();
        $this->postJson("/api/savings/accounts/{$account->id}/status", ['status' => 'active', 'reason' => 'Resolved'])->assertOk();
        $this->money("/api/savings/accounts/{$account->id}/close", ['date' => '2026-11-10', 'reason' => 'Member request', 'payout_method' => 'cash'])->assertCreated();

        $titles = array_values(array_filter($this->memberTitles(), fn (string $title) => str_starts_with($title, 'Savings account')));
        $this->assertEqualsCanonicalizing(['Savings account dormant', 'Savings account active again', 'Savings account frozen', 'Savings account closed'], $titles);

        $bodies = DB::table('notifications')->where('notifiable_type', PortalUser::class)->pluck('data')->map(fn ($d) => json_decode($d, true)['body'])->implode(' ');
        $this->assertStringNotContainsString('Signature check', $bodies);
    }

    public function test_an_approved_reactivation_tells_the_member(): void
    {
        $account = $this->account(['status' => 'dormant', 'dormant_since' => '2026-11-01', 'last_member_activity_on' => '2024-10-31']);
        $this->actingAs($this->teller());
        $approvalId = $this->postJson("/api/savings/accounts/{$account->id}/status", ['status' => 'active', 'reason' => 'Member came in'])->json('data.approval.id');
        $this->assertSame([], $this->memberTitles());

        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$approvalId}/approve")->assertOk();
        $this->assertSame(['Savings account active again'], $this->memberTitles());
    }

    // ── Approval waiting ──

    public function test_approval_waiting_goes_to_checkers_in_the_branch_never_the_maker(): void
    {
        $maker = $this->userWith(['savings:view', 'savings:close', 'savings:transact', 'savings:approve']);
        $checker = $this->checker();
        $elsewhereChecker = $this->userWith(['savings:view', 'savings:approve'], Branch::factory()->create());
        $dormant = $this->account(['status' => 'dormant', 'dormant_since' => '2026-11-01', 'last_member_activity_on' => '2024-10-31']);

        $this->actingAs($maker)->postJson("/api/savings/accounts/{$dormant->id}/status", ['status' => 'active', 'reason' => 'Member came in'])->assertStatus(202);

        $told = $this->told('savings_approval_waiting');
        $this->assertContains($checker->id, $told);
        $this->assertContains($this->admin->id, $told);
        $this->assertNotContains($maker->id, $told);
        $this->assertNotContains($elsewhereChecker->id, $told);
        $this->assertNotContains($this->viewer->id, $told);

        $row = DB::table('notifications')->where('type', 'savings_approval_waiting')->where('notifiable_id', $checker->id)->first();
        $data = json_decode($row->data, true);
        $this->assertSame("A reactivation on {$dormant->account_number} (Maria Santos) is waiting for approval, requested by {$maker->full_name}.", $data['body']);
        $this->assertSame('/savings/approvals', $data['url']);
        $this->assertSame('reactivation', $data['data']['kind']);
    }

    public function test_a_withdrawal_waiting_for_approval_names_its_amount(): void
    {
        $account = $this->account();
        $this->fund($account, 5_000_000);
        $checker = $this->checker();

        $this->actingAs($this->teller());
        $this->money("/api/savings/accounts/{$account->id}/withdrawals", ['amount' => '20000.00', 'date' => '2026-11-10', 'payment_method' => 'cash'])->assertStatus(202);

        $row = DB::table('notifications')->where('type', 'savings_approval_waiting')->where('notifiable_id', $checker->id)->first();
        $this->assertStringContainsString("A withdrawal on {$account->account_number} (Maria Santos) for ₱20,000.00 is waiting for approval", json_decode($row->data, true)['body']);
        $this->assertSame([], array_values(array_filter($this->memberTitles(), fn (string $title) => $title !== 'Deposit posted')));
    }

    // ── Settings ──

    public function test_the_notice_settings_are_read_and_saved(): void
    {
        $this->actingAs($this->admin);
        $this->getJson('/api/savings/settings')->assertOk()
            ->assertJsonPath('data.notice_days', [30, 15, 7, 3, 0])
            ->assertJsonPath('data.dormancy_warning_days', 60);

        $this->putJson('/api/savings/settings', ['notice_days' => [0, 10, 45], 'dormancy_warning_days' => 30])->assertOk()
            ->assertJsonPath('data.notice_days', [45, 10, 0])
            ->assertJsonPath('data.dormancy_warning_days', 30);
        $this->putJson('/api/savings/settings', ['notice_days' => []])->assertOk()->assertJsonPath('data.notice_days', []);

        $this->putJson('/api/savings/settings', ['notice_days' => [7, 7]])->assertUnprocessable()
            ->assertJsonPath('errors', ['notice_days.0' => ['Each notice day can be listed once.'], 'notice_days.1' => ['Each notice day can be listed once.']]);
        $this->assertSame(
            ['Each notice day must be a whole number of days, from 0 (the maturity day) to 365.'],
            $this->putJson('/api/savings/settings', ['notice_days' => [400]])->assertUnprocessable()->json('errors')['notice_days.0'],
        );
        $this->putJson('/api/savings/settings', ['dormancy_warning_days' => 366])->assertUnprocessable()
            ->assertJsonPath('errors.dormancy_warning_days.0', 'The warning before an account turns dormant can be 0 to 365 days.');
    }

    public function test_the_nightly_notices_run_after_the_maturity_job(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'savings:notices'));

        $this->assertCount(1, $events);
        $this->assertSame('50 0 * * *', $events->first()->expression);
        $this->assertSame('Asia/Manila', $events->first()->timezone);
    }
}

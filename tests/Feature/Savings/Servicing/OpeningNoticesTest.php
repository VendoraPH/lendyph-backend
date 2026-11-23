<?php

namespace Tests\Feature\Savings\Servicing;

use App\Models\Branch;
use App\Models\PortalUser;
use App\Models\SavingsProduct;
use App\Models\SavingsProductRate;
use App\Models\User;
use App\Services\Savings\Api\AccountOpener;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * "Account opened" and "time deposit placed" (Savings TASK 47), through the
 * channels of the other savings notices: the `savings:view` holders who can
 * see the branch, never the person who did it, and the member in the portal.
 * A rollover is a new placement and is told as one. Sent after commit, so an
 * opening that is rolled back tells nobody.
 */
class OpeningNoticesTest extends TestCase
{
    use PortalTestHelpers, SavingsAccountsTestHelpers, SetupLendyPH;

    private User $teller;

    private User $viewer;

    private User $elsewhereViewer;

    private PortalUser $portalUser;

    private SavingsProduct $timeProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        $this->teller = $this->teller();
        $this->viewer = $this->userWith(['savings:view']);
        $this->elsewhereViewer = $this->userWith(['savings:view'], Branch::factory()->create());
        $this->enablePortal();
        $this->portalUser = PortalUser::factory()->create(['borrower_id' => $this->member->id]);
        $this->timeProduct = SavingsProduct::factory()->timeDeposit()->create(['name' => 'Time Deposit 90', 'pretermination_product_id' => $this->product->id]);
        SavingsProductRate::factory()->create(['product_id' => $this->timeProduct->id, 'term_days' => 90, 'annual_rate' => '3.0000', 'effective_from' => '2026-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<int, array<string, mixed>> recipient id => the staff notice of `$type` */
    private function staffNotices(string $type): array
    {
        return DB::table('notifications')->where('type', $type)->where('notifiable_type', User::class)->orderBy('id')->get()
            ->mapWithKeys(fn ($row) => [(int) $row->notifiable_id => json_decode($row->data, true)])->all();
    }

    /** @return list<array{0: string, 1: string}> the member's portal notices as [title, body], oldest first */
    private function memberNotices(): array
    {
        return DB::table('notifications')->where('notifiable_type', PortalUser::class)->where('notifiable_id', $this->portalUser->id)
            ->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($row) => [json_decode($row->data, true)['title'], json_decode($row->data, true)['body']])->all();
    }

    /** @return array<string, mixed> */
    private function placement(array $overrides = []): array
    {
        return [
            'borrower_id' => $this->member->id,
            'product_id' => $this->timeProduct->id,
            'principal' => '50000.00',
            'term_days' => 90,
            'placement_date' => '2026-11-10',
            'maturity_instruction' => 'rollover_all',
            'payment_method' => 'cash',
            ...$overrides,
        ];
    }

    public function test_an_opened_account_tells_the_branchs_savings_staff_and_the_member(): void
    {
        $account = $this->actingAs($this->teller)->money('/api/savings/accounts', [
            'borrower_id' => $this->member->id,
            'product_id' => $this->product->id,
            'opened_at' => '2026-11-10',
            'initial_deposit' => '1500.00',
            'payment_method' => 'cash',
        ])->assertCreated()->json('data');

        $told = $this->staffNotices('savings_account_opened');
        $this->assertArrayHasKey($this->viewer->id, $told);
        $this->assertArrayHasKey($this->admin->id, $told);
        $this->assertArrayNotHasKey($this->teller->id, $told, 'Never the person who opened it.');
        $this->assertArrayNotHasKey($this->elsewhereViewer->id, $told, 'Only staff who can see the branch.');
        $this->assertSame([
            'type' => 'savings_account_opened',
            'title' => 'Savings account opened',
            'body' => "Regular Savings account {$account['account_number']} (Maria Santos) was opened on Nov 10, 2026, with an opening deposit of ₱1,500.00.",
            'url' => "/savings/accounts/{$account['id']}",
            'data' => ['account_id' => $account['id']],
        ], $told[$this->viewer->id]);

        $this->assertContains(['Savings account opened', "Your Regular Savings account {$account['account_number']} was opened on Nov 10, 2026."], $this->memberNotices());
    }

    public function test_an_opening_with_no_deposit_says_so(): void
    {
        $this->product->forceFill(['minimum_opening_balance' => '0.00'])->save();

        $number = $this->actingAs($this->teller)->money('/api/savings/accounts', [
            'borrower_id' => $this->member->id,
            'product_id' => $this->product->id,
            'opened_at' => '2026-11-10',
            'initial_deposit' => '0',
        ])->assertCreated()->json('data.account_number');

        $this->assertSame("Regular Savings account {$number} (Maria Santos) was opened on Nov 10, 2026, with no opening deposit.", $this->staffNotices('savings_account_opened')[$this->viewer->id]['body']);
        $this->assertSame([['Savings account opened', "Your Regular Savings account {$number} was opened on Nov 10, 2026."]], $this->memberNotices());
    }

    public function test_an_opening_rolled_back_after_it_was_written_tells_nobody(): void
    {
        try {
            DB::transaction(function (): void {
                app(AccountOpener::class)->open([
                    'borrower_id' => $this->member->id,
                    'product_id' => $this->product->id,
                    'opened_at' => '2026-11-10',
                    'initial_deposit' => '1500.00',
                    'payment_method' => 'cash',
                ], $this->teller);

                throw new RuntimeException('A later step failed.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->staffNotices('savings_account_opened'));
        $this->assertSame([], $this->memberNotices());
    }

    public function test_a_placed_time_deposit_tells_the_branchs_savings_staff_and_the_member(): void
    {
        $deposit = $this->actingAs($this->teller)->money('/api/savings/time-deposits', $this->placement())->assertCreated()->json('data');
        $this->assertSame('2027-02-08', $deposit['maturity_date']);
        $this->assertSame('50369.86', $deposit['maturity_amount']);

        $told = $this->staffNotices('savings_time_deposit_placed');
        $this->assertArrayHasKey($this->viewer->id, $told);
        $this->assertArrayNotHasKey($this->teller->id, $told, 'Never the person who placed it.');
        $this->assertArrayNotHasKey($this->elsewhereViewer->id, $told);
        $this->assertSame([
            'type' => 'savings_time_deposit_placed',
            'title' => 'Time deposit placed',
            'body' => "Time deposit {$deposit['certificate_number']} (Maria Santos) was placed on Nov 10, 2026: ₱50,000.00 for 90 days, maturing on Feb 8, 2027. Maturity amount: ₱50,369.86.",
            'url' => "/savings/time-deposits/{$deposit['id']}",
            'data' => ['time_deposit_id' => $deposit['id'], 'rolled_over_from_id' => null],
        ], $told[$this->viewer->id]);

        $this->assertSame([['Time deposit placed', "Your time deposit {$deposit['certificate_number']} of ₱50,000.00 was placed on Nov 10, 2026 for 90 days. It matures on Feb 8, 2027. Maturity amount: ₱50,369.86."]], $this->memberNotices());
    }

    public function test_a_rollover_at_settlement_is_told_as_a_new_placement(): void
    {
        $first = $this->actingAs($this->teller)->money('/api/savings/time-deposits', $this->placement())->assertCreated()->json('data');
        DB::table('notifications')->delete();

        $new = $this->on('2027-02-08', fn () => $this->actingAs($this->teller)->money("/api/savings/time-deposits/{$first['id']}/settle", [
            'date' => '2027-02-08', 'action' => 'rollover_all', 'payout_method' => 'cash',
        ])->assertCreated()->json('data'));
        $this->assertSame($first['id'], $new['rolled_over_from_id']);

        $told = $this->staffNotices('savings_time_deposit_placed');
        $this->assertArrayHasKey($this->viewer->id, $told);
        $this->assertArrayNotHasKey($this->teller->id, $told, 'Never the person who settled it.');
        $this->assertSame('Time deposit rolled over', $told[$this->viewer->id]['title']);
        $this->assertSame("Time deposit {$first['certificate_number']} (Maria Santos) was rolled over into {$new['certificate_number']}: ₱50,369.86 for 90 days from Feb 8, 2027, maturing on {$this->day($new['maturity_date'])}. Maturity amount: ₱".number_format((float) $new['maturity_amount'], 2).'.', $told[$this->viewer->id]['body']);
        $this->assertSame(['time_deposit_id' => $new['id'], 'rolled_over_from_id' => $first['id']], $told[$this->viewer->id]['data']);

        $this->assertSame([['Time deposit rolled over', "Your time deposit {$first['certificate_number']} was rolled over into {$new['certificate_number']}: ₱50,369.86 for 90 days from Feb 8, 2027. It matures on {$this->day($new['maturity_date'])}. Maturity amount: ₱".number_format((float) $new['maturity_amount'], 2).'.']], $this->memberNotices());
    }

    private function day(string $date): string
    {
        return Carbon::parse($date)->format('M j, Y');
    }
}

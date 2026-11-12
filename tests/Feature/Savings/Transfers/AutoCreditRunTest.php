<?php

namespace Tests\Feature\Savings\Transfers;

use App\Models\AccountingAccount;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsAutoCreditInstruction;
use App\Models\SavingsAutoCreditPosting;
use App\Models\SavingsAutoCreditRun;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Services\Accounting\AccountMap;
use Illuminate\Support\Carbon;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The savings auto-credit run (gap round S3): `GET
 * /savings/auto-credit/preview` and `POST /savings/auto-credit/process`
 * (savings:post). A run takes a cycle (a 15th or a month's last day) and a
 * settlement method (bank by default), posts one deposit per due instruction
 * (Dr settlement / Cr 2050) dated today, records it per instruction and
 * cycle so a re-run posts nothing, and skips, with words, accounts that
 * can't take the money. The preview is the same plan, written nowhere.
 */
class AutoCreditRunTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function instruct(SavingsAccount $account, string $amount, string $schedule = '15', array $overrides = []): SavingsAutoCreditInstruction
    {
        return SavingsAutoCreditInstruction::query()->create([
            'account_id' => $account->id,
            'amount' => $amount,
            'schedule' => $schedule,
            'active' => true,
            'authorised_on' => '2026-11-01',
            'authorised_by' => 'The member',
            'created_by' => $this->admin->id,
            ...$overrides,
        ]);
    }

    private function newAccount(array $overrides = []): SavingsAccount
    {
        return $this->account(['product_id' => SavingsProduct::factory()->create(['name' => 'Payroll Savings'])->id, ...$overrides]);
    }

    private function process(array $body = ['cycle_date' => '2026-11-15'])
    {
        return $this->money('/api/savings/auto-credit/process', $body);
    }

    private function codeOf(string $role): string
    {
        return AccountingAccount::query()->findOrFail(AccountMap::resolve()->accountFor($role))->code;
    }

    public function test_a_run_posts_each_due_instruction_and_equals_its_preview(): void
    {
        $a = $this->account();
        $b = $this->newAccount();
        $c = $this->newAccount();
        $first = $this->instruct($a, '500.00');
        $second = $this->instruct($b, '250.25', '15/30');
        $this->instruct($c, '100.00', '30');
        $this->instruct($this->newAccount(), '100.00', '15', ['active' => false]);
        $this->instruct($this->newAccount(), '100.00', '15', ['authorised_on' => '2026-11-16']);

        $preview = $this->getJson('/api/savings/auto-credit/preview?cycle_date=2026-11-15')->assertOk()
            ->assertJsonPath('data.cycle_date', '2026-11-15')
            ->assertJsonPath('data.posting_date', '2026-11-15')
            ->assertJsonPath('data.method', 'bank_transfer')
            ->assertJsonPath('data.totals', ['credit_count' => 2, 'credit_amount' => '750.25', 'skipped_count' => 0, 'skipped_amount' => '0.00', 'already_credited_count' => 0])
            ->json('data');
        $this->assertSame(0, SavingsTransaction::query()->where('remarks', 'like', 'Auto-credit%')->count());

        $run = $this->process()->assertCreated()->json('data');

        $strip = fn (array $credits) => collect($credits)->map(fn (array $c) => collect($c)->except(['transaction_id', 'reference'])->all())->all();
        $this->assertSame($strip($preview['credits']), $strip($run['credits']));
        $this->assertSame($preview['totals'], $run['totals']);
        $this->assertSame([$first->id, $second->id], array_column($run['credits'], 'instruction_id'));
        $this->assertSame(['500.00', '250.25'], array_column($run['credits'], 'amount'));

        $row = SavingsTransaction::query()->findOrFail($run['credits'][0]['transaction_id']);
        $this->assertSame(['deposit', '500.00', 'bank_transfer', '2026-11-15'], [$row->type, $row->credit, $row->method, $row->date->toDateString()]);
        $this->assertSame([[$this->codeOf('bank'), 50_000, 0], ['2050', 0, 50_000]], $this->journalLines($this->journalFor($row)));
        $this->assertSame('500.00', $this->balanceOf($a));
        $this->assertSame('250.25', $this->balanceOf($b));
        $this->assertSame('0.00', $this->balanceOf($c));
        $this->assertSame('2026-11-15', $a->refresh()->last_member_activity_on->toDateString());

        $this->assertSame(2, SavingsAutoCreditPosting::query()->where('cycle_date', '2026-11-15')->count());
        $record = SavingsAutoCreditRun::query()->sole();
        $this->assertSame([2, '750.25', 0, 'bank_transfer'], [$record->posted_count, $record->posted_total, $record->skipped_count, $record->method]);
        $this->assertSame($record->id, $run['run_id']);
        $this->assertTrue(AuditLog::query()->where('action', 'savings_auto_credit_run')->where('auditable_id', $record->id)->exists());
    }

    public function test_a_re_run_of_the_same_cycle_posts_nothing(): void
    {
        $account = $this->account();
        $instruction = $this->instruct($account, '500.00');
        $this->process()->assertCreated();

        $this->getJson('/api/savings/auto-credit/preview?cycle_date=2026-11-15')->assertOk()
            ->assertJsonPath('data.totals.credit_count', 0)
            ->assertJsonPath('data.totals.already_credited_count', 1)
            ->assertJsonPath('data.already_credited.0.instruction_id', $instruction->id);
        $this->process()->assertCreated()
            ->assertJsonPath('data.credits', [])
            ->assertJsonPath('data.totals.credit_amount', '0.00')
            ->assertJsonPath('data.already_credited.0.reference', SavingsTransaction::query()->where('type', 'deposit')->sole()->reference);

        $this->assertSame('500.00', $this->balanceOf($account));
        $this->assertSame(1, SavingsAutoCreditPosting::query()->count());
    }

    public function test_a_late_run_posts_todays_date_and_records_its_cycle(): void
    {
        $account = $this->account();
        $this->instruct($account, '500.00', '30');

        $this->on('2026-12-03', function () use ($account): void {
            $this->getJson('/api/savings/auto-credit/preview?cycle_date=2026-11-30')->assertOk()
                ->assertJsonPath('data.posting_date', '2026-12-03');
            $this->process(['cycle_date' => '2026-11-30'])->assertCreated()->assertJsonPath('data.totals.credit_count', 1);

            $row = SavingsTransaction::query()->where('account_id', $account->id)->sole();
            $this->assertSame('2026-12-03', $row->date->toDateString());
            $this->assertSame('2026-11-30', SavingsAutoCreditPosting::query()->sole()->cycle_date->toDateString());
            $this->assertSame('Auto-credit for the Nov 30, 2026 cycle', $row->remarks);
        });
    }

    public function test_the_cycle_date_must_be_a_15th_or_a_month_end_and_not_in_the_future(): void
    {
        $this->getJson('/api/savings/auto-credit/preview?cycle_date=2026-11-14')->assertUnprocessable()
            ->assertJsonPath('errors.cycle_date.0', 'The cycle date must be a 15th or the last day of a month (the "30th").');
        $this->getJson('/api/savings/auto-credit/preview?cycle_date=2026-11-30')->assertUnprocessable()
            ->assertJsonPath('errors.cycle_date.0', 'The cycle date can\'t be after today.');
        $this->getJson('/api/savings/auto-credit/preview?cycle_date=2026-07-31')->assertUnprocessable()
            ->assertJsonPath('errors.cycle_date.0', 'The cycle date can be at most 92 days ago.');
        $this->getJson('/api/savings/auto-credit/preview')->assertUnprocessable()->assertJsonPath('errors.cycle_date.0', 'Enter the cycle date.');
        $this->getJson('/api/savings/auto-credit/preview?cycle_date=2026-10-31')->assertOk();
        $this->on('2027-03-01', fn () => $this->getJson('/api/savings/auto-credit/preview?cycle_date=2027-02-28')->assertOk());
        $this->process(['cycle_date' => '2026-11-15', 'method' => 'cheque'])->assertUnprocessable()->assertJsonValidationErrors('method');
    }

    public function test_accounts_that_cant_take_the_money_are_skipped_with_words(): void
    {
        $frozen = $this->newAccount(['status' => 'frozen']);
        $closed = $this->newAccount(['status' => 'closed', 'closed_on' => '2026-11-09', 'open_key' => null]);
        $full = $this->account();
        $this->fund($full, 995_000, '2026-11-15');
        $this->product->update(['max_balance' => '10000.00']);
        $small = $this->account(['product_id' => SavingsProduct::factory()->create(['name' => 'Kiddie Savings', 'min_deposit' => '200.00'])->id]);
        $big = $this->account(['product_id' => SavingsProduct::factory()->create(['name' => 'Junior Savings', 'max_deposit' => '50.00'])->id]);
        $leaver = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'withdrawn', 'first_name' => 'Pedro', 'middle_name' => null, 'last_name' => 'Cruz', 'suffix' => null]);
        $former = $this->account(['borrower_id' => $leaver->id]);
        $fine = $this->newAccount();

        foreach ([$frozen, $closed, $full, $small, $big, $former, $fine] as $account) {
            $this->instruct($account, '100.00');
        }

        $run = $this->process()->assertCreated()->json('data');

        $reasons = collect($run['skipped'])->pluck('reason', 'account_number');
        $this->assertSame([
            $frozen->account_number => "{$frozen->account_number} is frozen and takes no deposits until it is unfrozen.",
            $closed->account_number => "{$closed->account_number} is closed and takes no deposits.",
            $full->account_number => "That would take {$full->account_number} to ₱10,050.00, above Regular Savings's maximum balance of ₱10,000.00. It can take up to ₱50.00 more.",
            $small->account_number => 'A deposit to Kiddie Savings is at least ₱200.00.',
            $big->account_number => 'A deposit to Junior Savings is at most ₱50.00.',
            $former->account_number => 'Pedro Cruz has withdrawn from the cooperative, so nothing is credited for them.',
        ], $reasons->all());
        $this->assertSame([$fine->account_number], array_column($run['credits'], 'account_number'));
        $this->assertSame(['credit_count' => 1, 'credit_amount' => '100.00', 'skipped_count' => 6, 'skipped_amount' => '600.00', 'already_credited_count' => 0], $run['totals']);
        $this->assertSame(1, SavingsAutoCreditPosting::query()->count());
    }

    public function test_an_auto_credit_reactivates_a_dormant_account_as_member_activity(): void
    {
        $account = $this->account(['status' => 'dormant', 'dormant_since' => '2026-11-05']);
        $this->instruct($account, '100.00');

        $this->process()->assertCreated()->assertJsonPath('data.totals.credit_count', 1);

        $account->refresh();
        $this->assertSame(['active', null, '2026-11-15'], [$account->status, $account->dormant_since, $account->last_member_activity_on->toDateString()]);
        $this->assertTrue(AuditLog::query()->where('action', 'savings_account_reactivated')->where('auditable_id', $account->id)->exists());
    }

    public function test_the_settlement_method_and_reference_are_kept(): void
    {
        $account = $this->account();
        $this->instruct($account, '100.00');

        $this->process(['cycle_date' => '2026-11-15', 'method' => 'gcash', 'reference_number' => 'PAYROLL-NOV-15'])->assertCreated()->assertJsonPath('data.method', 'gcash');

        $row = SavingsTransaction::query()->where('type', 'deposit')->sole();
        $this->assertSame(['gcash', 'PAYROLL-NOV-15'], [$row->method, $row->external_reference]);
        $this->assertSame($this->codeOf('gcash'), $this->journalLines($this->journalFor($row))[0][0]);
    }

    public function test_a_run_covers_only_the_callers_branches(): void
    {
        $elsewhere = Branch::factory()->create();
        $theirs = $this->account(['branch_id' => $elsewhere->id, 'borrower_id' => Borrower::factory()->create(['branch_id' => $elsewhere->id])->id]);
        $this->instruct($theirs, '100.00');
        $this->instruct($ours = $this->newAccount(), '100.00');

        $this->actingAs($this->userWith(['savings:view', 'savings:post']));
        $run = $this->process()->assertCreated()->json('data');

        $this->assertSame([$ours->account_number], array_column($run['credits'], 'account_number'));
        $this->assertSame('0.00', $this->balanceOf($theirs));
    }

    public function test_it_needs_savings_post_and_an_idempotency_key(): void
    {
        $this->instruct($this->account(), '100.00');

        $this->actingAs($this->userWith(['savings:view']));
        $this->getJson('/api/savings/auto-credit/preview?cycle_date=2026-11-15')->assertForbidden();
        $this->process()->assertForbidden();

        $this->actingAs($this->admin);
        $this->postJson('/api/savings/auto-credit/process', ['cycle_date' => '2026-11-15'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(0, SavingsAutoCreditPosting::query()->count());
    }
}

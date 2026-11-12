<?php

namespace Tests\Feature\Savings\Transfers;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\SavingsAutoCreditInstruction;
use App\Models\SavingsProduct;
use Illuminate\Support\Carbon;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Savings auto-credit instructions (gap round S3): a member's standing
 * instruction to credit an amount to one of their accounts on the 15th, the
 * 30th or both, with who authorised it and when. `savings:post` creates,
 * edits and deletes; `savings:view` reads. Branch-scoped (another branch's
 * instruction answers 404), audited, and at most one active instruction per
 * account per slot.
 */
class AutoCreditInstructionsTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private const URI = '/api/savings/auto-credit/instructions';

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

    /** @return array<string, mixed> */
    private function body(int $accountId, array $overrides = []): array
    {
        return ['account_id' => $accountId, 'amount' => '500.00', 'schedule' => '15', 'authorised_on' => '2026-11-01', 'authorised_by' => 'Maria Santos', 'remarks' => 'Payroll deduction', ...$overrides];
    }

    public function test_an_instruction_is_created_read_and_audited(): void
    {
        $account = $this->account();
        $this->actingAs($officer = $this->userWith(['savings:view', 'savings:post']));

        $id = $this->postJson(self::URI, $this->body($account->id))->assertCreated()
            ->assertJsonPath('data.account_id', $account->id)
            ->assertJsonPath('data.account_number', $account->account_number)
            ->assertJsonPath('data.borrower_name', 'Maria Santos')
            ->assertJsonPath('data.amount', '500.00')
            ->assertJsonPath('data.schedule', '15')
            ->assertJsonPath('data.schedule_label', '15th')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.authorised_on', '2026-11-01')
            ->assertJsonPath('data.authorised_by', 'Maria Santos')
            ->assertJsonPath('data.created_by', ['id' => $officer->id, 'name' => $officer->full_name])
            ->json('data.id');

        $this->getJson(self::URI."/{$id}")->assertOk()->assertJsonPath('data.remarks', 'Payroll deduction');
        $this->getJson(self::URI)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->getJson(self::URI."?account_id={$account->id}&active=1")->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertTrue(AuditLog::query()->where('action', 'created')->where('auditable_type', SavingsAutoCreditInstruction::class)->where('auditable_id', $id)->exists());
    }

    public function test_one_active_instruction_per_account_per_slot(): void
    {
        $account = $this->account();
        $this->postJson(self::URI, $this->body($account->id))->assertCreated();

        $this->postJson(self::URI, $this->body($account->id, ['schedule' => '15/30']))->assertUnprocessable()
            ->assertJsonPath('errors.schedule.0', "{$account->account_number} already has an active auto-credit instruction on the 15th. Deactivate it first, or change it instead.");
        $thirty = $this->postJson(self::URI, $this->body($account->id, ['schedule' => '30']))->assertCreated()->json('data.id');

        $this->putJson(self::URI."/{$thirty}", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $both = $this->postJson(self::URI, $this->body($account->id, ['schedule' => '15/30', 'active' => false]))->assertCreated()->json('data.id');
        $this->putJson(self::URI."/{$both}", ['active' => true])->assertUnprocessable()->assertJsonValidationErrors('schedule');

        $other = $this->account(['product_id' => SavingsProduct::factory()->create()->id]);
        $this->postJson(self::URI, $this->body($other->id, ['schedule' => '15/30']))->assertCreated();
    }

    public function test_the_fields_are_validated_in_words(): void
    {
        $account = $this->account();

        $this->postJson(self::URI, $this->body($account->id, ['schedule' => '20']))->assertUnprocessable()
            ->assertJsonPath('errors.schedule.0', 'Choose the 15th, the 30th (month end) or both.');
        $this->postJson(self::URI, $this->body($account->id, ['amount' => '0']))->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'The amount must be above ₱0.00.');
        $this->postJson(self::URI, $this->body($account->id, ['authorised_on' => '2026-11-11']))->assertUnprocessable()
            ->assertJsonPath('errors.authorised_on.0', 'The date it was authorised can\'t be after today.');
        $this->postJson(self::URI, $this->body($account->id, ['authorised_by' => '']))->assertUnprocessable()
            ->assertJsonPath('errors.authorised_by.0', 'Enter who authorised the instruction.');

        $closed = $this->account(['status' => 'closed', 'closed_on' => '2026-11-09', 'open_key' => null, 'product_id' => SavingsProduct::factory()->create()->id]);
        $this->postJson(self::URI, $this->body($closed->id))->assertUnprocessable()
            ->assertJsonPath('errors.account_id.0', "{$closed->account_number} is closed, so it can't take an auto-credit instruction.");

        $this->assertSame(0, SavingsAutoCreditInstruction::query()->count());
    }

    public function test_an_edit_changes_the_fields_but_never_the_account_and_is_audited(): void
    {
        $account = $this->account();
        $id = $this->postJson(self::URI, $this->body($account->id))->assertCreated()->json('data.id');

        $this->putJson(self::URI."/{$id}", ['amount' => '750.50', 'schedule' => '15/30'])->assertOk()
            ->assertJsonPath('data.amount', '750.50')
            ->assertJsonPath('data.schedule_label', '15th and 30th')
            ->assertJsonPath('data.updated_by', ['id' => $this->admin->id, 'name' => $this->admin->full_name]);
        $this->putJson(self::URI."/{$id}", ['account_id' => $this->account(['product_id' => SavingsProduct::factory()->create()->id])->id])->assertUnprocessable()
            ->assertJsonPath('errors.account_id.0', 'An instruction stays on its account. Delete it and make a new one for another account.');

        $log = AuditLog::query()->where('action', 'updated')->where('auditable_type', SavingsAutoCreditInstruction::class)->where('auditable_id', $id)->sole();
        $this->assertSame("Auto-credit instruction on savings account {$account->account_number} changed.", $log->description);
    }

    public function test_an_instruction_that_never_posted_can_be_deleted_and_one_that_posted_only_deactivated(): void
    {
        $account = $this->account();
        $id = $this->postJson(self::URI, $this->body($account->id))->assertCreated()->json('data.id');
        $this->deleteJson(self::URI."/{$id}")->assertOk();
        $this->assertNull(SavingsAutoCreditInstruction::query()->find($id));
        $this->assertTrue(AuditLog::query()->where('action', 'deleted')->where('auditable_type', SavingsAutoCreditInstruction::class)->where('auditable_id', $id)->exists());

        $id = $this->postJson(self::URI, $this->body($account->id))->assertCreated()->json('data.id');
        $this->on('2026-11-15', fn () => $this->money('/api/savings/auto-credit/process', ['cycle_date' => '2026-11-15'])->assertCreated());
        $this->deleteJson(self::URI."/{$id}")->assertUnprocessable()
            ->assertJsonPath('errors.instruction.0', 'This instruction has already credited the account, so it is kept for the record. Deactivate it instead.');
    }

    public function test_reading_needs_savings_view_and_writing_savings_post(): void
    {
        $account = $this->account();
        $id = $this->postJson(self::URI, $this->body($account->id))->assertCreated()->json('data.id');

        $this->actingAs($this->userWith(['savings:view']));
        $this->getJson(self::URI)->assertOk();
        $this->postJson(self::URI, $this->body($account->id, ['schedule' => '30']))->assertForbidden();
        $this->putJson(self::URI."/{$id}", ['amount' => '1.00'])->assertForbidden();
        $this->deleteJson(self::URI."/{$id}")->assertForbidden();

        $this->actingAs($this->userWith(['savings:post']));
        $this->getJson(self::URI)->assertForbidden();
    }

    public function test_another_branchs_instruction_is_hidden_and_its_account_refused(): void
    {
        $elsewhere = Branch::factory()->create();
        $theirs = $this->account(['branch_id' => $elsewhere->id, 'borrower_id' => Borrower::factory()->create(['branch_id' => $elsewhere->id])->id]);
        $id = $this->postJson(self::URI, $this->body($theirs->id))->assertCreated()->json('data.id');

        $this->actingAs($this->userWith(['savings:view', 'savings:post']));
        $this->getJson(self::URI)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::URI."/{$id}")->assertNotFound();
        $this->putJson(self::URI."/{$id}", ['amount' => '1.00'])->assertNotFound();
        $this->deleteJson(self::URI."/{$id}")->assertNotFound();
        $this->postJson(self::URI, $this->body($theirs->id, ['schedule' => '30']))->assertUnprocessable()
            ->assertJsonPath('errors.account_id.0', 'That savings account does not exist.');
    }
}

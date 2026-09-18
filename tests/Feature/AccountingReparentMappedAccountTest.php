<?php

namespace Tests\Feature;

use App\Models\AccountingAccount;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Accounting review finding N3 — re-parenting a role-mapped account silently
 * moves a reported figure, and nothing in the shape guard covered `parent_id`.
 *
 * `AccountingDashboardBuilder::receivableAccountIds()` reports gross loans
 * receivable as EVERY postable sibling under the parent of the account the
 * `loans_receivable` role points at. That fan-out is deliberate and documented:
 * moving a loan from current to past due is a journal between 1110 and 1120, so
 * counting only the mapped account would make the portfolio shrink whenever a
 * borrower fell behind.
 *
 * The consequence nobody guarded is that the figure is defined by the mapped
 * account's PARENT. `assertParentIsAGroupOfTheSameType()` only requires the new
 * parent to be a group of the same type, and `assertMappedRolesStillFit()`
 * checked `type`, `is_contra` and `cash_kind` — never `parent_id`. So re-parenting
 * 1110 from "1100 Loans Receivable" to "1000 Assets" is accepted, and every
 * postable asset under 1000 — Cash on Hand, GCash, Maya, Bank Accounts, Prepaid
 * Expenses — is folded into the loan portfolio on the dashboard's headline card.
 */
class AccountingReparentMappedAccountTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    /** A PUT that only moves the account, leaving every other field as it is. */
    private function reparent(int $accountId, int $newParentId): TestResponse
    {
        $account = AccountingAccount::findOrFail($accountId);

        return $this->putJson("/api/accounting/accounts/{$accountId}", [
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'parent_id' => $newParentId,
        ]);
    }

    public function test_a_role_mapped_account_cannot_be_moved_to_another_heading(): void
    {
        $response = $this->reparent($this->account('1110'), $this->account('1000'));

        $response->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

        $this->assertSame(
            $this->account('1100'),
            (int) AccountingAccount::find($this->account('1110'))->parent_id,
            'The account must not have moved.',
        );
    }

    public function test_the_refusal_names_the_role_and_the_remedy(): void
    {
        $message = $this->reparent($this->account('1110'), $this->account('1000'))
            ->assertUnprocessable()
            ->json('errors.parent_id.0');

        $this->assertStringContainsString('loans_receivable', $message);
        $this->assertStringContainsString('Point that role somewhere else', $message);
    }

    public function test_an_unmapped_account_can_still_be_moved(): void
    {
        // The guard is about role-mapped accounts only. Ordinary chart
        // maintenance has to keep working, or an organisation cannot tidy its
        // own books.
        $this->reparent($this->account('1300'), $this->account('1100'))->assertOk();

        $this->assertSame(
            $this->account('1100'),
            (int) AccountingAccount::find($this->account('1300'))->parent_id,
        );
    }

    public function test_a_mapped_account_can_still_be_edited_in_place(): void
    {
        // Only the MOVE is refused. Renaming a mapped account, or any other
        // change that leaves it where it is, must remain possible.
        $id = $this->account('1110');
        $account = AccountingAccount::findOrFail($id);

        $this->putJson("/api/accounting/accounts/{$id}", [
            'code' => $account->code,
            'name' => 'Current Loans Receivable (Members)',
            'type' => $account->type,
            'parent_id' => $account->parent_id,
        ])->assertOk();

        $this->assertSame(
            'Current Loans Receivable (Members)',
            AccountingAccount::find($id)->name,
        );
    }

    public function test_the_receivable_figure_still_spans_the_whole_heading(): void
    {
        // The fan-out itself is intentional and must survive the guard: a loan
        // going past due moves between siblings and the portfolio total must
        // not move with it.
        $this->postJournal([
            ['account_id' => $this->account('1110'), 'debit' => 500_00],
            ['account_id' => $this->account('3010'), 'credit' => 500_00],
        ]);

        $before = $this->getJson('/api/accounting/dashboard')->assertOk()->json('data.net_receivable');

        $this->postJournal([
            ['account_id' => $this->account('1120'), 'debit' => 200_00],
            ['account_id' => $this->account('1110'), 'credit' => 200_00],
        ]);

        $after = $this->getJson('/api/accounting/dashboard')->assertOk()->json('data.net_receivable');

        $this->assertSame($before, $after, 'Moving a loan to past due must not change the portfolio total.');
    }
}

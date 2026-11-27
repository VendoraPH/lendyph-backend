<?php

namespace Tests\Feature\Savings\Servicing;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsAccountNote;
use Illuminate\Support\Carbon;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * An account's notes timeline (gap round S4, Savings TASK 20 "Add Notes"):
 * read with savings:view, added with savings:post, in the caller's branches
 * only, each addition audited; never edited or deleted.
 */
class AccountNotesTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private SavingsAccount $savings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        $this->savings = $this->account();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_notes_are_added_listed_newest_first_and_audited(): void
    {
        $poster = $this->userWith(['savings:view', 'savings:post']);
        $this->actingAs($poster);

        $this->postJson("/api/savings/accounts/{$this->savings->id}/notes", ['note' => '  Member called about dormancy.  '])->assertCreated()
            ->assertJsonPath('data.note', 'Member called about dormancy.')
            ->assertJsonPath('data.created_by.id', $poster->id);
        $this->postJson("/api/savings/accounts/{$this->savings->id}/notes", ['note' => 'Asked to restrict withdrawals'])->assertCreated();

        $this->getJson("/api/savings/accounts/{$this->savings->id}/notes")->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.note', 'Asked to restrict withdrawals')
            ->assertJsonPath('data.1.note', 'Member called about dormancy.')
            ->assertJsonPath('meta.total', 2);

        $audit = AuditLog::query()->where('action', 'savings_account_note_added')->where('auditable_type', SavingsAccount::class)
            ->where('auditable_id', $this->savings->id)->orderBy('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame($poster->id, $audit->user_id);
        $this->assertEquals(['note_id' => $audit->new_values['note_id'], 'length' => 29], $audit->new_values);
        $this->assertSame("Note #{$audit->new_values['note_id']} (29 characters) added to savings account {$this->savings->account_number} by {$poster->full_name}.", $audit->description);
    }

    public function test_note_permissions_and_validation(): void
    {
        $this->actingAs($this->userWith(['savings:view']));
        $this->getJson("/api/savings/accounts/{$this->savings->id}/notes")->assertOk();
        $this->postJson("/api/savings/accounts/{$this->savings->id}/notes", ['note' => 'x'])->assertForbidden();

        $this->actingAs($this->userWith(['savings:post']));
        $this->getJson("/api/savings/accounts/{$this->savings->id}/notes")->assertForbidden();

        $this->actingAs($this->userWith(['savings:view', 'savings:post']));
        $this->postJson("/api/savings/accounts/{$this->savings->id}/notes", ['note' => '   '])->assertUnprocessable()
            ->assertJsonPath('errors.note.0', 'Write the note.');
        $this->postJson("/api/savings/accounts/{$this->savings->id}/notes", ['note' => str_repeat('a', 1001)])->assertUnprocessable()
            ->assertJsonPath('errors.note.0', 'A note can be at most 1,000 characters.');
        $this->postJson("/api/savings/accounts/{$this->savings->id}/notes", ['note' => str_repeat('a', 1000)])->assertCreated();
        $this->assertSame(1, SavingsAccountNote::query()->count());
    }

    public function test_another_branchs_account_answers_404(): void
    {
        $elsewhere = Branch::factory()->create();
        $member = Borrower::factory()->create(['branch_id' => $elsewhere->id, 'status' => 'active']);
        $theirs = $this->account(['borrower_id' => $member->id, 'branch_id' => $elsewhere->id]);

        $this->actingAs($this->userWith(['savings:view', 'savings:post']));
        $this->getJson("/api/savings/accounts/{$theirs->id}/notes")->assertNotFound();
        $this->postJson("/api/savings/accounts/{$theirs->id}/notes", ['note' => ''])->assertNotFound();
        $this->assertSame(0, SavingsAccountNote::query()->count());
    }

    public function test_notes_have_no_edit_or_delete_routes(): void
    {
        $this->actingAs($this->admin);
        $id = $this->postJson("/api/savings/accounts/{$this->savings->id}/notes", ['note' => 'Kept'])->json('data.id');

        $this->putJson("/api/savings/accounts/{$this->savings->id}/notes/{$id}", ['note' => 'Changed'])->assertStatus(404);
        $this->deleteJson("/api/savings/accounts/{$this->savings->id}/notes/{$id}")->assertStatus(404);
        $this->assertSame('Kept', SavingsAccountNote::query()->find($id)->note);
    }
}

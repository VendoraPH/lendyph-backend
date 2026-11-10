<?php

namespace Tests\Feature\Cda\Members;

use App\Models\Borrower;
use App\Models\ReminderQueueItem;
use Tests\Feature\Reminders\BuildsReminderLoans;
use Tests\TestCase;

/**
 * A withdrawn or deceased member (Borrower::FORMER_MEMBER_STATUSES) is still
 * a member for the records, but nothing new starts for them (CDA design §1):
 * payment reminders are neither planned nor sent, and share capital
 * auto-credit neither posts for them nor lists them.
 */
class FormerMembersSkippedTest extends TestCase
{
    use BuildsReminderLoans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReminders();
    }

    public function test_no_reminder_is_planned_for_a_former_member(): void
    {
        $this->at('2026-09-12 08:00');
        $current = $this->loanDue('2026-09-15');
        $withdrawn = $this->loanDue('2026-09-15', borrower: ['status' => 'withdrawn']);
        $deceased = $this->loanDue('2026-09-15', borrower: ['status' => 'deceased']);

        $this->plan();

        $this->assertSame(6, ReminderQueueItem::query()->where('loan_id', $current->id)->count());
        $this->assertSame(0, ReminderQueueItem::query()->whereIn('loan_id', [$withdrawn->id, $deceased->id])->count());
    }

    public function test_a_planned_reminder_is_cancelled_once_the_membership_ends(): void
    {
        $this->at('2026-09-12 08:00');
        $loan = $this->loanDue('2026-09-15');
        $this->plan();
        $loan->borrower->update(['status' => 'deceased']);

        $this->at('2026-09-12 09:05');
        $this->dispatchReminders();

        $item = $this->item($loan, 'Upcoming due');
        $this->assertSame('cancelled', $item->status);
        $this->assertCount(0, $this->driver->calls);
    }

    public function test_auto_credit_neither_posts_for_nor_lists_a_former_member(): void
    {
        $current = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $withdrawn = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'withdrawn']);
        $deceased = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'deceased']);

        foreach ([$current, $withdrawn, $deceased] as $member) {
            $member->shareCapitalPledge->update(['amount' => 500, 'auto_credit' => true]);
        }

        $status = $this->getJson('/api/auto-credit/status')->assertOk();
        $this->assertSame(1, $status->json('data.active_count'));

        $this->postJson('/api/auto-credit/process')
            ->assertCreated()
            ->assertJsonPath('data.member_count', 1);

        $this->assertDatabaseHas('share_capital_ledger', ['borrower_id' => $current->id]);
        $this->assertDatabaseMissing('share_capital_ledger', ['borrower_id' => $withdrawn->id]);
        $this->assertDatabaseMissing('share_capital_ledger', ['borrower_id' => $deceased->id]);
    }
}

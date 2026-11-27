<?php

namespace Tests\Feature\Reminders\Api;

use App\Models\Loan;
use App\Models\ReminderEvent;
use App\Models\ReminderPause;
use Tests\TestCase;

/**
 * A loan pause's reason is staff text about the member as much as a member
 * pause's is (a hospital stay, a death in the family), so the reminder screens
 * hide it the same way: it needs `borrowers:view` and the member's own branch
 * (ContactVisibility), as the Audit Trail requires for every pause reason.
 * Without them the pause reads a null reason, its timeline events carry no
 * text, and a skip because of it reads only "Reminders are paused for this
 * loan".
 */
class ReminderLoanPauseReasonTest extends TestCase
{
    use UsesReminderApi;

    private const SECRET = 'Loan pause Lihim: in hospital';

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReminders();
        $this->at('2026-09-15 09:00');
        $this->loan = $this->loanDue('2026-09-15');

        ReminderPause::query()->create(['scope' => 'loan', 'loan_id' => $this->loan->id, 'reason' => self::SECRET, 'paused_at' => now()->subMinute()]);
        $item = $this->queueRow($this->loan, ['status' => 'skipped', 'reason_code' => 'paused', 'reason' => 'Reminders are paused for this loan: '.self::SECRET]);
        ReminderEvent::forItem('skipped', $item, $item->reason);
    }

    public function test_a_loan_pauses_reason_is_hidden_without_borrowers_view_on_every_reminder_screen(): void
    {
        $this->actingAs($this->staff(['reminders:view', 'reminders:send', 'loans:view']));

        $summary = $this->getJson("/api/loans/{$this->loan->id}/reminders")->assertOk();
        $this->assertSame('loan', $summary->json('data.pause.scope'));
        $this->assertNull($summary->json('data.pause.reason'));
        $this->assertStringNotContainsString('Lihim', (string) $summary->getContent());

        $queue = $this->getJson('/api/reminders/queue')->assertOk();
        $this->assertContains('Reminders are paused for this loan', array_column($queue->json('data'), 'reason'));
        $this->assertStringNotContainsString('Lihim', (string) $queue->getContent());

        $this->assertStringNotContainsString('Lihim', (string) $this->getJson('/api/reminders/pauses')->assertOk()->getContent());

        $preview = $this->postJson('/api/reminders/manual-send/preview', ['loan_id' => $this->loan->id, 'channel' => 'both', 'template_type' => 'due_today'])->assertOk();
        $this->assertContains('Reminders are paused for this loan', $preview->json('data.blocked_reasons'));
        $this->assertStringNotContainsString('Lihim', (string) $preview->getContent());
    }

    public function test_a_member_in_another_branch_keeps_it_hidden_even_with_borrowers_view(): void
    {
        $this->loan->borrower->update(['branch_id' => $this->farBranch()->id]);
        $this->actingAs($this->staff(['reminders:view', 'loans:view', 'borrowers:view']));

        $summary = $this->getJson("/api/loans/{$this->loan->id}/reminders")->assertOk();
        $this->assertNull($summary->json('data.pause.reason'));
        $this->assertStringNotContainsString('Lihim', (string) $summary->getContent());
    }

    public function test_a_caller_who_may_read_the_member_still_reads_it(): void
    {
        $this->actingAs($this->staff(['reminders:view', 'loans:view', 'borrowers:view']));

        $summary = $this->getJson("/api/loans/{$this->loan->id}/reminders")->assertOk();
        $this->assertSame(self::SECRET, $summary->json('data.pause.reason'));
        $this->assertContains(self::SECRET, array_column($summary->json('data.events'), 'detail'));
        $this->assertContains(self::SECRET, array_column($this->getJson('/api/reminders/pauses')->assertOk()->json('data'), 'reason'));
    }
}

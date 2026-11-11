<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\AuditLog;
use App\Models\SavingsAccount;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 3 follow-ups, item B2 (the same pattern as the CDA return reason). The
 * close audit text reads "closed: <reason>. Paid out …", so a reason typed with
 * a full stop read "abroad.. Paid out". The reason's trailing full stops are
 * dropped and a "?" or "!" is kept, with no full stop after it. A reason needs
 * a letter or digit. The account's stored reason stays as typed.
 */
class CloseReasonSentenceTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private SavingsAccount $savings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00', 'Asia/Manila'));
        $this->savings = $this->account();
        $this->fund($this->savings, 500_000, '2026-11-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function closeWith(string $reason): TestResponse
    {
        return $this->money("/api/savings/accounts/{$this->savings->id}/close", [
            'date' => '2026-11-11', 'reason' => $reason, 'payout_method' => 'cash',
        ]);
    }

    private function closeDescription(): string
    {
        return AuditLog::query()->where('action', 'savings_account_closed')->where('auditable_id', $this->savings->id)->sole()->description;
    }

    public function test_a_close_reason_never_doubles_the_full_stop(): void
    {
        $this->closeWith('Member moving abroad.')->assertCreated()->assertJsonPath('data.close_reason', 'Member moving abroad.');

        $description = $this->closeDescription();
        $this->assertStringContainsString('closed: Member moving abroad. Paid out ', $description);
        $this->assertStringNotContainsString('..', $description);
    }

    public function test_a_close_reason_keeps_its_question_mark(): void
    {
        $this->closeWith('Moving abroad?')->assertCreated();

        $description = $this->closeDescription();
        $this->assertStringContainsString('closed: Moving abroad? Paid out ', $description);
        $this->assertStringNotContainsString('?.', $description);
    }

    public function test_a_close_reason_needs_a_letter_or_digit(): void
    {
        foreach (['.', '?', '!!'] as $reason) {
            $this->closeWith($reason)->assertUnprocessable()
                ->assertJsonPath('errors.reason.0', 'A reason needs at least one letter or number.');
        }

        $this->assertSame('active', $this->savings->fresh()->status);
    }
}

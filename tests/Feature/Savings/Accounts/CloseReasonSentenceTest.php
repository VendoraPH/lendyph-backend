<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\AuditLog;
use App\Models\SavingsAccount;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 3 follow-ups, item B2 (the same pattern as the CDA return reason). The
 * close audit text reads "closed: <reason>. Paid out …", so a reason typed with
 * a full stop read "abroad.. Paid out". The reason's trailing `.`, `!` or `?`
 * is dropped from the sentence; the account's stored reason stays as typed.
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

    public function test_a_close_reason_never_doubles_the_full_stop(): void
    {
        $this->money("/api/savings/accounts/{$this->savings->id}/close", [
            'date' => '2026-11-11', 'reason' => 'Member moving abroad.', 'payout_method' => 'cash',
        ])->assertCreated()->assertJsonPath('data.close_reason', 'Member moving abroad.');

        $description = AuditLog::query()->where('action', 'savings_account_closed')->where('auditable_id', $this->savings->id)->sole()->description;

        $this->assertStringContainsString('closed: Member moving abroad. Paid out ', $description);
        $this->assertStringNotContainsString('..', $description);
    }
}

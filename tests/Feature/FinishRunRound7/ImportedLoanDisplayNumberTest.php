<?php

namespace Tests\Feature\FinishRunRound7;

use App\Models\Loan;
use App\Models\User;
use App\Notifications\StaffNotification;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLookupRequest;
use App\Services\RepaymentService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 7: a loan's display number, one server rule. The importer
 * leaves an imported loan's LN- number empty on purpose, so the screens fell
 * back to "LN-<id>" (Payment Entry read "LN-6") while the loan page read
 * LA-000006. The rule: the loan's own LN- number, else the file's Loan No.,
 * else the application number (Loan::displayNumber()). It is sent as
 * `display_number` on loan rows (`loan_display_number` on a payment or an
 * adjustment), and the server's own text uses it.
 */
class ImportedLoanDisplayNumberTest extends TestCase
{
    use SetupLendyPH;

    private Loan $imported;

    private Loan $native;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->imported = $this->createReleasedLoan();
        $this->imported->update(['loan_account_number' => null, 'external_loan_no' => 'OLD-2023-0041']);
        $this->native = $this->createReleasedLoan();
    }

    public function test_the_rule_is_the_ln_number_else_the_files_loan_no_else_the_application_number(): void
    {
        $this->assertSame('OLD-2023-0041', $this->imported->fresh()->displayNumber());
        $this->assertSame($this->native->loan_account_number, $this->native->fresh()->displayNumber());
        $this->assertStringStartsWith('LN-', $this->native->fresh()->displayNumber());

        // Imported with no Loan No. in the file: its application number.
        $this->imported->update(['external_loan_no' => null, 'imported_arrears_baseline' => '2026-06-30']);
        $this->assertSame($this->imported->application_number, $this->imported->fresh()->displayNumber());
    }

    public function test_loan_rows_send_display_number(): void
    {
        $this->getJson("/api/loans/{$this->imported->id}")->assertOk()
            ->assertJsonPath('data.display_number', 'OLD-2023-0041')
            ->assertJsonPath('data.loan_account_number', null);
        $this->getJson("/api/loans/{$this->native->id}")->assertOk()
            ->assertJsonPath('data.display_number', $this->native->loan_account_number);

        $list = collect($this->getJson('/api/loans?per_page=100')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('OLD-2023-0041', $list[$this->imported->id]['display_number']);

        // Payment Entry's list (`view=summary`).
        $summary = collect($this->getJson('/api/loans?view=summary&status=collectible&per_page=100')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('OLD-2023-0041', $summary[$this->imported->id]['display_number']);
        $this->assertSame($this->native->loan_account_number, $summary[$this->native->id]['display_number']);
    }

    public function test_payment_rows_send_the_loans_display_number(): void
    {
        $repayment = app(RepaymentService::class)->processRepayment($this->imported->fresh(), 1000, now()->toDateString(), $this->admin);

        $this->getJson("/api/repayments/{$repayment->id}")->assertOk()
            ->assertJsonPath('data.loan_display_number', 'OLD-2023-0041');

        $rows = collect($this->getJson('/api/repayments?per_page=100')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('OLD-2023-0041', $rows[$repayment->id]['loan_display_number']);
    }

    public function test_the_members_loans_send_it(): void
    {
        $loans = collect($this->getJson("/api/loans?borrower_id={$this->imported->borrower_id}")->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('OLD-2023-0041', $loans[$this->imported->id]['display_number']);
    }

    public function test_a_void_notice_names_the_imported_loan_by_its_files_loan_no(): void
    {
        $cashier = User::factory()->inBranches($this->branch)->create();
        $cashier->assignRole('cashier');
        $repayment = app(RepaymentService::class)->processRepayment($this->imported->fresh(), 1000, now()->toDateString(), $cashier);

        app(RepaymentService::class)->voidRepayment($repayment, 'Keyed twice', $this->admin);

        $body = $cashier->notifications()->where('type', StaffNotification::PAYMENT_VOIDED)->firstOrFail()->data['body'];
        $this->assertStringContainsString('on OLD-2023-0041 for', $body);
    }

    public function test_linda_names_an_imported_loan_by_its_files_loan_no(): void
    {
        $reply = app(LindaAnswerer::class)->lookup(
            $this->admin,
            new LindaLookupRequest(LindaLookupRequest::LOAN, LindaIntent::LoanDetails, $this->imported->application_number),
        );

        $this->assertSame('answered', $reply->outcome);
        $this->assertStringStartsWith('OLD-2023-0041 for ', $reply->answer);
        $this->assertStringNotContainsString('(Loan No. OLD-2023-0041)', $reply->answer, 'named once');
    }
}

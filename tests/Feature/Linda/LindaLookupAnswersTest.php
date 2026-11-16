<?php

namespace Tests\Feature\Linda;

use App\Http\Resources\RepaymentResource;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\User;
use App\Services\BorrowerLoanSummaryService;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLookupRequest;
use App\Services\Linda\LindaReply;
use App\Services\LoanService;
use App\Services\RepaymentService;
use Illuminate\Http\Request;

/**
 * Linda round 2's lookups (LindaAnswerer::lookup()): a member by code or by
 * name, a loan by its LN- or LA- number and one receipt by its RCP- number.
 * Every figure is the one the member page, the loan page or the payment page
 * shows for the same user, another branch's record is never found, and no
 * contact detail or address is ever in a reply.
 */
class LindaLookupAnswersTest extends LindaAnswersTestCase
{
    private function answerer(): LindaAnswerer
    {
        return app(LindaAnswerer::class);
    }

    protected function member(Branch $branch, string $first, string $last): Borrower
    {
        return Borrower::factory()->create([
            'branch_id' => $branch->id,
            'first_name' => $first,
            'middle_name' => null,
            'last_name' => $last,
            'contact_number' => '09171234567',
            'email' => strtolower($first.'.'.$last).'@example.test',
            'address' => '12 Sampaguita Street, Barangay Uno',
        ]);
    }

    private function findMember(User $user, ?string $code = null, ?string $name = null): LindaReply
    {
        return $this->answerer()->lookup($user, new LindaLookupRequest(LindaLookupRequest::MEMBER, LindaIntent::BorrowerLookup, $code, $name));
    }

    private function findLoan(User $user, ?string $code): LindaReply
    {
        return $this->answerer()->lookup($user, new LindaLookupRequest(LindaLookupRequest::LOAN, LindaIntent::LoanDetails, $code));
    }

    private function findReceipt(User $user, ?string $code = null, ?string $name = null): LindaReply
    {
        return $this->answerer()->lookup($user, new LindaLookupRequest(LindaLookupRequest::RECEIPT, LindaIntent::TransactionLookup, $code, $name));
    }

    /** @return array<string, mixed> label => value, over every stats block */
    private function stats(LindaReply $reply): array
    {
        $values = [];
        foreach ($reply->blocks as $block) {
            if ($block['type'] === 'stats') {
                foreach ($block['items'] as $item) {
                    $values[$item['label']] = $item['value'];
                }
            }
        }

        return $values;
    }

    /** @return list<array<string, mixed>> */
    private function listItems(LindaReply $reply): array
    {
        foreach ($reply->blocks as $block) {
            if ($block['type'] === 'list') {
                return $block['items'];
            }
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function fields(array $item): array
    {
        return array_column($item['fields'], 'value', 'label');
    }

    private function assertNoContactDetails(LindaReply $reply, Borrower ...$members): void
    {
        $json = json_encode($reply->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($members as $member) {
            $this->assertStringNotContainsString((string) $member->contact_number, $json);
            $this->assertStringNotContainsString((string) $member->email, $json);
            $this->assertStringNotContainsString('Sampaguita', $json);
            $this->assertStringNotContainsString('birthdate', $json);
        }
    }

    // -----------------------------------------------------------------
    // Member lookup
    // -----------------------------------------------------------------

    public function test_a_member_found_by_code_carries_the_member_pages_figures_for_each_user(): void
    {
        $ana = $this->member($this->branch, 'Ana', 'Cruz');
        $first = $this->releaseTo($ana, 60000);
        $this->releaseTo($ana, 30000, now()->subMonths(1)->subDay()->toDateString());
        $this->pay($first, 1500);
        // A loan of hers in another branch: the branch user never counts it.
        $far = $this->releaseTo($ana, 20000);
        $far->update(['branch_id' => $this->far->id]);

        foreach ([$this->headOffice(), $this->branchUser()] as $user) {
            $summary = app(BorrowerLoanSummaryService::class)->summarize($ana->fresh(), $user);
            $visible = Loan::visibleTo($user)->where('borrower_id', $ana->id)->whereIn('status', Loan::COLLECTIBLE_STATUSES)->get();
            $loanSummaries = $visible->map(fn (Loan $loan) => app(RepaymentService::class)->getLoanSummary($loan));
            $nextDate = $loanSummaries->pluck('next_due_date')->filter()->min();
            $nextAmount = round($loanSummaries->where('next_due_date', $nextDate)->sum('next_due_amount'), 2);

            $reply = $this->findMember($user, 'brw-'.substr($ana->borrower_code, 4));

            $this->assertSame('answered', $reply->outcome);
            $this->assertSame('borrower_lookup', $reply->intent);
            $stats = $this->stats($reply);
            $this->assertEquals($summary['total_outstanding'], $stats['Outstanding']);
            $this->assertEquals($summary['overdue_amount'], $stats['Past Due']);
            $this->assertSame($summary['current_loans'], $stats['Current Loans']);
            [$dateLabel, $amountLabel] = $nextDate < now()->toDateString() ? ['Unpaid Since', 'Amount Unpaid'] : ['Next Due Date', 'Next Due Amount'];
            $this->assertSame($nextDate, $stats[$dateLabel]);
            $this->assertEquals($nextAmount, $stats[$amountLabel]);

            $items = $this->listItems($reply);
            $this->assertCount($visible->count(), $items);
            foreach ($items as $item) {
                $loan = $visible->firstWhere('loan_account_number', $item['title']);
                $this->assertNotNull($loan);
                $this->assertSame('/loans/'.$loan->id, $item['url']);
                $this->assertEquals(app(RepaymentService::class)->getLoanSummary($loan)['total_outstanding'], $this->fields($item)['Outstanding']);
                $this->assertContains($this->fields($item)['Status'], ['Current', 'Past Due']);
            }

            $this->assertSame([['label' => 'Open Member', 'url' => '/borrowers/'.$ana->id]], $reply->links);
            $this->assertSame([$ana->id], $reply->memberIds);
            $this->assertEqualsCanonicalizing($visible->pluck('id')->all(), $reply->loanIds);
            $this->assertStringContainsString('Ana Cruz', $reply->answer);
            $this->assertStringContainsString($ana->borrower_code, $reply->answer);
            $this->assertNoContactDetails($reply, $ana);
        }

        // The head office user sees the Far loan, the branch user doesn't.
        $this->assertCount(3, $this->listItems($this->findMember($this->headOffice(), $ana->borrower_code)));
        $this->assertCount(2, $this->listItems($this->findMember($this->branchUser(), $ana->borrower_code)));
    }

    public function test_a_member_is_found_by_every_typed_name_word_ignoring_case_and_spaces(): void
    {
        $juan = Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Juan Carlos', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz']);
        $this->member($this->branch, 'Pedro', 'Cruz');
        $user = $this->branchUser();

        foreach (['Juan Dela Cruz', '  juan   DELA  cruz ', 'Juan Carlos Santos Dela Cruz', 'Carlos Cruz', 'J. Dela Cruz'] as $typed) {
            $reply = $this->findMember($user, null, $typed);
            $this->assertSame('answered', $reply->outcome, $typed);
            $this->assertSame([$juan->id], $reply->memberIds, $typed);
        }

        // A part of a word, or a word that isn't one of the names, matches nothing.
        foreach (['Jua Cruz', 'Juan Reyes', 'Cr%z', 'C_uz'] as $typed) {
            $reply = $this->findMember($user, null, $typed);
            $this->assertSame('not_found', $reply->outcome, $typed);
            $this->assertSame('No member named "'.trim((string) preg_replace('/\s+/', ' ', $typed)).'" in your branches.', $reply->answer);
            $this->assertSame([], $reply->blocks);
        }
    }

    public function test_several_members_with_the_name_are_listed_and_never_guessed(): void
    {
        $one = $this->member($this->branch, 'Ana', 'Cruz');
        $two = $this->member($this->branch, 'Ana', 'Cruz');
        $this->member($this->far, 'Ana', 'Cruz');

        $reply = $this->findMember($this->branchUser(), null, 'Ana Cruz');

        $this->assertSame('ambiguous_record', $reply->outcome);
        $this->assertStringContainsString('Which one? Ask again with the member code.', $reply->answer);
        $items = $this->listItems($reply);
        $this->assertCount(2, $items);
        $this->assertEqualsCanonicalizing([$one->borrower_code, $two->borrower_code], array_column($items, 'subtitle'));
        $this->assertEqualsCanonicalizing(['/borrowers/'.$one->id, '/borrowers/'.$two->id], array_column($items, 'url'));
        foreach ($items as $item) {
            $this->assertSame('Ana Cruz', $item['title']);
            $this->assertSame(['Branch' => $this->branch->name], $this->fields($item));
        }
        $this->assertEqualsCanonicalizing([$one->id, $two->id], $reply->memberIds);
        $this->assertSame([], $this->stats($reply));
        $this->assertNoContactDetails($reply, $one, $two);
    }

    public function test_more_than_ten_matches_shows_the_first_ten_and_says_so(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->member($this->branch, 'Ana', 'Cruz');
        }

        $reply = $this->findMember($this->branchUser(), null, 'Ana Cruz');

        $this->assertCount(10, $this->listItems($reply));
        $this->assertCount(10, $reply->memberIds);
        $this->assertStringContainsString('12 members', $reply->answer);
        $this->assertStringContainsString('the first 10', $reply->answer);
    }

    public function test_another_branchs_member_is_never_found_by_name_or_by_code(): void
    {
        $ben = $this->member($this->far, 'Ben', 'Reyes');
        $this->releaseTo($ben);
        $user = $this->branchUser();

        $byCode = $this->findMember($user, $ben->borrower_code);
        $byName = $this->findMember($user, null, 'Ben Reyes');

        $this->assertSame('not_found', $byCode->outcome);
        $this->assertSame('No member with code '.$ben->borrower_code.' in your branches.', $byCode->answer);
        $this->assertSame('not_found', $byName->outcome);
        foreach ([$byCode, $byName] as $reply) {
            $this->assertSame([], $reply->blocks);
            $this->assertSame([], $reply->links);
            $this->assertSame([], $reply->memberIds);
            $this->assertSame([], $reply->loanIds);
        }

        $this->assertSame('answered', $this->findMember($this->headOffice(), $ben->borrower_code)->outcome);
    }

    public function test_a_member_lookup_needs_borrowers_view_and_loans_view(): void
    {
        $ana = $this->member($this->branch, 'Ana', 'Cruz');

        foreach ([['loans:view'], ['borrowers:view']] as $permissions) {
            $reply = $this->findMember($this->branchUser($permissions), $ana->borrower_code);

            $this->assertSame('denied', $reply->outcome);
            $this->assertSame("Your role doesn't have access to the member details. Please ask your administrator if you need them.", $reply->answer);
            $this->assertSame([], $reply->blocks);
            $this->assertSame([], $reply->links);
            $this->assertSame([], $reply->memberIds);
        }
    }

    public function test_a_lookup_with_no_target_asks_for_one(): void
    {
        $user = $this->headOffice(['reports:view', 'loans:view', 'borrowers:view', 'payments:view']);

        $member = $this->findMember($user);
        $loan = $this->findLoan($user, null);
        $receipt = $this->findReceipt($user);

        $this->assertSame('no_target', $member->outcome);
        $this->assertSame('Which member? Type their name or member code (BRW-…).', $member->answer);
        $this->assertSame('no_target', $loan->outcome);
        $this->assertSame('Which loan? Type its loan number (LN-…) or application number (LA-…).', $loan->answer);
        $this->assertSame('no_target', $receipt->outcome);
        $this->assertSame('Which receipt? Type its receipt number (RCP-…).', $receipt->answer);
        $this->assertSame('borrower_lookup', $member->intent);
        $this->assertSame('loan_details', $loan->intent);
        $this->assertSame('transaction_lookup', $receipt->intent);
    }

    public function test_a_record_number_linda_cant_look_up_gets_a_fixed_reply(): void
    {
        $reply = $this->answerer()->lookup($this->headOffice(['payments:view']), new LindaLookupRequest(LindaLookupRequest::RECEIPT, LindaIntent::TransactionLookup, 'JE-000012'));

        $this->assertSame('not_yet', $reply->outcome);
        $this->assertSame('I can look up members (BRW-…), loans (LN-… or LA-…) and receipts (RCP-…) by their number, but not JE- numbers yet.', $reply->answer);
    }

    public function test_a_user_with_no_branch_gets_no_lookup(): void
    {
        $ana = $this->member($this->branch, 'Ana', 'Cruz');
        $reply = $this->findMember($this->userWith(['loans:view', 'borrowers:view']), $ana->borrower_code);

        $this->assertSame('denied', $reply->outcome);
        $this->assertSame([], $reply->memberIds);
    }

    public function test_a_loan_word_lookup_by_name_answers_with_the_member(): void
    {
        $ana = $this->member($this->branch, 'Ana', 'Cruz');
        $this->releaseTo($ana);

        $reply = $this->answerer()->lookup($this->branchUser(), new LindaLookupRequest(LindaLookupRequest::LOAN, LindaIntent::LoanDetails, null, 'Ana Cruz'));

        $this->assertSame('answered', $reply->outcome);
        $this->assertSame([$ana->id], $reply->memberIds);
        $this->assertArrayHasKey('Outstanding', $this->stats($reply));
    }

    // -----------------------------------------------------------------
    // Loan lookup
    // -----------------------------------------------------------------

    public function test_a_loan_found_by_its_number_carries_the_loan_summarys_figures(): void
    {
        $ana = $this->member($this->branch, 'Ana', 'Cruz');
        $loan = $this->releaseTo($ana, 60000);
        $this->pay($loan, 700, now()->subDays(3)->toDateString());
        $this->pay($loan, 1500);

        foreach ([$this->headOffice(), $this->branchUser(['loans:view'])] as $user) {
            $summary = app(RepaymentService::class)->getLoanSummary($loan->fresh());
            $last = Repayment::query()->where('loan_id', $loan->id)->where('status', 'posted')->latest('payment_date')->orderByDesc('id')->first();

            foreach ([$loan->loan_account_number, $loan->application_number] as $number) {
                $reply = $this->findLoan($user, $number);

                $this->assertSame('answered', $reply->outcome, $number);
                $this->assertSame('loan_details', $reply->intent);
                $stats = $this->stats($reply);
                $this->assertEquals(60000, $stats['Principal']);
                $this->assertEquals($summary['total_outstanding'], $stats['Outstanding']);
                $this->assertEquals($summary['outstanding_principal'], $stats['Principal Balance']);
                $this->assertEquals($summary['overdue_amount'], $stats['Past Due']);
                [$dateLabel, $amountLabel] = $summary['next_due_date'] < now()->toDateString() ? ['Unpaid Since', 'Amount Unpaid'] : ['Next Due Date', 'Next Due Amount'];
                $this->assertSame($summary['next_due_date'], $stats[$dateLabel]);
                $this->assertEquals($summary['next_due_amount'], $stats[$amountLabel]);
                $this->assertEquals((float) $last->amount_paid, $stats['Last Payment']);
                $this->assertSame($last->payment_date->toDateString(), $stats['Last Payment Date']);
                $this->assertSame('Past Due', $stats['Status']);
                $this->assertSame($loan->loanProduct->name, $stats['Product']);
                $this->assertSame([['label' => 'Open Loan', 'url' => '/loans/'.$loan->id]], $reply->links);
                $this->assertSame([$loan->id], $reply->loanIds);
                $this->assertSame([$ana->id], $reply->memberIds);
                $this->assertStringContainsString('Ana Cruz ('.$ana->borrower_code.')', $reply->answer);
                $this->assertNoContactDetails($reply, $ana);
            }
        }
    }

    public function test_numbers_are_found_with_or_without_their_leading_zeros(): void
    {
        $ana = $this->member($this->branch, 'Ana', 'Cruz');
        $loan = $this->releaseTo($ana);
        $this->pay($loan, 1500);
        $receipt = Repayment::query()->where('loan_id', $loan->id)->latest('id')->firstOrFail();
        $user = $this->branchUser(['loans:view', 'borrowers:view', 'payments:view']);
        $short = fn (string $code): string => strstr($code, '-', true).'-'.ltrim(substr(strstr($code, '-'), 1), '0');

        $this->assertSame([$ana->id], $this->findMember($user, $short($ana->borrower_code))->memberIds);
        $this->assertSame([$loan->id], $this->findLoan($user, $short($loan->loan_account_number))->loanIds);
        $this->assertSame([$loan->id], $this->findLoan($user, $short($loan->application_number))->loanIds);
        $this->assertSame('answered', $this->findReceipt($user, $short($receipt->receipt_number))->outcome);
        $this->assertSame('not_found', $this->findLoan($user, 'LN-9999')->outcome);
    }

    public function test_a_loan_not_yet_released_shows_its_status_and_amount_only(): void
    {
        $ana = $this->member($this->branch, 'Ana', 'Cruz');
        $product = LoanProduct::factory()->create(['interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly']);
        $loan = app(LoanService::class)->createLoan([
            'borrower_id' => $ana->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 25000,
            'start_date' => now()->toDateString(),
        ], $this->admin);
        $loan->update(['branch_id' => $this->branch->id]);
        app(LoanService::class)->submitForReview($loan->fresh());

        $reply = $this->findLoan($this->branchUser(['loans:view']), $loan->application_number);

        $this->assertSame('answered', $reply->outcome);
        $stats = $this->stats($reply);
        $this->assertSame('For Approval', $stats['Status']);
        $this->assertEquals(25000, $stats['Principal']);
        $this->assertArrayNotHasKey('Outstanding', $stats);
        $this->assertStringContainsString('has no balance yet', $reply->answer);
        $this->assertSame([$loan->id], $reply->loanIds);
    }

    public function test_another_branchs_loan_is_never_found_and_a_loan_needs_loans_view(): void
    {
        $loan = $this->releaseTo($this->member($this->far, 'Ben', 'Reyes'));

        foreach ([$loan->loan_account_number, $loan->application_number] as $number) {
            $reply = $this->findLoan($this->branchUser(), $number);
            $this->assertSame('not_found', $reply->outcome);
            $this->assertSame('No loan numbered '.$number.' in your branches.', $reply->answer);
            $this->assertSame([], $reply->loanIds);
        }

        $denied = $this->findLoan($this->headOffice(['reports:view', 'borrowers:view']), $loan->loan_account_number);
        $this->assertSame('denied', $denied->outcome);
        $this->assertSame("Your role doesn't have access to the loan details. Please ask your administrator if you need them.", $denied->answer);
    }

    // -----------------------------------------------------------------
    // One receipt
    // -----------------------------------------------------------------

    public function test_a_receipt_shows_how_the_payment_page_applied_it(): void
    {
        $ana = $this->member($this->branch, 'Ana', 'Cruz');
        $loan = $this->releaseTo($ana, 60000);
        $this->pay($loan, 2345.67);
        $receipt = Repayment::query()->where('loan_id', $loan->id)->latest('id')->firstOrFail();
        $resource = (new RepaymentResource($receipt->load('loan.borrower', 'loan.loanProduct', 'loan.amortizationSchedules', 'receivedByUser', 'voidedByUser', 'shareCapitalLedgerEntries')))->toArray(Request::create('/'));

        foreach ([$this->headOffice(['payments:view']), $this->branchUser(['payments:view'])] as $user) {
            $reply = $this->findReceipt($user, $receipt->receipt_number);

            $this->assertSame('answered', $reply->outcome);
            $this->assertSame('transaction_lookup', $reply->intent);
            $stats = $this->stats($reply);
            $this->assertEquals($resource['amount_paid'], $stats['Amount']);
            $this->assertSame($resource['payment_date'], $stats['Date']);
            $this->assertSame('Cash', $stats['Method']);
            $this->assertSame('Posted', $stats['Status']);
            $this->assertEquals($resource['principal_applied'], $stats['Principal']);
            $this->assertEquals($resource['interest_applied'], $stats['Interest']);
            $this->assertEquals($resource['penalty_applied'], $stats['Penalty']);
            $this->assertEquals($resource['insurance_applied'], $stats['Insurance Premium']);
            $this->assertEquals(round($stats['Principal'] + $stats['Interest'] + $stats['Penalty'] + $stats['Insurance Premium'] + $stats['Build-Up'] + $stats['Overpayment'], 2), $stats['Amount']);
            $this->assertSame(['/payments/'.$receipt->id], array_column($reply->links, 'url'));
            $this->assertSame([$loan->id], $reply->loanIds);
            $this->assertSame([$ana->id], $reply->memberIds);
            $this->assertStringContainsString($loan->loan_account_number, $reply->answer);
            $this->assertStringContainsString('Ana Cruz ('.$ana->borrower_code.')', $reply->answer);
            $this->assertNoContactDetails($reply, $ana);
        }
    }

    public function test_a_voided_receipt_says_so_with_the_reason(): void
    {
        $loan = $this->releaseTo($this->member($this->branch, 'Ana', 'Cruz'));
        $this->pay($loan, 1500);
        $receipt = Repayment::query()->where('loan_id', $loan->id)->latest('id')->firstOrFail();
        $this->actingAs($this->admin)->patchJson("/api/repayments/{$receipt->id}/void", ['void_reason' => 'Entered twice'])->assertOk();

        $reply = $this->findReceipt($this->branchUser(['payments:view']), strtolower($receipt->receipt_number));

        $this->assertSame('Voided', $this->stats($reply)['Status']);
        $this->assertStringContainsString('was voided', $reply->answer);
        $this->assertStringContainsString('Entered twice', $reply->answer);
    }

    public function test_another_branchs_receipt_is_never_found_and_a_receipt_needs_payments_view(): void
    {
        $loan = $this->releaseTo($this->member($this->far, 'Ben', 'Reyes'));
        $this->pay($loan, 1500);
        $receipt = Repayment::query()->where('loan_id', $loan->id)->latest('id')->firstOrFail();

        $hidden = $this->findReceipt($this->branchUser(['payments:view']), $receipt->receipt_number);
        $this->assertSame('not_found', $hidden->outcome);
        $this->assertSame('No receipt numbered '.$receipt->receipt_number.' in your branches.', $hidden->answer);
        $this->assertSame([], $hidden->loanIds);

        $denied = $this->findReceipt($this->headOffice(['reports:view', 'loans:view']), $receipt->receipt_number);
        $this->assertSame('denied', $denied->outcome);
        $this->assertSame("Your role doesn't have access to the payment details. Please ask your administrator if you need them.", $denied->answer);
    }

    public function test_a_payment_lookup_by_name_lists_that_members_latest_payments(): void
    {
        $ana = $this->member($this->branch, 'Ana', 'Cruz');
        $loan = $this->releaseTo($ana);
        $this->pay($loan, 1500);
        $this->pay($loan, 700);
        $other = $this->releaseTo($this->member($this->branch, 'Carla', 'Diaz'));
        $this->pay($other, 900);
        $user = $this->branchUser(['payments:view', 'borrowers:view']);

        $reply = $this->findReceipt($user, null, 'Ana Cruz');
        $history = $this->actingAs($user)->getJson('/api/repayments?status=posted&borrower_id='.$ana->id)->assertOk();

        $this->assertSame('answered', $reply->outcome);
        $this->assertSame('transaction_lookup', $reply->intent);
        $this->assertSame($history->json('meta.total'), $this->stats($reply)['Payments']);
        $this->assertEquals($history->json('meta.totals.collected'), $this->stats($reply)['Total Collected']);
        $this->assertCount(2, $this->listItems($reply));
        $this->assertSame([$ana->id], $reply->memberIds);
        $this->assertSame([$loan->id], $reply->loanIds);
    }

    public function test_a_payment_lookup_by_member_needs_borrowers_view(): void
    {
        $ana = $this->member($this->branch, 'Ana', 'Cruz');
        $loan = $this->releaseTo($ana);
        $this->pay($loan, 1500);
        $receipt = Repayment::query()->where('loan_id', $loan->id)->latest('id')->firstOrFail();

        foreach ([$this->branchUser(['payments:view']), $this->headOffice(['payments:view', 'loans:view'])] as $user) {
            foreach ([[null, 'Ana Cruz'], [$ana->borrower_code, null]] as [$code, $name]) {
                $reply = $this->findReceipt($user, $code, $name);

                $this->assertSame('denied', $reply->outcome);
                $this->assertSame("Your role doesn't have access to the member details. Please ask your administrator if you need them.", $reply->answer);
                $this->assertSame([], $reply->blocks);
                $this->assertSame([], $reply->memberIds);
                $this->assertSame([], $reply->loanIds);
                $this->assertStringNotContainsString('Ana', $reply->answer);
            }

            // A receipt by its own number still needs only payments:view.
            $this->assertSame('answered', $this->findReceipt($user, $receipt->receipt_number)->outcome);
        }
    }

    public function test_a_payment_lookup_by_member_matches_payment_history_in_the_users_scope(): void
    {
        $ana = $this->member($this->branch, 'Ana', 'Cruz');
        $near = $this->releaseTo($ana);
        $this->pay($near, 1500);
        $this->pay($near, 700);
        // A loan of hers booked in another branch: only head office counts it.
        $far = $this->releaseTo($ana, 20000);
        $far->update(['branch_id' => $this->far->id]);
        $this->pay($far, 900);

        foreach ([$this->branchUser(['payments:view', 'borrowers:view']), $this->headOffice(['payments:view', 'borrowers:view'])] as $user) {
            foreach ([[null, 'Ana Cruz'], [$ana->borrower_code, null]] as [$code, $name]) {
                $reply = $this->findReceipt($user, $code, $name);
                $history = $this->actingAs($user)->getJson('/api/repayments?status=posted&borrower_id='.$ana->id)->assertOk();

                $this->assertSame('answered', $reply->outcome);
                $this->assertSame($history->json('meta.total'), $this->stats($reply)['Payments']);
                $this->assertEquals($history->json('meta.totals.collected'), $this->stats($reply)['Total Collected']);
                $this->assertCount($history->json('meta.total'), $this->listItems($reply));
                $this->assertSame([$ana->id], $reply->memberIds);
                $this->assertNoContactDetails($reply, $ana);
            }
        }

        $this->assertSame(2, $this->stats($this->findReceipt($this->branchUser(['payments:view', 'borrowers:view']), null, 'Ana Cruz'))['Payments']);
        $this->assertSame(3, $this->stats($this->findReceipt($this->headOffice(['payments:view', 'borrowers:view']), null, 'Ana Cruz'))['Payments']);
    }

    public function test_a_payment_lookup_never_finds_another_branchs_member(): void
    {
        $ben = $this->member($this->far, 'Ben', 'Reyes');
        $this->pay($this->releaseTo($ben), 1500);
        $user = $this->branchUser(['payments:view', 'borrowers:view']);

        $byName = $this->findReceipt($user, null, 'Ben Reyes');
        $byCode = $this->findReceipt($user, $ben->borrower_code);

        $this->assertSame('not_found', $byName->outcome);
        $this->assertSame('No member named "Ben Reyes" in your branches.', $byName->answer);
        $this->assertSame('not_found', $byCode->outcome);
        $this->assertSame('No member with code '.$ben->borrower_code.' in your branches.', $byCode->answer);
        foreach ([$byName, $byCode] as $reply) {
            $this->assertSame([], $reply->blocks);
            $this->assertSame([], $reply->memberIds);
            $this->assertSame([], $reply->loanIds);
        }
    }
}

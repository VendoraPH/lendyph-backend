<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\LoanDocumentPrint;
use App\Models\Role;
use App\Models\User;
use App\Services\LoanDocumentTerms;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Ledger\Release\ReleasesLoans;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Reprint after a change (finish run round 3, R.A. 3765): each print of the
 * disclosure statement or the promissory note records the loan's terms. While
 * the loan is approved, a printed document whose terms changed since shows on
 * GET /loans/{id} (`printed_documents_outdated`), and the release is refused
 * with 422 on `documents` until it is printed again. A document never printed,
 * and a print made under another terms version, block nothing.
 *
 * createReleasedLoan() leaves ₱60,000.00 principal and ₱10,800.00 interest
 * owed; every restructure here starts at ₱50,000.00.
 */
class LoanDocumentReprintTest extends TestCase
{
    use PostsJournals, ReleasesLoans, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo(Carbon::parse('2026-11-25 10:04:00'));
    }

    public function test_print_log_writes_the_audit_row_and_a_print_row_with_the_terms_printed(): void
    {
        $loan = $this->approvedLoan();

        foreach (['disclosure' => 'Printed disclosure statement', 'promissory_note' => 'Printed promissory note'] as $document => $description) {
            $this->printed($loan, $document);

            $audit = AuditLog::query()->latest('id')->firstOrFail();
            $this->assertSame('printed', $audit->action);
            $this->assertSame($loan->id, (int) $audit->auditable_id);
            $this->assertSame($description, $audit->description);

            $print = LoanDocumentPrint::query()->where('loan_id', $loan->id)->where('document', $document)->sole();
            $this->assertSame(LoanDocumentTerms::VERSION, $print->terms_version);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $print->terms_fingerprint);
            $this->assertSame(app(LoanDocumentTerms::class)->fingerprint($loan, $document), $print->terms_fingerprint);
            $this->assertSame($this->admin->id, (int) $print->printed_by);
            $this->assertSame('2026-11-25 10:04:00', $print->printed_at->toDateTimeString());
        }

        // Each document has terms of its own.
        $this->assertNotSame(
            LoanDocumentPrint::query()->where('document', 'disclosure')->value('terms_fingerprint'),
            LoanDocumentPrint::query()->where('document', 'promissory_note')->value('terms_fingerprint'),
        );
    }

    public function test_print_log_writes_both_rows_or_neither(): void
    {
        $loan = $this->approvedLoan();
        $audits = AuditLog::count();
        LoanDocumentPrint::creating(fn () => throw new RuntimeException('The print row could not be written.'));

        $this->postJson("/api/loans/{$loan->id}/print-log", ['document' => 'disclosure'])->assertStatus(500);

        $this->assertSame($audits, AuditLog::count());
        $this->assertSame(0, LoanDocumentPrint::count());
    }

    public function test_nothing_is_out_of_date_while_the_terms_stand_days_later_or_after_the_members_own_details_change(): void
    {
        $loan = $this->approvedLoan();
        $this->printed($loan, 'disclosure');
        $this->printed($loan, 'promissory_note');

        $this->travel(5)->days();
        $loan->borrower->update([
            'first_name' => 'Renamed',
            'last_name' => 'Member',
            'street_address' => '99 Another Street',
            'contact_number' => '09170000001',
        ]);
        $loan->loanProduct->update(['name' => 'A Renamed Product']);

        $this->assertOutdated($loan, []);
        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();
    }

    public function test_a_sent_back_restructure_edited_to_a_new_principal_is_refused_until_both_documents_are_printed_again(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 50000, 'Board approved write-off');
        $this->printed($new, 'disclosure');
        $this->travelTo(Carbon::parse('2026-11-26 15:30:00'));
        $this->printed($new, 'promissory_note');

        $this->patchJson("/api/loans/{$new->id}/send-back-approved", ['reason' => 'The member asked for less.'])->assertOk();
        // In review again: nothing is listed while the loan isn't approved.
        $this->assertOutdated($new, []);

        $this->putJson("/api/loans/{$new->id}", ['principal_amount' => 45000, 'remarks' => 'The member asked for less.'])->assertOk();
        $this->resubmitAndClimb($new);
        $this->assertSame('approved', $new->fresh()->status);

        $disclosure = $this->latestPrint($new, 'disclosure');
        $note = $this->latestPrint($new, 'promissory_note');
        $this->assertOutdated($new, [
            ['document' => 'disclosure', 'label' => 'Disclosure Statement', 'printed_at' => $disclosure->printed_at->toJSON()],
            ['document' => 'promissory_note', 'label' => 'Promissory Note', 'printed_at' => $note->printed_at->toJSON()],
        ]);
        $this->assertSame('2026-11-25T02:04:00.000000Z', $disclosure->printed_at->toJSON());

        $before = $this->releaseState();
        $this->patchJson("/api/loans/{$new->id}/release")
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => "The loan's terms changed after the disclosure statement was printed on November 25, 2026 and the promissory note on November 26, 2026. Print them again and have the member sign the new copies before releasing.",
                'errors' => ['documents' => ["The loan's terms changed after the disclosure statement was printed on November 25, 2026 and the promissory note on November 26, 2026. Print them again and have the member sign the new copies before releasing."]],
            ]);
        $this->assertSame($before, $this->releaseState());
        $this->assertContains($source->fresh()->status, ['released', 'ongoing']);

        // The disclosure printed again: only the note is left.
        $this->travelTo(Carbon::parse('2026-11-27 09:00:00'));
        $this->printed($new, 'disclosure');
        $this->assertOutdated($new, [
            ['document' => 'promissory_note', 'label' => 'Promissory Note', 'printed_at' => $note->printed_at->toJSON()],
        ]);
        $this->patchJson("/api/loans/{$new->id}/release")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documents' => "The loan's terms changed after the promissory note was printed on November 26, 2026. Print it again and have the member sign the new copy before releasing."]);

        $this->printed($new, 'promissory_note');
        $this->assertOutdated($new, []);
        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();
        $this->assertSame('restructured', $source->fresh()->status);
    }

    public function test_a_restructure_whose_figures_were_taken_again_at_the_same_principal_needs_no_reprint(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 50000, 'Board approved write-off');
        $this->printed($new, 'disclosure');
        $this->printed($new, 'promissory_note');

        // Period 1 paid while it waits for release: the release is refused, it
        // goes back and is approved again on figures taken again, at the same
        // principal. Nothing the documents print has changed.
        $this->payOn($source, 11800);
        $this->patchJson("/api/loans/{$new->id}/release")->assertUnprocessable()->assertJsonValidationErrors('principal_amount');
        $this->patchJson("/api/loans/{$new->id}/send-back-approved", ['reason' => 'The source was paid down.'])->assertOk();
        $this->resubmitAndClimb($new);
        $this->assertSame('59000.00', $new->fresh()->restructure_outstanding);

        $this->assertOutdated($new, []);
        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();
    }

    public function test_a_catalog_fee_added_after_the_print_puts_only_the_disclosure_out_of_date(): void
    {
        $loan = $this->approvedLoan();
        $this->printed($loan, 'disclosure');
        $this->printed($loan, 'promissory_note');

        Fee::create(['name' => 'Documentary Stamp Tax', 'type' => 'fixed', 'value' => 150, 'applicable_product_ids' => [$loan->loan_product_id]]);

        $this->assertOutdated($loan, [
            ['document' => 'disclosure', 'label' => 'Disclosure Statement', 'printed_at' => '2026-11-25T02:04:00.000000Z'],
        ]);
        $this->patchJson("/api/loans/{$loan->id}/release")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documents' => "The loan's terms changed after the disclosure statement was printed on November 25, 2026. Print it again and have the member sign the new copy before releasing."]);
        $this->assertSame('approved', $loan->fresh()->status);

        $this->printed($loan, 'disclosure');
        $this->assertOutdated($loan, []);
        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();
    }

    public function test_a_document_never_printed_blocks_nothing(): void
    {
        $loan = $this->approvedLoan();
        Fee::create(['name' => 'Documentary Stamp Tax', 'type' => 'fixed', 'value' => 150, 'applicable_product_ids' => [$loan->loan_product_id]]);

        $this->assertOutdated($loan, []);
        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();
    }

    public function test_a_print_under_another_terms_version_or_with_no_terms_blocks_nothing(): void
    {
        $loan = $this->approvedLoan();
        $this->recordPrint($loan, 'disclosure', str_repeat('a', 64), LoanDocumentTerms::VERSION + 1);
        $this->recordPrint($loan, 'promissory_note', null, LoanDocumentTerms::VERSION);

        $this->assertOutdated($loan, []);
        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();
    }

    public function test_fees_above_the_principal_leave_no_terms_and_no_terms_never_block(): void
    {
        $loan = $this->approvedLoan();
        $this->printed($loan, 'disclosure');

        // The disclosure can't be built now (the release preview's own 422), so
        // there are no current terms to compare: not out of date.
        $fee = Fee::create(['name' => 'Oversized Fee', 'type' => 'fixed', 'value' => 999999, 'applicable_product_ids' => [$loan->loan_product_id]]);
        $this->getJson("/api/loans/{$loan->id}/disclosure")->assertUnprocessable()->assertJsonValidationErrors('fees');
        $this->assertNull(app(LoanDocumentTerms::class)->fingerprint($loan, 'disclosure'));
        $this->assertOutdated($loan, []);

        // A print made while it couldn't be built records no terms, and blocks nothing.
        $this->printed($loan, 'disclosure');
        $this->assertNull($this->latestPrint($loan, 'disclosure')->terms_fingerprint);
        $fee->delete();
        $this->assertOutdated($loan, []);
        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();
    }

    public function test_a_released_loan_lists_nothing(): void
    {
        $loan = $this->createReleasedLoan();
        $this->recordPrint($loan, 'disclosure', str_repeat('b', 64), LoanDocumentTerms::VERSION);

        $this->assertOutdated($loan, []);
    }

    public function test_the_loans_list_never_works_it_out(): void
    {
        $loan = $this->approvedLoan();
        $this->recordPrint($loan, 'disclosure', str_repeat('c', 64), LoanDocumentTerms::VERSION);
        $this->assertCount(1, $this->getJson("/api/loans/{$loan->id}")->json('data.printed_documents_outdated'));

        foreach (['/api/loans', '/api/loans?view=summary'] as $url) {
            $read = [];
            DB::listen(function (QueryExecuted $query) use (&$read): void {
                $read[] = $query->sql;
            });

            $rows = collect($this->getJson($url)->assertOk()->json('data'));
            $row = $rows->firstWhere('id', $loan->id);
            $this->assertNotNull($row, $url);
            $this->assertArrayNotHasKey('printed_documents_outdated', $row, $url);
            // Not a print row read, so no document is built for any row.
            $this->assertSame([], array_values(array_filter($read, fn (string $sql): bool => str_contains($sql, 'loan_document_prints'))), $url);
        }
    }

    public function test_print_log_still_needs_loans_view_and_records_nothing_without_it(): void
    {
        $loan = $this->approvedLoan();
        $this->actingAs(User::factory()->inBranches($this->branch)->create());

        $this->postJson("/api/loans/{$loan->id}/print-log", ['document' => 'disclosure'])->assertForbidden();
        $this->assertSame(0, LoanDocumentPrint::count());

        $viewer = User::factory()->inBranches($this->branch)->create();
        $viewer->givePermissionTo(Permission::findByName('loans:view', 'web'));
        $this->actingAs($viewer);
        $this->printed($loan, 'disclosure');
        $this->assertSame($viewer->id, (int) $this->latestPrint($loan, 'disclosure')->printed_by);
    }

    public function test_the_documents_send_the_terms_they_state(): void
    {
        $loan = $this->approvedLoan();
        $terms = app(LoanDocumentTerms::class);

        $this->getJson("/api/loans/{$loan->id}/disclosure")
            ->assertOk()
            ->assertJsonPath('data.terms_fingerprint', $terms->fingerprint($loan, 'disclosure'))
            ->assertJsonPath('data.terms_version', LoanDocumentTerms::VERSION);

        $this->getJson("/api/loans/{$loan->id}/promissory-note")
            ->assertOk()
            ->assertJsonPath('data.terms_fingerprint', $terms->fingerprint($loan, 'promissory_note'))
            ->assertJsonPath('data.terms_version', LoanDocumentTerms::VERSION);
    }

    public function test_a_print_tab_opened_before_a_terms_change_and_printed_after_it_leaves_the_document_out_of_date(): void
    {
        $loan = $this->approvedLoan();
        // The print tab reads the statement, then a catalog fee is added before
        // anyone presses Print: the paper still shows the terms it was read with.
        $opened = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data');
        Fee::create(['name' => 'Documentary Stamp Tax', 'type' => 'fixed', 'value' => 150, 'applicable_product_ids' => [$loan->loan_product_id]]);

        $this->printedFrom($loan, 'disclosure', $opened);

        $this->assertSame($opened['terms_fingerprint'], $this->latestPrint($loan, 'disclosure')->terms_fingerprint);
        $this->assertOutdated($loan, [
            ['document' => 'disclosure', 'label' => 'Disclosure Statement', 'printed_at' => '2026-11-25T02:04:00.000000Z'],
        ]);
        $this->patchJson("/api/loans/{$loan->id}/release")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documents' => "The loan's terms changed after the disclosure statement was printed on November 25, 2026. Print it again and have the member sign the new copy before releasing."]);
    }

    public function test_a_print_of_an_up_to_date_payload_clears_it(): void
    {
        $loan = $this->approvedLoan();
        $opened = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data');
        Fee::create(['name' => 'Documentary Stamp Tax', 'type' => 'fixed', 'value' => 150, 'applicable_product_ids' => [$loan->loan_product_id]]);
        $this->printedFrom($loan, 'disclosure', $opened);
        $this->assertCount(1, $this->getJson("/api/loans/{$loan->id}")->json('data.printed_documents_outdated'));

        $reopened = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data');
        $this->assertNotSame($opened['terms_fingerprint'], $reopened['terms_fingerprint']);
        $this->printedFrom($loan, 'disclosure', $reopened);

        $this->assertOutdated($loan, []);
        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();
    }

    public function test_a_print_that_sends_no_terms_still_records_the_terms_as_they_stand(): void
    {
        $loan = $this->approvedLoan();
        // A tab opened before this change: its payload had no terms to send.
        $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk();
        Fee::create(['name' => 'Documentary Stamp Tax', 'type' => 'fixed', 'value' => 150, 'applicable_product_ids' => [$loan->loan_product_id]]);

        $this->printed($loan, 'disclosure');

        $print = $this->latestPrint($loan, 'disclosure');
        $this->assertSame(app(LoanDocumentTerms::class)->fingerprint($loan, 'disclosure'), $print->terms_fingerprint);
        $this->assertSame(LoanDocumentTerms::VERSION, $print->terms_version);
        $this->assertOutdated($loan, []);
    }

    public function test_sent_terms_are_stored_as_they_are(): void
    {
        $loan = $this->approvedLoan();

        // A document that couldn't be built has no terms; a print from another
        // version of the terms is kept as that version. Neither blocks.
        $this->postJson("/api/loans/{$loan->id}/print-log", ['document' => 'disclosure', 'terms_fingerprint' => null, 'terms_version' => LoanDocumentTerms::VERSION])
            ->assertCreated();
        $this->postJson("/api/loans/{$loan->id}/print-log", ['document' => 'promissory_note', 'terms_fingerprint' => str_repeat('d', 64), 'terms_version' => LoanDocumentTerms::VERSION + 1])
            ->assertCreated();

        $this->assertNull($this->latestPrint($loan, 'disclosure')->terms_fingerprint);
        $this->assertSame(LoanDocumentTerms::VERSION, $this->latestPrint($loan, 'disclosure')->terms_version);
        $this->assertSame(str_repeat('d', 64), $this->latestPrint($loan, 'promissory_note')->terms_fingerprint);
        $this->assertSame(LoanDocumentTerms::VERSION + 1, $this->latestPrint($loan, 'promissory_note')->terms_version);
        $this->assertOutdated($loan, []);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function malformedTerms(): array
    {
        $hex = str_repeat('a', 64);
        $reference = "The printed document's terms reference isn't valid. Open the document again and print it.";
        $version = "The printed document's terms version isn't valid. Open the document again and print it.";
        $together = "Send the printed document's terms reference and terms version together.";

        return [
            'upper case' => [['terms_fingerprint' => strtoupper($hex), 'terms_version' => 1], 'terms_fingerprint', $reference],
            'too short' => [['terms_fingerprint' => substr($hex, 1), 'terms_version' => 1], 'terms_fingerprint', $reference],
            'too long' => [['terms_fingerprint' => $hex.'a', 'terms_version' => 1], 'terms_fingerprint', $reference],
            'not hexadecimal' => [['terms_fingerprint' => str_repeat('g', 64), 'terms_version' => 1], 'terms_fingerprint', $reference],
            'a list' => [['terms_fingerprint' => [$hex], 'terms_version' => 1], 'terms_fingerprint', $reference],
            'a version in words' => [['terms_fingerprint' => $hex, 'terms_version' => 'one'], 'terms_version', $version],
            'a null version' => [['terms_fingerprint' => $hex, 'terms_version' => null], 'terms_version', $version],
            'a version out of range' => [['terms_fingerprint' => $hex, 'terms_version' => 70000], 'terms_version', $version],
            'a reference with no version' => [['terms_fingerprint' => $hex], 'terms_version', $together],
            'a version with no reference' => [['terms_version' => 1], 'terms_fingerprint', $together],
        ];
    }

    /** @param  array<string, mixed>  $terms */
    #[DataProvider('malformedTerms')]
    public function test_malformed_terms_are_refused_in_words_and_record_nothing(array $terms, string $field, string $message): void
    {
        $loan = $this->approvedLoan();
        $audits = AuditLog::count();

        $this->postJson("/api/loans/{$loan->id}/print-log", ['document' => 'disclosure', ...$terms])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);

        $this->assertSame($audits, AuditLog::count());
        $this->assertSame(0, LoanDocumentPrint::count());
    }

    private function printed(Loan $loan, string $document): void
    {
        $this->postJson("/api/loans/{$loan->id}/print-log", ['document' => $document])
            ->assertCreated()
            ->assertExactJson(['logged' => true]);
    }

    /**
     * A print logged by a tab that read `$payload`: the terms that paper states.
     *
     * @param  array<string, mixed>  $payload
     */
    private function printedFrom(Loan $loan, string $document, array $payload): void
    {
        $this->postJson("/api/loans/{$loan->id}/print-log", [
            'document' => $document,
            'terms_fingerprint' => $payload['terms_fingerprint'],
            'terms_version' => $payload['terms_version'],
        ])->assertCreated()->assertExactJson(['logged' => true]);
    }

    /** @param  list<array<string, string>>  $expected */
    private function assertOutdated(Loan $loan, array $expected): void
    {
        $this->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.printed_documents_outdated', $expected);
    }

    private function latestPrint(Loan $loan, string $document): LoanDocumentPrint
    {
        return LoanDocumentPrint::query()->where('loan_id', $loan->id)->where('document', $document)->latest('id')->firstOrFail();
    }

    private function recordPrint(Loan $loan, string $document, ?string $fingerprint, int $version): void
    {
        LoanDocumentPrint::query()->create([
            'loan_id' => $loan->id,
            'document' => $document,
            'terms_fingerprint' => $fingerprint,
            'terms_version' => $version,
            'printed_by' => $this->admin->id,
            'printed_at' => now(),
        ]);
    }

    /** Re-submit on the submit step, then sign off every approve step. */
    private function resubmitAndClimb(Loan $loan): void
    {
        $resubmit = $this->pendingStep($loan);
        $this->assertSame(LoanApprovalStep::KIND_SUBMIT, $resubmit->kind);
        $this->approveStepAs($resubmit->role, $loan);

        while (($step = $this->pendingStep($loan))->kind === LoanApprovalStep::KIND_APPROVE) {
            $this->approveStepAs($step->role, $loan);
        }
    }

    private function approveStepAs(string $role, Loan $loan): void
    {
        $step = $this->pendingStep($loan);

        $this->actingAs($this->userWithRole($role))
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/approve")
            ->assertOk();
        $this->actingAs($this->admin);
    }

    private function pendingStep(Loan $loan): LoanApprovalStep
    {
        return $loan->approvalSteps()
            ->where('status', LoanApprovalStep::STATUS_PENDING)
            ->orderByDesc('round')
            ->firstOrFail();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole(Role::where('name', $role)->firstOrFail());

        return $user;
    }
}

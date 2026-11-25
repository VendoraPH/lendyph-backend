<?php

namespace Tests\Feature;

use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanDocumentPrint;
use App\Services\LoanDocumentTerms;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ledger\Release\ReleasesLoans;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Reminders in the Release dialog after a print (finish run round 4): while a
 * loan is approved, GET /loans/{id} sends `printed_documents`, the latest print
 * of each document, so the dialog can remind the cashier to print the
 * disclosure statement again after setting an insurance premium, or the
 * promissory note after adding a co-maker. Any print counts, whatever its terms
 * version: this only says when the paper was printed. It comes from the one
 * read of the loan's prints that `printed_documents_outdated` uses.
 */
class LoanPrintedDocumentsTest extends TestCase
{
    use PostsJournals, ReleasesLoans, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo(Carbon::parse('2026-11-25 08:50:00'));
    }

    public function test_an_approved_loan_lists_the_latest_print_of_each_document_in_order(): void
    {
        $loan = $this->approvedLoan();
        $this->printed($loan, 'promissory_note');
        $this->travelTo(Carbon::parse('2026-11-25 09:15:00'));
        $this->printed($loan, 'disclosure');
        $this->travelTo(Carbon::parse('2026-11-26 14:05:00'));
        $this->printed($loan, 'disclosure');

        $this->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.printed_documents', [
                ['document' => 'disclosure', 'label' => 'Disclosure Statement', 'printed_at' => '2026-11-26T06:05:00.000000Z'],
                ['document' => 'promissory_note', 'label' => 'Promissory Note', 'printed_at' => '2026-11-25T00:50:00.000000Z'],
            ])
            // The terms stand, so nothing is out of date.
            ->assertJsonPath('data.printed_documents_outdated', []);
    }

    public function test_a_print_under_another_terms_version_or_with_no_terms_still_counts_as_printed(): void
    {
        $loan = $this->approvedLoan();
        $this->recordPrint($loan, 'disclosure', str_repeat('a', 64), LoanDocumentTerms::VERSION + 1);
        $this->travelTo(Carbon::parse('2026-11-25 10:30:00'));
        $this->recordPrint($loan, 'promissory_note', null, LoanDocumentTerms::VERSION);

        $this->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.printed_documents', [
                ['document' => 'disclosure', 'label' => 'Disclosure Statement', 'printed_at' => '2026-11-25T00:50:00.000000Z'],
                ['document' => 'promissory_note', 'label' => 'Promissory Note', 'printed_at' => '2026-11-25T02:30:00.000000Z'],
            ])
            // Neither print is compared, so neither holds the release.
            ->assertJsonPath('data.printed_documents_outdated', []);
    }

    public function test_the_latest_print_is_listed_while_the_hold_still_compares_the_last_print_with_current_terms(): void
    {
        $loan = $this->approvedLoan();
        $this->printed($loan, 'disclosure');
        Fee::create(['name' => 'Documentary Stamp Tax', 'type' => 'fixed', 'value' => 150, 'applicable_product_ids' => [$loan->loan_product_id]]);

        // A later print under another terms version never clears the hold, but
        // it is still the latest time the statement was printed.
        $this->travelTo(Carbon::parse('2026-11-25 11:00:00'));
        $this->recordPrint($loan, 'disclosure', str_repeat('b', 64), LoanDocumentTerms::VERSION + 1);

        $this->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.printed_documents', [
                ['document' => 'disclosure', 'label' => 'Disclosure Statement', 'printed_at' => '2026-11-25T03:00:00.000000Z'],
            ])
            ->assertJsonPath('data.printed_documents_outdated', [
                ['document' => 'disclosure', 'label' => 'Disclosure Statement', 'printed_at' => '2026-11-25T00:50:00.000000Z'],
            ]);
    }

    public function test_an_approved_loan_never_printed_sends_an_empty_list(): void
    {
        $loan = $this->approvedLoan();

        $this->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.printed_documents', [])
            ->assertJsonPath('data.printed_documents_outdated', []);
    }

    public function test_a_released_loan_sends_an_empty_list_and_reads_no_prints(): void
    {
        $loan = $this->createReleasedLoan();
        $this->recordPrint($loan, 'disclosure', str_repeat('c', 64), LoanDocumentTerms::VERSION);
        $this->recordPrint($loan, 'promissory_note', str_repeat('d', 64), LoanDocumentTerms::VERSION);

        $reads = $this->printReadsDuring(fn () => $this->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.printed_documents', [])
            ->assertJsonPath('data.printed_documents_outdated', []));

        $this->assertSame(0, $reads);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function statusesPastOrBeforeApproval(): array
    {
        return [
            'sent back for approval' => ['for_review'],
            'released' => ['released'],
            'ongoing' => ['ongoing'],
            'completed' => ['completed'],
        ];
    }

    #[DataProvider('statusesPastOrBeforeApproval')]
    public function test_only_an_approved_loan_sends_its_prints(string $status): void
    {
        $loan = $this->approvedLoan();
        $this->printed($loan, 'disclosure');
        $this->printed($loan, 'promissory_note');
        $this->assertCount(2, $this->getJson("/api/loans/{$loan->id}")->json('data.printed_documents'));

        $loan->update(['status' => $status]);

        $this->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.printed_documents', []);
    }

    public function test_the_show_reads_the_prints_once_for_both_lists(): void
    {
        $loan = $this->approvedLoan();
        $this->printed($loan, 'disclosure');
        $this->printed($loan, 'promissory_note');
        Fee::create(['name' => 'Documentary Stamp Tax', 'type' => 'fixed', 'value' => 150, 'applicable_product_ids' => [$loan->loan_product_id]]);

        $reads = $this->printReadsDuring(fn () => $this->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.printed_documents')
            ->assertJsonCount(1, 'data.printed_documents_outdated'));

        $this->assertSame(1, $reads);
    }

    public function test_the_loans_list_never_sends_it(): void
    {
        $loan = $this->approvedLoan();
        $this->printed($loan, 'disclosure');
        $this->assertCount(1, $this->getJson("/api/loans/{$loan->id}")->json('data.printed_documents'));

        foreach (['/api/loans', '/api/loans?view=summary'] as $url) {
            $reads = $this->printReadsDuring(function () use ($url, $loan): void {
                $row = collect($this->getJson($url)->assertOk()->json('data'))->firstWhere('id', $loan->id);
                $this->assertNotNull($row, $url);
                $this->assertArrayNotHasKey('printed_documents', $row, $url);
            });

            $this->assertSame(0, $reads, $url);
        }
    }

    private function printed(Loan $loan, string $document): void
    {
        $this->postJson("/api/loans/{$loan->id}/print-log", ['document' => $document])
            ->assertCreated()
            ->assertExactJson(['logged' => true]);
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

    /** How many queries on the prints table `$request` made. */
    private function printReadsDuring(callable $request): int
    {
        $reads = 0;
        $counting = true;
        DB::listen(function (QueryExecuted $query) use (&$reads, &$counting): void {
            if ($counting && str_contains($query->sql, 'loan_document_prints')) {
                $reads++;
            }
        });

        $request();
        $counting = false;

        return $reads;
    }
}

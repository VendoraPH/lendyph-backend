<?php

namespace Tests\Feature\CsvImport;

use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CsvImportRow;
use App\Models\CsvImportRun;
use App\Models\Role;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\CsvImport\CsvImportProcessor;
use App\Services\CsvImport\CsvImportSchema;
use App\Services\CsvImport\ImportTick;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Share Capital is a third Data Import file, beside Members and Loans
 * (finish run round 2, decision 13; ClickUp 14zfbg0e8ev).
 *
 * - A run may carry it with or without a members file. Its rows match a member
 *   already in Lendyph by account number or member ID, or a member of the same
 *   run's members file.
 * - Duplicates are refused: by reference when one is given, else by member,
 *   date, type and amount, within the file and against the ledger.
 * - Imported rows land in the member's share capital ledger, marked as
 *   imported, with the ledger's own running balance. They post no journal, as
 *   imported loans don't, and a withdrawal never takes the balance below ₱0.00.
 * - An imported reference in this deployment's own cash numbering moves that
 *   series past it, so the counter never gives the same number again.
 */
class ShareCapitalImportTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private int $membersBefore = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Storage::fake('private');
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_run_brings_members_and_their_share_capital_together(): void
    {
        $run = $this->import([
            'customers' => $this->membersCsv([
                ['account_no' => 'M-001', 'first_name' => 'Ana'],
                ['account_no' => 'M-002', 'first_name' => 'Ben'],
            ]),
            'share_capital' => $this->shareCapitalCsv([
                ['account_no' => 'M-001', 'transaction_date' => '2024-01-15', 'reference_number' => 'OR-1', 'transaction_type' => 'Contribution', 'amount' => '1,000.00', 'remarks' => 'Opening share capital'],
                ['account_no' => 'M-001', 'transaction_date' => '2024-02-15', 'transaction_type' => 'Contribution', 'amount' => '500'],
                ['account_no' => 'M-002', 'transaction_date' => '2024-03-01', 'reference_number' => 'OR-3', 'transaction_type' => 'Contribution', 'amount' => '250.50'],
                ['account_no' => 'M-001', 'transaction_date' => '2024-03-10', 'reference_number' => 'OR-4', 'transaction_type' => 'Withdrawal', 'amount' => '300'],
            ]),
        ]);

        $this->assertSame('completed', $run->phase);

        $ana = Borrower::where('external_account_no', 'M-001')->firstOrFail();
        $ben = Borrower::where('external_account_no', 'M-002')->firstOrFail();

        $entries = ShareCapitalLedger::where('borrower_id', $ana->id)->orderBy('date')->get();
        $this->assertCount(3, $entries);
        $this->assertSame(['2024-01-15', '2024-02-15', '2024-03-10'], $entries->map(fn ($e) => $e->date->toDateString())->all());
        $this->assertSame(['1000.00', '500.00', '0.00'], $entries->pluck('credit')->all());
        $this->assertSame(['0.00', '0.00', '300.00'], $entries->pluck('debit')->all());
        $this->assertSame(['OR-1', null, 'OR-4'], $entries->pluck('reference_number')->all());
        $this->assertSame(['Opening share capital', 'Contribution (imported)', 'Withdrawal (imported)'], $entries->pluck('description')->all());
        $this->assertSame([true, true, true], $entries->pluck('is_imported')->all());
        $this->assertSame([null, null, null], $entries->pluck('method')->all(), 'no money moved in this app');
        $this->assertSame([$this->admin->id], $entries->pluck('created_by')->unique()->values()->all());
        $this->assertSame([$this->branch->id], $entries->pluck('branch_id')->unique()->values()->all());
        $this->assertSame('250.50', ShareCapitalLedger::where('borrower_id', $ben->id)->sole()->credit);

        // Each staged row records the entry it wrote.
        $this->assertSame(
            ShareCapitalLedger::orderBy('id')->pluck('id')->all(),
            CsvImportRow::whereNotNull('share_capital_ledger_id')->orderBy('share_capital_ledger_id')->pluck('share_capital_ledger_id')->all(),
        );

        // The Subsidiary Ledger reads them with the ledger's own running balance.
        $this->getJson("/api/share-capital/ledger?borrower_id={$ana->id}")
            ->assertOk()
            ->assertJsonPath('data.0.running_balance', 1200)
            ->assertJsonPath('data.0.is_imported', true)
            ->assertJsonPath('data.1.running_balance', 1500)
            ->assertJsonPath('data.2.running_balance', 1000)
            ->assertJsonPath('meta.summary.ending_balance', 1200);

        $status = $this->getJson("/api/imports/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.phase', 'completed')
            ->assertJsonPath('data.files.customers.counts.imported', 2)
            ->assertJsonPath('data.files.share_capital.counts.imported', 4);
        $this->assertArrayNotHasKey('loans', $status->json('data.files'));

        $audit = DB::table('audit_logs')->where('action', 'csv_import_completed')->sole();
        $this->assertSame("CSV migration run #{$run->id} completed: 2 member(s), 0 loan(s) and 4 share capital entries imported.", $audit->description);
        $this->assertSame(4, json_decode((string) $audit->new_values, true)['share_capital_imported']);
    }

    public function test_a_share_capital_only_run_matches_members_already_in_lendyph(): void
    {
        $this->membersBefore = Borrower::count();
        $legacy = Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => 'LEG-7']);
        $native = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $run = $this->import(['share_capital' => $this->shareCapitalCsv([
            ['account_no' => 'LEG-7', 'reference_number' => 'OR-10'],
            ['account_no' => strtolower($native->borrower_code), 'reference_number' => 'OR-11', 'amount' => '75.25'],
        ])]);

        $this->assertSame('completed', $run->phase);
        $this->assertSame('250.00', ShareCapitalLedger::where('borrower_id', $legacy->id)->sole()->credit);
        $this->assertSame('75.25', ShareCapitalLedger::where('borrower_id', $native->id)->sole()->credit);
        $this->assertSame(2, Borrower::count() - $this->membersBefore, 'no member is created from a share capital file');
    }

    public function test_a_row_for_no_member_is_refused_in_words(): void
    {
        Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => 'PEND-1', 'status' => 'pending']);
        Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => 'GONE-1', 'status' => 'withdrawn']);
        $deceased = Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => 'RIP-1', 'status' => 'deceased']);

        $run = $this->import(['share_capital' => $this->shareCapitalCsv([
            ['account_no' => 'NOPE-1', 'reference_number' => 'A'],
            ['account_no' => 'PEND-1', 'reference_number' => 'B'],
            ['account_no' => 'GONE-1', 'reference_number' => 'C'],
            ['account_no' => 'RIP-1', 'reference_number' => 'D'],
        ])]);

        $this->assertOutcome($run, 2, 'failed', 'borrower_not_found', 'No member was found with account number or member ID "NOPE-1", so this entry wasn\'t imported. Use the account number from the members file, or the member\'s ID in Lendyph.');
        $this->assertOutcome($run, 3, 'failed', 'not_a_member', 'The record with account number or member ID "PEND-1" is a registration that isn\'t an approved member, so no share capital is recorded for it.');
        $this->assertOutcome($run, 4, 'failed', 'member_withdrawn', 'The member with account number or member ID "GONE-1" withdrew from the cooperative, so no share capital is recorded for them.');
        $this->assertOutcome($run, 5, 'imported', null);

        $this->assertSame([$deceased->id], ShareCapitalLedger::pluck('borrower_id')->all(), 'a deceased member\'s estate still settles');
    }

    public function test_a_row_that_names_two_members_is_refused(): void
    {
        $byCode = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => $byCode->borrower_code]);

        $run = $this->import(['share_capital' => $this->shareCapitalCsv([['account_no' => $byCode->borrower_code]])]);

        $this->assertOutcome($run, 2, 'skipped', 'ambiguous_match');
        $this->assertSame(0, ShareCapitalLedger::count());
    }

    public function test_a_row_repeating_an_earlier_row_of_the_file_is_refused_and_the_first_kept(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => 'DUP-1']);

        $run = $this->import(['share_capital' => $this->shareCapitalCsv([
            ['account_no' => 'DUP-1', 'reference_number' => 'OR-7', 'amount' => '100'],
            ['account_no' => 'DUP-1', 'reference_number' => 'or-7', 'amount' => '999'],
            ['account_no' => 'DUP-1', 'reference_number' => '', 'amount' => '40'],
            ['account_no' => 'DUP-1', 'reference_number' => '', 'amount' => '40'],
        ])]);

        $this->assertOutcome($run, 2, 'imported', null);
        $this->assertOutcome($run, 3, 'skipped', 'invalid_row');
        $this->assertOutcome($run, 4, 'imported', null);
        $this->assertOutcome($run, 5, 'skipped', 'invalid_row');
        $this->assertSame(['100.00', '40.00'], ShareCapitalLedger::where('borrower_id', $member->id)->orderBy('id')->pluck('credit')->all());

        $issues = $this->getJson("/api/imports/{$run->id}/errors?kind=share_capital")->assertOk()->json('data');
        $this->assertSame(['duplicate_in_file', 'invalid_row', 'duplicate_in_file', 'invalid_row'], array_column($issues, 'category'));
        $this->assertSame('Reference Number', $issues[0]['field_label']);
        $this->assertSame('DUP-1', $issues[0]['account_no']);
        $this->assertSame('or-7', $issues[0]['original_value']);
    }

    public function test_a_row_already_on_the_ledger_is_refused(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => 'LED-1']);
        $gcash = ShareCapitalLedger::create(['borrower_id' => $member->id, 'date' => '2024-05-01', 'description' => 'GCash contribution', 'debit' => 0, 'credit' => 300, 'method' => 'gcash', 'reference_number' => 'GC-55', 'created_by' => $this->admin->id]);
        ShareCapitalLedger::create(['borrower_id' => $member->id, 'date' => '2024-06-01', 'description' => 'Cash contribution', 'debit' => 0, 'credit' => 200, 'method' => 'cash', 'created_by' => $this->admin->id]);

        $run = $this->import(['share_capital' => $this->shareCapitalCsv([
            ['account_no' => 'LED-1', 'transaction_date' => '2024-05-01', 'reference_number' => 'gc-55', 'amount' => '300'],
            ['account_no' => 'LED-1', 'transaction_date' => '2024-07-01', 'reference_number' => $gcash->reference, 'amount' => '10'],
            ['account_no' => 'LED-1', 'transaction_date' => '2024-06-01', 'reference_number' => '', 'amount' => '200'],
            ['account_no' => $member->borrower_code, 'transaction_date' => '2024-08-01', 'reference_number' => '', 'amount' => '60'],
            ['account_no' => 'LED-1', 'transaction_date' => '2024-08-01', 'reference_number' => '', 'amount' => '60'],
            ['account_no' => 'LED-1', 'transaction_date' => '2024-06-01', 'reference_number' => '', 'amount' => '201'],
        ])]);

        $this->assertOutcome($run, 2, 'skipped', 'duplicate_reference', 'Reference gc-55 is already on the share capital ledger, recorded in Lendyph, so this row wasn\'t imported. A reference number belongs to one transaction.');
        $this->assertOutcome($run, 3, 'skipped', 'duplicate_reference');
        $this->assertOutcome($run, 4, 'skipped', 'duplicate_transaction', 'This member already has a contribution of ₱200.00 dated 2024-06-01 on the share capital ledger, recorded in Lendyph, so this row wasn\'t imported. If both are real, give each its own reference number.');
        $this->assertOutcome($run, 5, 'imported', null);
        // The same member by account number: the row the member ID imported on
        // line 5 is on the ledger now.
        $this->assertOutcome($run, 6, 'skipped', 'duplicate_transaction', 'This member already has a contribution of ₱60.00 dated 2024-08-01 on the share capital ledger, from line 5 of this file, so this row wasn\'t imported. If both are real, give each its own reference number.');
        $this->assertOutcome($run, 7, 'imported', null);

        $this->assertSame(4, ShareCapitalLedger::where('borrower_id', $member->id)->count());
    }

    public function test_running_the_same_file_again_writes_nothing_twice(): void
    {
        Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => 'RE-1']);
        $file = $this->shareCapitalCsv([
            ['account_no' => 'RE-1', 'reference_number' => 'OR-50', 'amount' => '100'],
            ['account_no' => 'RE-1', 'reference_number' => '', 'transaction_date' => '2026-02-01', 'amount' => '20'],
        ]);

        $first = $this->import(['share_capital' => $file]);
        $second = $this->import(['share_capital' => $file]);

        $this->assertSame(['completed', 'completed'], [$first->phase, $second->phase]);
        $this->assertSame(2, ShareCapitalLedger::count());
        $this->assertOutcome($second, 2, 'already_imported', 'already_imported', 'This entry, reference OR-50, was brought in by an earlier import, so it was skipped.');
        $this->assertOutcome($second, 3, 'skipped', 'duplicate_transaction', 'This member already has a contribution of ₱20.00 dated 2026-02-01 on the share capital ledger, from an earlier import, so this row wasn\'t imported. If both are real, give each its own reference number.');
    }

    public function test_a_withdrawal_never_takes_the_balance_below_zero(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => 'WD-1']);
        ShareCapitalLedger::create(['borrower_id' => $member->id, 'date' => '2024-01-01', 'description' => 'Contribution', 'debit' => 0, 'credit' => 1000, 'created_by' => $this->admin->id]);
        ShareCapitalLedger::create(['borrower_id' => $member->id, 'date' => '2024-06-01', 'description' => 'Withdrawal', 'debit' => 700, 'credit' => 0, 'created_by' => $this->admin->id]);

        $run = $this->import(['share_capital' => $this->shareCapitalCsv([
            // Listed first, dated before the contribution that covers it, which
            // comes later in the file: contributions are imported first.
            ['account_no' => 'WD-1', 'transaction_date' => '2023-12-20', 'reference_number' => 'W-1', 'transaction_type' => 'Withdrawal', 'amount' => '50'],
            ['account_no' => 'WD-1', 'transaction_date' => '2023-12-01', 'reference_number' => 'C-1', 'transaction_type' => 'Contribution', 'amount' => '50'],
            // ₱1,000.00 on Mar 1, but the June withdrawal leaves ₱300.00 by then.
            ['account_no' => 'WD-1', 'transaction_date' => '2024-03-01', 'reference_number' => 'W-2', 'transaction_type' => 'Withdrawal', 'amount' => '300.01'],
            ['account_no' => 'WD-1', 'transaction_date' => '2024-03-01', 'reference_number' => 'W-3', 'transaction_type' => 'Withdrawal', 'amount' => '300'],
            // Nothing left after W-3.
            ['account_no' => 'WD-1', 'transaction_date' => '2025-01-01', 'reference_number' => 'W-4', 'transaction_type' => 'Withdrawal', 'amount' => '0.01'],
        ])]);

        $this->assertOutcome($run, 2, 'imported', null);
        $this->assertOutcome($run, 3, 'imported', null);
        // The member's balance isn't in the report: it needs only imports:process.
        $this->assertOutcome($run, 4, 'failed', 'withdrawal_exceeds_balance', 'This withdrawal of ₱300.01 on 2024-03-01 is more than the member\'s share capital can cover from that date on, so it wasn\'t imported. A share capital balance can\'t go below ₱0.00.');
        $this->assertOutcome($run, 5, 'imported', null);
        $this->assertOutcome($run, 6, 'failed', 'withdrawal_exceeds_balance');

        $this->assertSame('0.00', number_format(ShareCapitalLedger::balancesFor([$member->id])[$member->id], 2));
    }

    public function test_imported_entries_post_no_journal(): void
    {
        $this->seedChartOfAccounts();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => 'BK-1']);

        $this->import(['share_capital' => $this->shareCapitalCsv([
            ['account_no' => 'BK-1', 'reference_number' => 'OR-1', 'amount' => '500'],
            ['account_no' => 'BK-1', 'reference_number' => 'OR-2', 'transaction_type' => 'Withdrawal', 'amount' => '100'],
        ])]);

        $this->assertSame(2, ShareCapitalLedger::where('borrower_id', $member->id)->count());
        $this->assertSame(0, AccountingJournal::query()->where('postable_type', ShareCapitalLedger::class)->count());
    }

    public function test_an_imported_cash_number_moves_the_cash_series_past_it(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => 'CSH-M']);
        $owner = $this->owner();

        $run = $this->import(['share_capital' => $this->shareCapitalCsv([
            ['account_no' => 'CSH-M', 'reference_number' => "CSH-{$owner}-2026-0007", 'amount' => '100'],
            // Lower in the same series: the series stays at 7.
            ['account_no' => 'CSH-M', 'reference_number' => "csh-{$owner}-2026-0003", 'amount' => '101'],
            // Another year's series, another owner's and another prefix: theirs, not this one.
            ['account_no' => 'CSH-M', 'reference_number' => "CSH-{$owner}-2025-0040", 'transaction_date' => '2025-12-01', 'amount' => '102'],
            ['account_no' => 'CSH-M', 'reference_number' => 'CSH-9999-2026-0500', 'amount' => '103'],
            ['account_no' => 'CSH-M', 'reference_number' => "TD-{$owner}-2026-0900", 'amount' => '104'],
            // More than the numbering holds.
            ['account_no' => 'CSH-M', 'reference_number' => "CSH-{$owner}-2026-1000000000", 'amount' => '105'],
        ])]);

        $this->assertOutcome($run, 7, 'failed', 'cash_reference_too_large');
        $this->assertSame(5, ShareCapitalLedger::where('borrower_id', $member->id)->count());

        $this->getJson('/api/share-capital/cash-reference/next')->assertOk()->assertJsonPath('data.reference_number', "CSH-{$owner}-2026-0008");

        $this->postJson('/api/share-capital/ledger', ['borrower_id' => $member->id, 'date' => '2026-11-24', 'description' => 'Contribution', 'type' => 'credit', 'amount' => 50])
            ->assertCreated()
            ->assertJsonPath('data.reference_number', "CSH-{$owner}-2026-0008");
        $this->postJson('/api/share-capital/ledger', ['borrower_id' => $member->id, 'date' => '2025-11-24', 'description' => 'Contribution', 'type' => 'credit', 'amount' => 50])
            ->assertCreated()
            ->assertJsonPath('data.reference_number', "CSH-{$owner}-2025-0041");

        $this->assertFalse(DB::table('cash_reference_sequences')->where('prefix', 'TD')->exists(), 'the TD series was never touched');
        $this->assertFalse(DB::table('cash_reference_sequences')->where('owner_id', 9999)->exists(), 'nor another owner\'s');
    }

    public function test_an_imported_cash_number_the_app_already_gave_is_refused(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => 'CSH-B']);
        $owner = $this->owner();

        foreach (range(1, 3) as $entry) {
            $this->postJson('/api/share-capital/ledger', ['borrower_id' => $member->id, 'date' => '2026-11-20', 'description' => 'Contribution', 'type' => 'credit', 'amount' => 10 + $entry])->assertCreated();
        }

        $run = $this->import(['share_capital' => $this->shareCapitalCsv([
            ['account_no' => 'CSH-B', 'reference_number' => "CSH-{$owner}-2026-0002", 'amount' => '99'],
        ])]);

        // 0002 was given already: refused as a duplicate, and the series is unmoved.
        $this->assertOutcome($run, 2, 'skipped', 'duplicate_reference');
        $this->getJson('/api/share-capital/cash-reference/next')->assertJsonPath('data.reference_number', "CSH-{$owner}-2026-0004");
    }

    public function test_a_cash_number_written_another_way_is_kept_and_matched_as_the_app_writes_it(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => 'CSW-1']);
        $owner = $this->owner();
        $unpadded = (int) $owner;

        $first = $this->import(['share_capital' => $this->shareCapitalCsv([
            ['account_no' => 'CSW-1', 'reference_number' => "csh-{$unpadded}-2026-7", 'amount' => '100'],
            ['account_no' => 'CSW-1', 'reference_number' => "CSH-{$owner}-2026-0007", 'amount' => '200'],
        ])]);

        $this->assertOutcome($first, 2, 'imported', null);
        $this->assertOutcome($first, 3, 'skipped', 'invalid_row', null);
        $this->assertSame(["CSH-{$owner}-2026-0007"], ShareCapitalLedger::query()->where('borrower_id', $member->id)->pluck('reference_number')->all());

        $second = $this->import(['share_capital' => $this->shareCapitalCsv([
            ['account_no' => 'CSW-1', 'reference_number' => "CSH-{$unpadded}-2026-07", 'amount' => '300'],
        ])]);

        $this->assertOutcome($second, 2, 'skipped', 'duplicate_reference');
        $this->assertSame(1, ShareCapitalLedger::query()->where('borrower_id', $member->id)->count());
        $this->getJson('/api/share-capital/cash-reference/next')->assertJsonPath('data.reference_number', "CSH-{$owner}-2026-0008");
    }

    public function test_opening_a_run_says_what_it_may_carry(): void
    {
        $this->postJson('/api/imports', $this->openRunPayload(['share_capital' => 'x']))->assertCreated();
        $this->deleteJson('/api/imports/'.CsvImportRun::sole()->id)->assertOk();

        $this->postJson('/api/imports', $this->openRunPayload(['loans' => 'x', 'share_capital' => 'y']))
            ->assertStatus(422)
            ->assertJsonPath('errors', ['files.customers' => ['A loans file needs its members file in the same import.']]);

        $this->postJson('/api/imports', $this->openRunPayload([]))
            ->assertStatus(422)
            ->assertJsonPath('errors.files', ['Choose the members file or the share capital file to import.']);

        $this->actingAs($this->userWith(['imports:process']));
        $this->postJson('/api/imports', $this->openRunPayload(['customers' => 'x', 'share_capital' => 'y']))
            ->assertStatus(422)
            ->assertJsonPath('errors', ['files.share_capital' => ['Importing share capital needs permission to record share capital entries, and your account doesn\'t have it. Leave the share capital file out, or have someone who can record share capital start the import.']]);

        $this->actingAs($this->userWith(['imports:process', 'share_capital:create']));
        $this->postJson('/api/imports', $this->openRunPayload(['share_capital' => 'y']))->assertCreated();
    }

    public function test_a_run_assembles_with_its_share_capital_file(): void
    {
        $csv = $this->shareCapitalCsv([['account_no' => 'X']]);
        $runId = (int) $this->postJson('/api/imports', $this->openRunPayload(['share_capital' => $csv]))
            ->assertCreated()
            ->assertJsonPath('files.share_capital.total_chunks', 1)
            ->json('run.id');

        $this->sendChunk($runId, 'share_capital', 0, $csv)->assertCreated();

        $this->postJson("/api/imports/{$runId}/assemble")
            ->assertOk()
            ->assertJsonPath('run.phase', 'assembled')
            ->assertJsonPath('files.share_capital.assembled', true);
    }

    public function test_the_starter_must_still_record_share_capital_when_the_rows_are_written(): void
    {
        Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => 'PRM-1']);
        $clerk = $this->userWith(['imports:process', 'share_capital:create']);
        $this->actingAs($clerk);

        $run = $this->open(['share_capital' => $this->shareCapitalCsv([['account_no' => 'PRM-1']])]);

        foreach ($clerk->roles as $role) {
            $role->revokePermissionTo('share_capital:create');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $run = $this->runToIdle($run);

        $this->assertSame('completed', $run->phase);
        $this->assertOutcome($run, 2, 'failed', 'share_capital_needs_permission', 'Only staff who can record share capital can import it, and the person who started this import can\'t any more. Have someone who can record share capital start the import again.');
        $this->assertSame(0, ShareCapitalLedger::count());
    }

    public function test_a_branch_user_reaches_only_members_in_their_branches(): void
    {
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        Borrower::factory()->create(['branch_id' => $far->id, 'external_account_no' => 'FAR-1']);
        $near = Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => 'NEAR-1']);
        $this->actingAs($this->userWith(['imports:process', 'share_capital:create']));

        $run = $this->import(['share_capital' => $this->shareCapitalCsv([
            ['account_no' => 'FAR-1', 'reference_number' => 'F'],
            ['account_no' => 'NEAR-1', 'reference_number' => 'N'],
        ])]);

        $this->assertOutcome($run, 2, 'failed', 'borrower_not_found');
        $this->assertSame([$near->id], ShareCapitalLedger::pluck('borrower_id')->all());
    }

    public function test_a_closed_branch_takes_no_imported_entries(): void
    {
        $closed = Branch::factory()->create(['name' => 'Old Branch', 'code' => 'OLD']);
        Borrower::factory()->create(['branch_id' => $closed->id, 'external_account_no' => 'OLD-1']);
        $closed->forceFill(['status' => Branch::STATUS_CLOSED])->save();

        $run = $this->import(['share_capital' => $this->shareCapitalCsv([['account_no' => 'OLD-1']])]);

        $this->assertOutcome($run, 2, 'failed', 'branch_closed', 'Branch "Old Branch" (OLD) is closed, so no share capital can be imported for its members.');
        $this->assertSame(0, ShareCapitalLedger::count());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Opens a run through the API, uploads and assembles its files, and runs
     * it until it settles.
     *
     * @param  array<string, string>  $files  kind => contents
     */
    private function import(array $files): CsvImportRun
    {
        return $this->runToIdle($this->open($files));
    }

    /**
     * @param  array<string, string>  $files  kind => contents
     */
    private function open(array $files): CsvImportRun
    {
        $runId = (int) $this->postJson('/api/imports', $this->openRunPayload($files))->assertCreated()->json('run.id');

        foreach ($files as $kind => $contents) {
            $this->sendChunk($runId, $kind, 0, $contents)->assertCreated();
        }

        $this->postJson("/api/imports/{$runId}/assemble")->assertOk();

        return CsvImportRun::findOrFail($runId);
    }

    private function runToIdle(CsvImportRun $run, int $maxTicks = 60): CsvImportRun
    {
        $processor = new CsvImportProcessor;

        for ($i = 0; $i < $maxTicks; $i++) {
            $tick = $processor->advance($run);
            $run->refresh();

            if ($tick instanceof ImportTick && $tick->idle) {
                return $run;
            }
        }

        $this->fail("The run did not settle within {$maxTicks} ticks.");
    }

    private function assertOutcome(CsvImportRun $run, int $line, string $result, ?string $category, ?string $message = null): void
    {
        $row = CsvImportRow::query()
            ->whereIn('csv_import_file_id', $run->files()->where('kind', 'share_capital')->pluck('id'))
            ->where('line_number', $line)
            ->sole();

        $this->assertSame([$result, $category], [$row->result, $row->result_category], "line {$line}: {$row->result_message}");

        if ($message !== null) {
            $this->assertSame($message, $row->result_message);
        }
    }

    /**
     * @param  array<string, string>  $files  kind => contents
     * @return array<string, mixed>
     */
    private function openRunPayload(array $files): array
    {
        return [
            'branch_id' => $this->branch->id,
            'files' => array_map(static fn (string $contents): array => [
                'filename' => 'upload.csv',
                'size_bytes' => strlen($contents),
                'sha256' => hash('sha256', $contents),
            ], $files),
        ];
    }

    private function sendChunk(int $runId, string $kind, int $index, string $bytes): TestResponse
    {
        return $this->post("/api/imports/{$runId}/files/{$kind}/chunks/{$index}", [
            'sha256' => hash('sha256', $bytes),
            'chunk' => UploadedFile::fake()->createWithContent("chunk{$index}.part", $bytes),
        ], ['Accept' => 'application/json']);
    }

    /**
     * A share capital file, header row first. A row's blank values take the
     * defaults: a ₱250.00 contribution dated 2026-01-15.
     *
     * @param  list<array<string, string>>  $rows
     */
    private function shareCapitalCsv(array $rows): string
    {
        $keys = CsvImportSchema::keys(CsvImportSchema::SHARE_CAPITAL);
        $lines = [implode(',', CsvImportSchema::labels(CsvImportSchema::SHARE_CAPITAL))];

        foreach ($rows as $row) {
            $values = $row + [
                'transaction_date' => '2026-01-15',
                'reference_number' => '',
                'transaction_type' => 'Contribution',
                'amount' => '250.00',
                'remarks' => '',
            ];

            $lines[] = implode(',', array_map(static fn (string $key): string => self::cell($values[$key] ?? ''), $keys));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function membersCsv(array $rows): string
    {
        $keys = CsvImportSchema::keys(CsvImportSchema::CUSTOMERS);
        $lines = [implode(',', CsvImportSchema::labels(CsvImportSchema::CUSTOMERS))];

        foreach ($rows as $row) {
            $values = $row + [
                'last_name' => 'Dela Cruz',
                'birthdate' => '1985-04-12',
                'gender' => 'Female',
                'civil_status' => 'Single',
                'contact_number' => '09171234567',
                'street_address' => '12 Mabini Street',
                'barangay' => 'San Roque',
                'city' => 'Cabanatuan',
                'province' => 'Nueva Ecija',
            ];

            $lines[] = implode(',', array_map(static fn (string $key): string => self::cell($values[$key] ?? ''), $keys));
        }

        return implode("\n", $lines)."\n";
    }

    private static function cell(string $value): string
    {
        return str_contains($value, ',') ? '"'.$value.'"' : $value;
    }

    /** The primary super admin's id as a cash number writes it. */
    private function owner(): string
    {
        $id = User::query()->where('status', 'active')
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'))
            ->min('id');

        return str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'scimp_probe_'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $user->branches()->sync([$this->branch->id]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}

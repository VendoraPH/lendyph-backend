<?php

namespace Tests\Feature\CsvImport;

use App\Models\Borrower;
use App\Models\CsvImportRun;
use App\Models\PortalUser;
use App\Models\ShareCapitalLedger;
use App\Services\CsvImport\CsvImportProcessor;
use App\Services\CsvImport\CsvImportSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * What the share capital import's entries do NOT set off (finish run round
 * 2, decision 13). They are the cooperative's earlier books brought over,
 * not new money:
 *
 * - no member portal notice, though a manual entry for the same member still
 *   sends its "Share capital credited";
 * - on the ledger-gaps report they read as imported, the way an imported
 *   loan's events do: marked, with an "Imported" reason, left out by
 *   `imported=exclude` and alone under `imported=only`.
 */
class ShareCapitalImportSideEffectsTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

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

    public function test_an_imported_entry_sends_the_member_no_portal_notice(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => 'PRT-1']);
        $portalUser = PortalUser::factory()->create(['borrower_id' => $member->id]);

        $this->import([
            ['PRT-1', '2024-01-15', 'OR-1', 'Contribution', '1000.00'],
            ['PRT-1', '2024-02-15', 'OR-2', 'Contribution', '500.00'],
            ['PRT-1', '2024-03-15', 'OR-3', 'Withdrawal', '200.00'],
        ]);

        $this->assertSame(3, ShareCapitalLedger::query()->where('borrower_id', $member->id)->where('is_imported', true)->count());
        $this->assertSame([], $this->noticeTitles($portalUser), 'history brought over is not news to the member');

        // A share capital entry made in the app still tells them.
        $this->postJson('/api/share-capital/ledger', ['borrower_id' => $member->id, 'date' => '2026-11-24', 'description' => 'Contribution', 'type' => 'credit', 'amount' => 250])
            ->assertCreated();
        $this->assertSame(['Share capital credited'], $this->noticeTitles($portalUser));
    }

    public function test_the_ledger_gaps_report_reads_imported_entries_as_imported(): void
    {
        $this->seedChartOfAccounts();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => 'GAP-1']);
        $native = ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'date' => '2026-10-04', 'credit' => 300, 'debit' => 0]);

        $this->import([
            ['GAP-1', '2024-01-15', 'OR-1', 'Contribution', '1000.00'],
            ['GAP-1', '2024-03-15', 'OR-2', 'Withdrawal', '200.00'],
        ]);
        $contribution = ShareCapitalLedger::query()->where('reference_number', 'OR-1')->sole();
        $withdrawal = ShareCapitalLedger::query()->where('reference_number', 'OR-2')->sole();

        $all = $this->rows($this->gaps()->assertOk());
        $this->assertTrue($all[$contribution->id]['imported']);
        $this->assertSame('Imported share capital contribution, from the old books: no journal.', $all[$contribution->id]['reason']);
        $this->assertSame('1000.00', $all[$contribution->id]['amount']);
        $this->assertTrue($all[$withdrawal->id]['imported']);
        $this->assertSame('Imported share capital withdrawal, from the old books: no journal.', $all[$withdrawal->id]['reason']);
        $this->assertFalse($all[$native->id]['imported']);
        $this->assertSame('Share capital contribution with no journal.', $all[$native->id]['reason']);

        $excluded = $this->gaps(['imported' => 'exclude'])->assertOk();
        $this->assertSame([$native->id], array_keys($this->rows($excluded)));
        $this->assertSame(['count' => 1, 'amount' => '300.00'], $excluded->json('meta.totals.by_kind.share_capital'));

        $only = $this->gaps(['imported' => 'only'])->assertOk();
        $this->assertEqualsCanonicalizing([$contribution->id, $withdrawal->id], array_keys($this->rows($only)));
        $this->assertSame(['count' => 2, 'amount' => '1200.00'], $only->json('meta.totals.by_kind.share_capital'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Imports these share capital rows (account, date, reference, type,
     * amount) through the API and the import pass, as a share-capital-only run.
     *
     * @param  list<list<string>>  $rows
     */
    private function import(array $rows): void
    {
        $csv = implode(',', CsvImportSchema::labels(CsvImportSchema::SHARE_CAPITAL))."\n"
            .implode('', array_map(static fn (array $row): string => implode(',', [...$row, ''])."\n", $rows));

        $runId = (int) $this->postJson('/api/imports', [
            'branch_id' => $this->branch->id,
            'files' => ['share_capital' => ['filename' => 'share-capital.csv', 'size_bytes' => strlen($csv), 'sha256' => hash('sha256', $csv)]],
        ])->assertCreated()->json('run.id');

        $this->post("/api/imports/{$runId}/files/share_capital/chunks/0", [
            'sha256' => hash('sha256', $csv),
            'chunk' => UploadedFile::fake()->createWithContent('chunk0.part', $csv),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson("/api/imports/{$runId}/assemble")->assertOk();

        $run = CsvImportRun::findOrFail($runId);
        $processor = new CsvImportProcessor;

        for ($tick = 0; $tick < 20 && ! $processor->advance($run)->idle; $tick++) {
            $run->refresh();
        }

        $this->assertSame('completed', $run->refresh()->phase);
    }

    /** @return list<string> */
    private function noticeTitles(PortalUser $portalUser): array
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $portalUser->getMorphClass())
            ->where('notifiable_id', $portalUser->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (DatabaseNotification $notice): string => (string) $notice->data['title'])
            ->all();
    }

    /** @param  array<string, string>  $query */
    private function gaps(array $query = []): TestResponse
    {
        return $this->getJson('/api/accounting/ledger-gaps'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /**
     * The share capital rows of a ledger-gaps response, keyed by entry id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(TestResponse $response): array
    {
        return collect($response->json('data'))->where('kind', 'share_capital')->keyBy('record_id')->all();
    }
}

<?php

namespace Tests\Feature\CsvImport;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CsvImportRow;
use App\Models\CsvImportRun;
use App\Models\ShareCapitalLedger;
use App\Services\CsvImport\CsvImportProcessor;
use App\Services\CsvImport\CsvImportSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The share capital import locks as every share capital write does: the
 * member's branch row, then the member's own row, read again under its shared
 * lock. A member moved to another branch in between, on another connection,
 * gets no entry from that row, in words; imported again, the entry lands in
 * the member's new branch.
 */
class ShareCapitalImportMemberMoveTest extends TestCase
{
    use SetupLendyPH;

    /** The other connection can only see rows this one has committed. */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'share_capital_import_member_move';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Storage::fake('private');
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'Asia/Manila'));

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
    }

    protected function tearDown(): void
    {
        DB::purge(self::OTHER_CONNECTION);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_member_moved_while_their_entry_is_imported_gets_none_and_the_next_import_brings_it(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'external_account_no' => 'MOV-1']);
        $elsewhere = Branch::factory()->create(['name' => 'Town Branch', 'code' => 'TWN']);
        $file = $this->shareCapitalCsv('MOV-1');
        $first = $this->open($file);
        $moved = false;
        $locks = [];

        // Once the run is open and uploaded: the import pass's own locks only.
        DB::connection()->beforeExecuting(function (string $query) use (&$moved, &$locks, $member, $elsewhere): void {
            if (! str_contains($query, 'lock in share mode')) {
                return;
            }

            $locks[] = str_contains($query, 'from `branches`') ? 'branch' : (str_contains($query, 'from `borrowers`') ? 'member' : 'other');

            if (! $moved && str_contains($query, 'from `borrowers`')) {
                $moved = true;
                DB::connection(self::OTHER_CONNECTION)->table('borrowers')->where('id', $member->id)->update(['branch_id' => $elsewhere->id]);
            }
        });

        $first = $this->runToIdle($first);

        $this->assertTrue($moved);
        $this->assertSame(['branch', 'other', 'member'], $locks, 'the branch row (and its pending closures), then the member');
        $row = CsvImportRow::query()->whereIn('csv_import_file_id', $first->files()->pluck('id'))->sole();
        $this->assertSame(['failed', 'member_moved'], [$row->result, $row->result_category]);
        $this->assertSame('This member was moved to another branch while the import was writing this entry, so it wasn\'t imported. Import the file again to bring it in: the entries already imported are skipped.', $row->result_message);
        $this->assertSame(0, ShareCapitalLedger::query()->count());

        $this->runToIdle($this->open($file));

        $this->assertSame($elsewhere->id, ShareCapitalLedger::query()->sole()->branch_id);
    }

    private function open(string $file): CsvImportRun
    {
        $runId = (int) $this->postJson('/api/imports', [
            'branch_id' => $this->branch->id,
            'files' => ['share_capital' => ['filename' => 'share-capital.csv', 'size_bytes' => strlen($file), 'sha256' => hash('sha256', $file)]],
        ])->assertCreated()->json('run.id');

        $this->post("/api/imports/{$runId}/files/share_capital/chunks/0", [
            'sha256' => hash('sha256', $file),
            'chunk' => UploadedFile::fake()->createWithContent('chunk0.part', $file),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->postJson("/api/imports/{$runId}/assemble")->assertOk();

        return CsvImportRun::findOrFail($runId);
    }

    private function runToIdle(CsvImportRun $run): CsvImportRun
    {
        $processor = new CsvImportProcessor;

        for ($tick = 0; $tick < 20; $tick++) {
            if ($processor->advance($run)->idle) {
                return $run->refresh();
            }

            $run->refresh();
        }

        $this->fail('The run did not settle.');
    }

    private function shareCapitalCsv(string $account): string
    {
        return implode(',', CsvImportSchema::labels(CsvImportSchema::SHARE_CAPITAL))."\n"
            ."{$account},2026-01-15,OR-MOVE,Contribution,250.00,\n";
    }
}

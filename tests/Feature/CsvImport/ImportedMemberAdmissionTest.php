<?php

namespace Tests\Feature\CsvImport;

use App\Http\Resources\BorrowerResource;
use App\Models\Borrower;
use App\Models\CsvImportRun;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\StagesCsvImportRuns;

/**
 * Imported members bypass the KYC gate, and the record has to say so.
 *
 * They land `status = active`, which is correct and stays — `pending` is in
 * NON_MEMBER_STATUSES, so importing a cooperative's whole membership as pending
 * would hide every one of them from the members list and from share capital.
 * What was missing is any way to tell an imported member from a KYC-verified
 * one: `approved_at` and `approved_by` were both NULL on a record that WAS
 * admitted, by someone, on a date, and `external_account_no` being non-null was
 * the only signal that a migration put them there.
 */
class ImportedMemberAdmissionTest extends TestCase
{
    use StagesCsvImportRuns;

    private CsvImportRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAndLoginAsImportAdmin();

        $this->run = $this->makeImportRun(['as_of_date' => '2026-06-30']);
    }

    private function importedMember(): Borrower
    {
        return Borrower::create([
            'external_account_no' => 'A-001',
            'first_name' => 'Maria',
            'last_name' => 'Dela Cruz',
            'branch_id' => $this->importBranch->id,
            'status' => 'active',
        ] + $this->run->admissionStamp());
    }

    public function test_the_stamp_names_the_operator_and_the_date_the_extract_represents(): void
    {
        $stamp = $this->run->admissionStamp();

        $this->assertSame('2026-06-30 00:00:00', $stamp['approved_at']->toDateTimeString());
        $this->assertSame($this->importAdmin->id, $stamp['approved_by']);

        $borrower = $this->importedMember();

        $this->assertSame('2026-06-30 00:00:00', $borrower->approved_at->toDateTimeString());
        $this->assertSame($this->importAdmin->id, $borrower->approved_by);
    }

    public function test_it_dates_the_admission_to_the_extract_and_not_to_the_upload(): void
    {
        /*
         * An extract cut on the 30th and uploaded on the 9th of the next month
         * must not date a decade-old membership to the afternoon somebody
         * dragged the file into the browser.
         */
        $this->travelTo('2026-07-09 14:20:00');

        $this->assertSame('2026-06-30', $this->importedMember()->approved_at->toDateString());
    }

    public function test_stamping_closes_the_backfill_command_fallback(): void
    {
        $borrower = $this->importedMember();

        /*
         * The trap this closes. `registrations:backfill-approvals` fills any
         * member with a NULL approved_at, and for a borrower CREATED as a member
         * there is no audit trail of an approval to read — so its fallback
         * writes `approved_at = created_at, approved_by = null`. Run after an
         * import, that would overwrite the extract's date with the upload date
         * and erase the operator who ran it, permanently and unnoticeably.
         */
        Artisan::call('registrations:backfill-approvals');

        $borrower->refresh();

        $this->assertSame('2026-06-30', $borrower->approved_at->toDateString());
        $this->assertSame($this->importAdmin->id, $borrower->approved_by);
        $this->assertStringContainsString('Every member already has an approved_at timestamp.', Artisan::output());
    }

    public function test_a_run_whose_initiator_is_gone_stamps_an_honest_null(): void
    {
        $this->run->forceFill(['initiated_by' => null])->save();

        $borrower = $this->importedMember();

        $this->assertNull($borrower->approved_by, 'Inventing a plausible operator is worse than a blank one.');
        $this->assertNotNull($borrower->approved_at, 'The date is still known even when the actor is not.');
    }

    public function test_the_members_list_flags_an_imported_member_with_no_kyc_on_file(): void
    {
        $imported = $this->importedMember();

        $registered = Borrower::create([
            'first_name' => 'Jose',
            'last_name' => 'Santos',
            'branch_id' => $this->importBranch->id,
            'status' => 'active',
        ]);
        $registered->documents()->create([
            'type' => 'valid_id',
            'label' => 'umid',
            'file_path' => 'documents/valid_id/test.jpg',
            'original_filename' => 'test.jpg',
        ]);

        $rows = collect($this->getJson('/api/borrowers?per_page=100')->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertTrue($rows[$imported->id]['is_imported']);
        $this->assertFalse($rows[$imported->id]['has_valid_id'], 'This is the backlog an operator has to work.');

        $this->assertFalse($rows[$registered->id]['is_imported']);
        $this->assertTrue($rows[$registered->id]['has_valid_id']);
    }

    public function test_the_list_answers_the_kyc_question_without_a_query_per_row(): void
    {
        foreach (range(1, 5) as $n) {
            Borrower::create([
                'external_account_no' => "A-10{$n}",
                'first_name' => 'Member',
                'last_name' => "Number{$n}",
                'branch_id' => $this->importBranch->id,
                'status' => 'active',
            ] + $this->run->admissionStamp());
        }

        DB::enableQueryLog();
        $this->getJson('/api/borrowers?per_page=100')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $documentQueries = collect($queries)->filter(
            fn (array $q): bool => str_contains($q['query'], 'from `documents`'),
        );

        $this->assertLessThanOrEqual(
            1,
            $documentQueries->count(),
            'has_valid_id must come from one aggregate for the page, never one relation load per member.',
        );
    }

    public function test_the_key_is_absent_rather_than_guessed_when_nothing_was_loaded(): void
    {
        $borrower = $this->importedMember();

        /*
         * A resource built outside the controllers has loaded neither the
         * documents nor the count. Answering `false` there would tell an
         * operator that every member in that payload is missing their KYC.
         */
        $payload = (new BorrowerResource($borrower))->toArray(request());

        $this->assertTrue($payload['is_imported']);
        $this->assertInstanceOf(MissingValue::class, $payload['has_valid_id']);
    }

    public function test_the_member_detail_answers_from_the_documents_it_already_loads(): void
    {
        $borrower = $this->importedMember();

        $this->assertFalse($this->getJson("/api/borrowers/{$borrower->id}")->assertOk()->json('data.has_valid_id'));

        $borrower->documents()->create([
            'type' => 'valid_id',
            'label' => 'umid',
            'file_path' => 'documents/valid_id/test.jpg',
            'original_filename' => 'test.jpg',
        ]);

        $this->assertTrue($this->getJson("/api/borrowers/{$borrower->id}")->assertOk()->json('data.has_valid_id'));
    }
}

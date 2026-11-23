<?php

namespace Tests\Feature\CsvImport;

use App\Models\Borrower;
use App\Models\CsvImportFile;
use App\Models\CsvImportRow;
use App\Models\CsvImportRun;
use App\Models\Role;
use App\Models\User;
use App\Services\CsvImport\CsvFileRejectedException;
use App\Services\CsvImport\CsvImportProcessor;
use App\Services\CsvImport\CsvImportReader;
use App\Services\CsvImport\CsvImportSchema;
use App\Services\CsvImport\CsvImportStager;
use App\Services\CsvImport\CsvRow;
use App\Services\CsvImport\CustomerRowNormalizer;
use App\Services\CsvImport\ImportTick;
use App\Services\CsvImport\NormalizedRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\BuildsCsvImports;

/**
 * The members file carries every Member Profile field that is not a file:
 * Date Hired and the CDA membership columns, appended after the spouse's, so a
 * file made before them still reads by position (ClickUp 14zfbg0e8er).
 *
 * Valid IDs and the photo are files, so they are not columns at all.
 */
class MemberProfileColumnsTest extends TestCase
{
    use BuildsCsvImports;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // The layout
    // ------------------------------------------------------------------

    public function test_the_four_columns_are_appended_after_the_spouse_columns_and_nothing_before_them_moves(): void
    {
        $labels = CsvImportSchema::labels(CsvImportSchema::CUSTOMERS);

        $this->assertSame([
            'Account No.', 'Last Name', 'First Name', 'Middle Name', 'Suffix', 'Birthdate', 'Gender',
            'Civil Status', 'Contact Number', 'email', 'Street Address', 'Barangay', 'City/Municipality',
            'Province', 'Employer/Business Name', 'Monthly Income', 'Pledge Amt(If Applicable)',
            'Spouse FName (If Married)', 'Spouse MName (If Married)', 'Spouse LName (If Married)',
            'Spouse Contact No (If Married)', 'Spouse Occupation (If Married)',
            'Date Hired', 'Membership Date', 'Member Type', 'Membership Number',
        ], $labels);

        $this->assertSame('date', CsvImportSchema::typeOf(CsvImportSchema::CUSTOMERS, 'date_hired'));
        $this->assertSame('date', CsvImportSchema::typeOf(CsvImportSchema::CUSTOMERS, 'membership_date'));

        // Today's layout first, then the 22-column layout every earlier file
        // has. The loans file is unchanged and has only its own.
        $this->assertSame([26, 22], CsvImportSchema::acceptedWidths(CsvImportSchema::CUSTOMERS));
        $this->assertSame([18], CsvImportSchema::acceptedWidths(CsvImportSchema::LOANS));
    }

    // ------------------------------------------------------------------
    // Saving what the file provides
    // ------------------------------------------------------------------

    public function test_an_import_with_the_new_columns_saves_them(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('A-001', [
                'date_hired' => '2012-06-01',
                'membership_date' => '2010-03-15',
                'member_type' => 'ASSOCIATE',
                'membership_number' => 'bc 0007',
            ]),
            // A membership date with no number: the member's own code becomes
            // their number, as for any member the app creates with a date.
            $this->customerRow('A-002', ['membership_date' => '2015-01-10', 'member_type' => 'regular']),
            // Every new column blank: not set, exactly as before.
            $this->customerRow('A-003'),
        ]);

        (new CsvImportStager)->stage($file);
        $this->runToIdle($run);

        $first = Borrower::where('external_account_no', 'A-001')->firstOrFail();
        $this->assertSame('2012-06-01', $first->date_hired?->toDateString());
        $this->assertSame('2010-03-15', $first->membership_date?->toDateString());
        $this->assertSame('associate', $first->member_type);
        // Stored the way members sign in with it: capitals, no spaces.
        $this->assertSame('BC0007', $first->membership_number);

        $note = (string) CsvImportRow::where('csv_import_file_id', $file->id)->where('line_number', 2)->value('result_message');
        $this->assertStringContainsString('BC0007', $note);

        $second = Borrower::where('external_account_no', 'A-002')->firstOrFail();
        $this->assertSame('2015-01-10', $second->membership_date?->toDateString());
        $this->assertSame('regular', $second->member_type);
        $this->assertSame($second->borrower_code, $second->membership_number);

        $third = Borrower::where('external_account_no', 'A-003')->firstOrFail();
        $this->assertNull($third->date_hired);
        $this->assertNull($third->membership_date);
        $this->assertSame('regular', $third->member_type);
        $this->assertNull($third->membership_number);
    }

    // ------------------------------------------------------------------
    // Old files
    // ------------------------------------------------------------------

    public function test_an_old_file_without_the_new_columns_imports_as_before_with_or_without_its_header(): void
    {
        foreach ([true, false] as $withHeader) {
            $this->seedForImport();
            $suffix = $withHeader ? 'H' : 'N';

            $run = $this->makeRun();
            $file = $this->makeEarlierLayoutFile($run, [
                $this->customerRow("OLD-{$suffix}1"),
                $this->customerRow("OLD-{$suffix}2", ['civil_status' => 'Single']),
            ], $withHeader);

            $result = (new CsvImportStager)->stage($file);
            $this->assertSame(2, $result->valid, 'an old file stages every row');
            $this->assertSame(0, $result->invalid);

            $this->runToIdle($run);

            $file->refresh();
            $this->assertSame(22, $file->column_count, 'the file is recorded at its own width');
            $this->assertSame($withHeader, (bool) $file->header_skipped);

            $member = Borrower::where('external_account_no', "OLD-{$suffix}1")->firstOrFail();
            $this->assertSame('active', $member->status);
            $this->assertSame('Dela Cruz', $member->last_name);
            $this->assertSame('1985-04-12', $member->birthdate?->toDateString());
            $this->assertSame('Cabanatuan Public Market', $member->employer_or_business);
            $this->assertSame('12500.00', (string) $member->pledge_amount);
            // Blank means not set.
            $this->assertNull($member->date_hired);
            $this->assertNull($member->membership_date);
            $this->assertSame('regular', $member->member_type);
            $this->assertNull($member->membership_number);
            $this->assertSame('single', Borrower::where('external_account_no', "OLD-{$suffix}2")->value('civil_status'));
        }
    }

    public function test_a_row_at_the_other_layouts_width_is_refused_rather_than_read_out_of_place(): void
    {
        $this->seedForImport();

        // An old file whose third row grew four cells — an unquoted address
        // with four commas in it. Reading it as a 26-column row would put the
        // street into Barangay and everything after it one field over. The
        // amounts carry no thousands separator, so only the address splits.
        $plain = ['monthly_income' => '18500.00', 'pledge_amount' => '12500.00'];
        $run = $this->makeRun();
        $file = $this->makeEarlierLayoutFile($run, [
            $this->customerRow('S-001', $plain),
            $this->customerRow('S-002', $plain),
            $this->customerRow('S-003', ['street_address' => 'Blk 1, Lot 2, Phase 3, Purok 4, Mabini'] + $plain),
        ], true, quoteCells: false);

        (new CsvImportStager)->stage($file);

        $grown = CsvImportRow::where('csv_import_file_id', $file->id)->where('line_number', 4)->firstOrFail();
        $this->assertSame('invalid', $grown->status);
        $this->assertSame('row_column_count', $grown->errors[0]['code']);
        $this->assertStringContainsString('26 columns but 22 were expected', $grown->errors[0]['message']);

        // And today's layout holds its rows to 26: a 22-cell row there is a
        // row problem, never an old-layout row.
        $run = $this->makeRun();
        $path = "csv-imports/{$run->id}/customers.csv";
        $lines = [
            $this->csvLine(CsvImportSchema::labels(CsvImportSchema::CUSTOMERS)),
            $this->csvLine($this->cells($this->customerRow('T-001'), 26)),
            $this->csvLine($this->cells($this->customerRow('T-002'), 26)),
            $this->csvLine($this->cells($this->customerRow('T-003'), 22)),
        ];
        $file = $this->registerFile($run, $path, implode("\n", $lines)."\n");

        (new CsvImportStager)->stage($file);

        $short = CsvImportRow::where('csv_import_file_id', $file->id)->where('line_number', 4)->firstOrFail();
        $this->assertSame('invalid', $short->status);
        $this->assertStringContainsString('22 columns but 26 were expected', $short->errors[0]['message']);
    }

    public function test_a_file_at_neither_width_is_refused_and_the_message_names_both_layouts(): void
    {
        $row = implode(',', array_fill(0, 24, 'x'));

        try {
            (new CsvImportReader)->read($this->writeTemp(str_repeat($row."\n", 4)), CsvImportSchema::CUSTOMERS);
            $this->fail('A 24-column members file is neither layout and must be refused.');
        } catch (CsvFileRejectedException $e) {
            $this->assertSame('unexpected_column_count', $e->reasonCode);
            $this->assertStringContainsString('24 columns', $e->getMessage());
            $this->assertStringContainsString('26', $e->getMessage());
            $this->assertStringContainsString('22 in the earlier layout', $e->getMessage());
        }
    }

    public function test_an_old_header_is_verified_against_its_own_columns(): void
    {
        $labels = array_slice(CsvImportSchema::labels(CsvImportSchema::CUSTOMERS), 0, 22);
        $data = $this->csvLine($this->cells($this->customerRow('H-001'), 22));

        $result = (new CsvImportReader)->read($this->writeTemp(implode(',', $labels)."\n".$data."\n"), CsvImportSchema::CUSTOMERS);
        $this->assertTrue($result->hasHeader);
        $this->assertSame(22, $result->columnCount);

        [$labels[5], $labels[6]] = [$labels[6], $labels[5]];

        try {
            (new CsvImportReader)->read($this->writeTemp(implode(',', $labels)."\n".$data."\n"), CsvImportSchema::CUSTOMERS);
            $this->fail('An old header in the wrong order must still be refused.');
        } catch (CsvFileRejectedException $e) {
            $this->assertSame('header_mismatch', $e->reasonCode);
            $this->assertStringContainsString('Column 6', $e->getMessage());
        }
    }

    public function test_a_row_staged_before_the_new_columns_still_reads(): void
    {
        $earlier = array_fill(0, 22, null);
        $earlier[0] = 'A-001';

        $row = NormalizedRow::fromPayload(['shape' => CsvImportSchema::CUSTOMERS, 'row' => 1, 'values' => $earlier]);

        $this->assertSame('A-001', $row->value('account_no'));
        $this->assertNull($row->value('date_hired'));
        $this->assertNull($row->value('membership_number'));

        $this->expectException(InvalidArgumentException::class);
        NormalizedRow::fromPayload(['shape' => CsvImportSchema::CUSTOMERS, 'row' => 1, 'values' => array_fill(0, 23, null)]);
    }

    // ------------------------------------------------------------------
    // Validation, in words
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, string>, 1: string, 2: string, 3: string}>
     */
    public static function badValues(): array
    {
        return [
            'date hired after today' => [['date_hired' => '2999-01-01'], 'date_hired', 'date_hired_future', 'can\'t be in the future'],
            'date hired before the birthdate' => [['date_hired' => '1980-01-01'], 'date_hired', 'date_hired_before_birthdate', 'before this member\'s birthdate (1985-04-12)'],
            'date hired not after 1900' => [['date_hired' => '1900-01-01'], 'date_hired', 'date_hired_implausible', 'after January 1, 1900'],
            'date hired unreadable' => [['date_hired' => 'last year'], 'date_hired', 'date_invalid', 'is not a date'],
            'membership date after today' => [['membership_date' => '2999-01-01'], 'membership_date', 'membership_date_future', 'can\'t be in the future'],
            'membership date unreadable' => [['membership_date' => '31/31/2020'], 'membership_date', 'date_invalid', 'is not a date'],
            'member type not a type' => [['member_type' => 'Premium'], 'member_type', 'member_type_invalid', 'Write Regular or Associate'],
            'membership number too long' => [['membership_number' => str_repeat('7', 31)], 'membership_number', 'membership_number_too_long', '30 characters'],
            'membership number reads as a member code' => [['membership_number' => 'brw-000123'], 'membership_number', 'membership_number_member_code', 'member code'],
            'membership number like a member code, no dash' => [['membership_number' => 'BRW000123'], 'membership_number', 'membership_number_member_code', 'starts like a member code (BRW)'],
            'membership number like a member code, a letter O' => [['membership_number' => 'BRW-00O123'], 'membership_number', 'membership_number_member_code', 'starts like a member code (BRW)'],
            'membership number with a character it cannot hold' => [['membership_number' => 'NIÑO-01'], 'membership_number', 'membership_number_invalid', 'letters, numbers and punctuation'],
            'membership number a dash placeholder' => [['membership_number' => '-'], 'membership_number', 'membership_number_placeholder', 'has no digits'],
            'membership number N/A' => [['membership_number' => 'N/A'], 'membership_number', 'membership_number_placeholder', 'has no digits'],
            'membership number a zero' => [['membership_number' => '0'], 'membership_number', 'membership_number_placeholder', 'nothing but zeros'],
            'membership number zeros and a dash' => [['membership_number' => '00-00'], 'membership_number', 'membership_number_placeholder', 'nothing but zeros'],
        ];
    }

    /**
     * @param  array<string, string>  $override
     */
    #[DataProvider('badValues')]
    public function test_a_bad_value_in_a_new_column_refuses_the_row_in_words(array $override, string $field, string $code, string $words): void
    {
        $row = $this->normalize($this->customerRow('V-001', $override), mayImportMembership: true);

        $this->assertFalse($row->isValid(), 'a value the member form would refuse is refused here too');
        $this->assertSame([$field], array_column($row->errorsToArray(), 'field'));
        $this->assertSame([$code], array_column($row->errorsToArray(), 'code'));
        $this->assertStringContainsString($words, $row->errorsToArray()[0]['message']);
        $this->assertDoesNotMatchRegularExpression('/\b[a-z]+_[a-z_]+\b/', $row->errorsToArray()[0]['message'], 'messages read in words, never field keys');
    }

    public function test_good_values_in_the_new_columns_pass(): void
    {
        $row = $this->normalize($this->customerRow('V-002', [
            'date_hired' => '1985-04-12',
            'membership_date' => Carbon::today()->toDateString(),
            'member_type' => ' Associate ',
            'membership_number' => '2019-00101',
        ]), mayImportMembership: true);

        $this->assertTrue($row->isValid());
        $this->assertSame('1985-04-12', $row->value('date_hired'), 'the birthdate itself is not before the birthdate');
        $this->assertSame('associate', $row->value('member_type'));
        $this->assertSame('2019-00101', $row->value('membership_number'));

        // A real number may hold zeros, and letters other than a member code's.
        foreach (['BC-000', 'A1', '10', 'BR-0007'] as $number) {
            $this->assertTrue(
                $this->normalize($this->customerRow('V-003', ['membership_number' => $number]), mayImportMembership: true)->isValid(),
                "{$number} is a membership number",
            );
        }
    }

    public function test_a_refused_value_is_never_imported(): void
    {
        $this->seedForImport();

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('R-001', ['date_hired' => '2999-01-01']),
            $this->customerRow('R-002', ['member_type' => 'Honorary']),
            $this->customerRow('R-003', ['date_hired' => '2001-02-03']),
        ]);

        $staged = (new CsvImportStager)->stage($file);
        $this->assertSame(1, $staged->valid);
        $this->assertSame(2, $staged->invalid);

        $this->runToIdle($run);

        $this->assertFalse(Borrower::where('external_account_no', 'R-001')->exists());
        $this->assertFalse(Borrower::where('external_account_no', 'R-002')->exists());
        $this->assertSame('2001-02-03', Borrower::where('external_account_no', 'R-003')->firstOrFail()->date_hired?->toDateString());
    }

    // ------------------------------------------------------------------
    // Uniqueness
    // ------------------------------------------------------------------

    public function test_a_membership_number_clash_is_refused(): void
    {
        $this->seedForImport();

        Borrower::factory()->create(['membership_number' => 'BC-0007']);

        $run = $this->makeRun();
        $file = $this->makeFile($run, 'customers', [
            // Taken by a member already in the app, in another case.
            $this->customerRow('C-001', ['membership_number' => 'bc-0007']),
            // Two rows of one file with one number: the first keeps it.
            $this->customerRow('C-002', ['membership_number' => 'BC-0100']),
            $this->customerRow('C-003', ['membership_number' => 'BC-0100']),
        ]);

        (new CsvImportStager)->stage($file);
        $this->runToIdle($run);

        $taken = CsvImportRow::where('csv_import_file_id', $file->id)->where('line_number', 2)->firstOrFail();
        $this->assertSame('failed', $taken->result);
        $this->assertSame('membership_number_taken', $taken->result_category);
        $this->assertStringContainsString('BC-0007 is already another member\'s', (string) $taken->result_message);
        $this->assertFalse(Borrower::where('external_account_no', 'C-001')->exists());

        $this->assertSame('BC-0100', Borrower::where('external_account_no', 'C-002')->value('membership_number'));

        $second = CsvImportRow::where('csv_import_file_id', $file->id)->where('line_number', 4)->firstOrFail();
        $this->assertSame('failed', $second->result);
        $this->assertSame('membership_number_taken', $second->result_category);
        $this->assertFalse(Borrower::where('external_account_no', 'C-003')->exists());
    }

    // ------------------------------------------------------------------
    // Permission: the stricter of importing and editing a member
    // ------------------------------------------------------------------

    public function test_only_a_run_started_by_someone_who_can_edit_members_carries_the_membership_columns(): void
    {
        $this->seedForImport();

        [$importer] = $this->staffWith(['imports:process']);
        $this->assertTrue($importer->can('imports:process'));
        $this->assertFalse($importer->can('borrowers:update'));

        $run = $this->makeRun(['initiated_by' => $importer->id]);
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('P-001', ['membership_date' => '2010-03-15']),
            $this->customerRow('P-002', ['member_type' => 'Regular']),
            // Date Hired is an ordinary member field, imported like the rest.
            $this->customerRow('P-003', ['date_hired' => '2012-06-01']),
        ]);

        (new CsvImportStager)->stage($file);

        $refused = CsvImportRow::where('csv_import_file_id', $file->id)->where('line_number', 2)->firstOrFail();
        $this->assertSame('invalid', $refused->status);
        $this->assertSame('membership_needs_member_edit', $refused->errors[0]['code']);
        $this->assertStringContainsString('edit members', $refused->errors[0]['message']);
        $this->assertSame('invalid', CsvImportRow::where('csv_import_file_id', $file->id)->where('line_number', 3)->value('status'));

        $this->runToIdle($run);

        $this->assertFalse(Borrower::where('external_account_no', 'P-001')->exists());
        $this->assertSame('2012-06-01', Borrower::where('external_account_no', 'P-003')->firstOrFail()->date_hired?->toDateString());

        // A run whose starter has left (the column is nullOnDelete) carries
        // none either: nobody can be credited with editing members.
        $orphan = $this->makeRun(['initiated_by' => null]);
        $orphanFile = $this->makeFile($orphan, 'customers', [$this->customerRow('P-004', ['membership_number' => 'X-1'])]);
        (new CsvImportStager)->stage($orphanFile);
        $this->assertSame('invalid', CsvImportRow::where('csv_import_file_id', $orphanFile->id)->value('status'));
    }

    public function test_access_lost_after_staging_fails_the_rows_that_carry_membership_columns(): void
    {
        $this->seedForImport();

        [$starter, $role] = $this->staffWith(['imports:process', 'borrowers:update']);

        $run = $this->makeRun(['initiated_by' => $starter->id]);
        $file = $this->makeFile($run, 'customers', [
            $this->customerRow('L-001', ['membership_date' => '2010-03-15']),
            $this->customerRow('L-002', ['date_hired' => '2012-06-01']),
        ]);

        $this->assertSame(2, (new CsvImportStager)->stage($file)->valid, 'the starter could edit members when the rows were staged');

        // While the run waited (on its product mapping, say), the role lost it.
        $role->revokePermissionTo('borrowers:update');

        $this->runToIdle($run);

        $lost = CsvImportRow::where('csv_import_file_id', $file->id)->where('line_number', 2)->firstOrFail();
        $this->assertSame('failed', $lost->result);
        $this->assertSame('membership_needs_member_edit', $lost->result_category);
        $this->assertStringContainsString('Only staff who can edit members can import membership details', (string) $lost->result_message);
        $this->assertFalse(Borrower::where('external_account_no', 'L-001')->exists());

        // A row with no membership value imports as usual.
        $this->assertSame('2012-06-01', Borrower::where('external_account_no', 'L-002')->firstOrFail()->date_hired?->toDateString());
    }

    public function test_an_inactive_starter_carries_no_membership_columns_at_staging_or_at_import(): void
    {
        $this->seedForImport();

        // Inactive when the rows are staged: refused there, though the role grants it.
        [$inactive] = $this->staffWith(['imports:process', 'borrowers:update']);
        $inactive->forceFill(['status' => 'inactive'])->save();

        $run = $this->makeRun(['initiated_by' => $inactive->id]);
        $file = $this->makeFile($run, 'customers', [$this->customerRow('I-001', ['member_type' => 'Associate'])]);
        (new CsvImportStager)->stage($file);

        $staged = CsvImportRow::where('csv_import_file_id', $file->id)->firstOrFail();
        $this->assertSame('invalid', $staged->status);
        $this->assertSame('membership_needs_member_edit', $staged->errors[0]['code']);

        // Active when staged, deactivated before the import pass: refused there.
        [$starter] = $this->staffWith(['imports:process', 'borrowers:update']);
        $run = $this->makeRun(['initiated_by' => $starter->id]);
        $file = $this->makeFile($run, 'customers', [$this->customerRow('I-002', ['membership_number' => 'BC-0200'])]);
        $this->assertSame(1, (new CsvImportStager)->stage($file)->valid);

        $starter->forceFill(['status' => 'inactive'])->save();
        $this->runToIdle($run);

        $this->assertSame('membership_needs_member_edit', CsvImportRow::where('csv_import_file_id', $file->id)->value('result_category'));
        $this->assertFalse(Borrower::where('external_account_no', 'I-002')->exists());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function runToIdle(CsvImportRun $run, int $maxTicks = 200): ImportTick
    {
        $processor = new CsvImportProcessor;
        $tick = new ImportTick($run->phase, 0);

        for ($i = 0; $i < $maxTicks; $i++) {
            $tick = $processor->advance($run);

            if ($tick->idle) {
                return $tick;
            }

            $run->refresh();
        }

        $this->fail("The run did not settle within {$maxTicks} ticks.");
    }

    /**
     * A staff user holding exactly these permissions, through a role of their own.
     *
     * @param  list<string>  $permissions
     * @return array{0: User, 1: Role}
     */
    private function staffWith(array $permissions): array
    {
        $role = Role::create(['name' => 'csv_test_'.strtolower(Str::random(8)), 'guard_name' => 'web', 'is_active' => true]);
        $role->givePermissionTo($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);

        return [$user, $role];
    }

    /**
     * @param  array<string, string>  $values
     */
    private function normalize(array $values, bool $mayImportMembership): NormalizedRow
    {
        return (new CustomerRowNormalizer)->normalize(new CsvRow(1, 1, $this->cells($values, 26)), $mayImportMembership);
    }

    /**
     * A positional row of the given width, from field-keyed values.
     *
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function cells(array $values, int $width): array
    {
        $keys = array_slice(CsvImportSchema::keys(CsvImportSchema::CUSTOMERS), 0, $width);
        $cells = array_fill(0, $width, '');

        foreach ($values as $key => $value) {
            $index = array_search($key, $keys, true);

            if ($index !== false) {
                $cells[$index] = $value;
            }
        }

        return $cells;
    }

    /**
     * A members file as every file made before this change looks: the
     * workbook's 22 columns, ending at Spouse Occupation.
     *
     * @param  list<array<string, string>>  $rows
     */
    private function makeEarlierLayoutFile(CsvImportRun $run, array $rows, bool $withHeader, bool $quoteCells = true): CsvImportFile
    {
        $width = CsvImportSchema::indexOf(CsvImportSchema::CUSTOMERS, 'spouse_occupation') + 1;
        $lines = [];

        if ($withHeader) {
            $lines[] = $this->csvLine(array_slice(CsvImportSchema::labels(CsvImportSchema::CUSTOMERS), 0, $width));
        }

        foreach ($rows as $row) {
            $cells = $this->cells($row, $width);
            $lines[] = $quoteCells ? $this->csvLine($cells) : implode(',', $cells);
        }

        return $this->registerFile($run, "csv-imports/{$run->id}/customers.csv", implode("\n", $lines)."\n");
    }

    private function registerFile(CsvImportRun $run, string $path, string $contents): CsvImportFile
    {
        Storage::disk('private')->put($path, $contents);

        return CsvImportFile::create([
            'csv_import_run_id' => $run->id,
            'kind' => 'customers',
            'original_filename' => 'customers.csv',
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'chunk_size' => 1024 * 1024,
            'total_chunks' => 1,
            'assembled_path' => $path,
            'assembled_sha256' => hash('sha256', $contents),
        ]);
    }

    private function writeTemp(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'memberprofile');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}

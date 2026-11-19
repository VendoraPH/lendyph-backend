<?php

namespace Tests\Feature\Phase6\Amla\Screening;

use App\Exceptions\AmlaRecordIsAppendOnlyException;
use App\Models\AmlaLogEntry;
use App\Models\AmlaWatchlistEntry;
use App\Models\AmlaWatchlistVersion;
use App\Services\Amla\Screening\WatchlistParser;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Watchlists (AMLA design §4.11; task §11): uploads only, never an outbound
 * call. The UN consolidated list comes as its XML; AMLC designations and
 * internal lists use the CSV template. Each upload is a version of its list,
 * kept forever; a new one supersedes the last and queues a rescreen of
 * everyone. A malformed file is refused in words and nothing is kept.
 * Screening needs `amla:screening` and head-office access.
 */
class WatchlistUploadTest extends TestCase
{
    use ScreeningTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_un_list_is_read_from_its_xml_with_aliases_birth_years_and_entities(): void
    {
        $screener = $this->screener();
        $before = $this->outsideAmlaCounts();

        $version = $this->upload($screener, 'un', $this->unXml([
            ['ref' => 'QDi.001', 'first' => 'JUAN', 'second' => 'DELA', 'third' => 'CRUZ', 'aliases' => ['Johnny Cruz'], 'dob' => '1975-03-04'],
            ['ref' => 'QDi.002', 'first' => 'MARIA', 'second' => 'SANTOS', 'year' => '1980'],
        ], [['ref' => 'QDe.010', 'name' => 'ACME TRADING GROUP']]), 'consolidated.xml')
            ->assertCreated()->json('data');

        $this->assertSame('un', $version['source']);
        $this->assertSame('UN Security Council consolidated list', $version['source_words']);
        $this->assertSame(3, $version['entry_count']);
        $this->assertSame('2026-11-18T10:00:00-05:00', $version['version_label']);
        $this->assertSame('active', $version['status']);
        $this->assertTrue($version['rescreen_pending']);

        $juan = AmlaWatchlistEntry::query()->where('reference', 'QDi.001')->sole();
        $this->assertSame('individual', $juan->kind);
        $this->assertSame('JUAN DELA CRUZ', $juan->full_name);
        $this->assertSame(['Johnny Cruz'], $juan->aliases);
        $this->assertSame([1975], $juan->birth_years);
        $this->assertSame([1980], AmlaWatchlistEntry::query()->where('reference', 'QDi.002')->sole()->birth_years);
        $this->assertSame('entity', AmlaWatchlistEntry::query()->where('reference', 'QDe.010')->sole()->kind);

        $this->assertSame(1, AmlaLogEntry::query()->where('action', 'watchlist_uploaded')->count());
        $this->assertNothingWrittenOutsideAmla($before);
    }

    public function test_amlc_and_internal_lists_use_the_csv_template(): void
    {
        $data = $this->upload($this->screener(), 'amlc', $this->csvList([
            ['AMLC-2026-01', 'individual', 'Pedro Penduko', 'Pedring P.|Pedro P', '1970', 'PH'],
            ['AMLC-2026-02', 'entity', 'Kalayaan Holdings Inc', '', '', ''],
        ]), 'designations.csv', 'Resolution No. 12, 2026')->assertCreated()->json('data');

        $this->assertSame(2, $data['entry_count']);
        $this->assertSame('Resolution No. 12, 2026', $data['version_label']);
        $pedro = AmlaWatchlistEntry::query()->where('reference', 'AMLC-2026-01')->sole();
        $this->assertSame(['Pedring P.', 'Pedro P'], $pedro->aliases);
        $this->assertSame([1970], $pedro->birth_years);

        $this->upload($this->screener(), 'internal', $this->csvList([['INT-1', 'individual', 'Ana Reyes', '', '', '']]), 'internal.csv', 'Board list')
            ->assertCreated()->assertJsonPath('data.source_words', 'Internal list');
    }

    public function test_a_malformed_file_is_refused_in_words_and_nothing_is_kept(): void
    {
        $screener = $this->screener();

        $this->upload($screener, 'un', '<CONSOLIDATED_LIST><INDIVIDUALS><INDIVIDUAL>', 'broken.xml')
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'The file isn\'t a readable UN consolidated list (its XML is broken).');

        $this->upload($screener, 'un', "<?xml version=\"1.0\"?>\n<!DOCTYPE x [<!ENTITY a \"aaaa\">]><CONSOLIDATED_LIST><INDIVIDUALS/></CONSOLIDATED_LIST>", 'entities.xml')
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'The file declares its own document type, which a UN list never does, so it isn\'t read.');

        $this->upload($screener, 'un', '<?xml version="1.0"?><SOMETHING_ELSE/>', 'other.xml')
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'The file isn\'t a UN consolidated list: it has no CONSOLIDATED_LIST.');

        $this->upload($screener, 'amlc', "name,birthday\nPedro,1970\n", 'wrong.csv')
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'The first line must be the template\'s columns: reference, type, full_name, aliases, birth_year, nationality.');

        $this->upload($screener, 'amlc', $this->csvList([
            ['A-1', 'individual', '', '', '', ''],
            ['A-2', 'robot', 'Someone', '', '19x0', ''],
        ]), 'rows.csv')->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'Row 2: the full name is missing.')
            ->assertJsonPath('errors.file.1', 'Row 3: the type must be individual or entity.')
            ->assertJsonPath('errors.file.2', 'Row 3: the birth year must be four digits, or a range such as 1960-1965.');

        $this->upload($screener, 'amlc', $this->csvList([]), 'empty.csv')
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'The list has no entries.');

        $this->upload($screener, 'un', 'just text', 'list.pdf')->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->upload($screener, 'amlc', $this->csvList([['A-1', 'individual', 'X Y', '', '', '']]), 'list.xml')->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $this->assertSame(0, AmlaWatchlistVersion::query()->count());
        $this->assertSame(0, AmlaWatchlistEntry::query()->count());
    }

    public function test_a_new_version_supersedes_the_last_and_both_are_kept(): void
    {
        $screener = $this->screener();
        $first = $this->upload($screener, 'internal', $this->csvList([['I-1', 'individual', 'Ana Reyes', '', '', '']]), 'one.csv', 'v1')->json('data.id');
        $second = $this->upload($screener, 'internal', $this->csvList([['I-1', 'individual', 'Ana Reyes', '', '', ''], ['I-2', 'individual', 'Ben Cruz', '', '', '']]), 'two.csv', 'v2')->json('data.id');

        $this->assertSame('superseded', AmlaWatchlistVersion::query()->findOrFail($first)->status);
        $this->assertSame('active', AmlaWatchlistVersion::query()->findOrFail($second)->status);

        $lists = collect($this->actingAs($screener)->getJson('/api/amla/screening/lists')->assertOk()->json('data'))->keyBy('source');
        $this->assertSame($second, $lists['internal']['active_version']['id']);
        $this->assertCount(2, $lists['internal']['versions']);
        $this->assertNull($lists['un']['active_version']);

        $this->expectException(AmlaRecordIsAppendOnlyException::class);
        AmlaWatchlistEntry::query()->where('version_id', $first)->delete();
    }

    public function test_lists_need_the_screening_key_and_head_office_access(): void
    {
        $csv = $this->csvList([['I-1', 'individual', 'Ana Reyes', '', '', '']]);

        $this->upload($this->amlaUser(['amla:screening']), 'internal', $csv, 'a.csv')->assertForbidden()
            ->assertJsonPath('message', 'AMLA compliance covers the whole cooperative, so this needs head-office access to all branches.');
        $this->upload($this->amlaUser(['amla:cdd', 'amla:sanctions', 'branches:view_all']), 'internal', $csv, 'a.csv')->assertForbidden()
            ->assertJsonPath('message', 'This needs the AMLA screening permission.');
        $this->actingAs($this->amlaUser(['amla:view', 'branches:view_all']))->getJson('/api/amla/screening/lists')->assertForbidden();
        $this->upload($this->screener(), 'ofac', $csv, 'a.csv')->assertNotFound();
    }

    public function test_birth_year_ranges_are_kept_as_every_year_in_them(): void
    {
        $xml = str_replace(
            '<INDIVIDUAL_DATE_OF_BIRTH><TYPE_OF_DATE>APPROXIMATELY</TYPE_OF_DATE><YEAR>1960</YEAR></INDIVIDUAL_DATE_OF_BIRTH>',
            '<INDIVIDUAL_DATE_OF_BIRTH><TYPE_OF_DATE>BETWEEN</TYPE_OF_DATE><FROM_YEAR>1960</FROM_YEAR><TO_YEAR>1965</TO_YEAR></INDIVIDUAL_DATE_OF_BIRTH>',
            $this->unXml([['ref' => 'QDi.050', 'first' => 'ISNILON', 'second' => 'TOTONI', 'third' => 'HAPILON', 'year' => '1960']]),
        );
        $this->upload($this->screener(), 'un', $xml, 'list.xml')->assertCreated();
        $this->assertSame(range(1960, 1965), AmlaWatchlistEntry::query()->where('reference', 'QDi.050')->sole()->birth_years);

        $this->upload($this->screener(), 'internal', $this->csvList([['I-9', 'individual', 'Ana Reyes', '', '1970-1972', '']]), 'r.csv')->assertCreated();
        $this->assertSame([1970, 1971, 1972], AmlaWatchlistEntry::query()->where('reference', 'I-9')->sole()->birth_years);
    }

    public function test_a_list_that_isnt_utf8_is_refused_so_a_utf16_document_type_never_gets_past(): void
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-16\"?>\n<!DOCTYPE x [<!ENTITY a \"aaaa\">]><CONSOLIDATED_LIST><INDIVIDUALS/></CONSOLIDATED_LIST>";
        $utf16 = "\xFF\xFE".mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8');

        try {
            app(WatchlistParser::class)->un($utf16);
            $this->fail('A UTF-16 list was read.');
        } catch (ValidationException $refused) {
            $this->assertSame('The UN list must be UTF-8 XML.', $refused->errors()['file'][0]);
        }

        $this->upload($this->screener(), 'un', $utf16, 'utf16.xml')->assertUnprocessable();
        $this->assertSame(0, AmlaWatchlistVersion::query()->count());
    }
}

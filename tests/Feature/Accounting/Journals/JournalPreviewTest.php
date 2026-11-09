<?php

namespace Tests\Feature\Accounting\Journals;

use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\Money;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * POST /accounting/journals/preview: the entry form's totals and "out by"
 * figure come from the server, summed exactly as posting sums them, and the
 * call writes nothing (Phase 2, Accounting 10).
 */
class JournalPreviewTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    public function test_the_preview_equals_what_a_saved_draft_stores_for_the_same_lines(): void
    {
        $lines = [
            ['account_id' => $this->account('5030'), 'debit' => 123457, 'credit' => 0],
            ['account_id' => $this->account('5040'), 'debit' => 1, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 123458],
        ];

        $preview = $this->postJson('/api/accounting/journals/preview', ['lines' => $lines])->assertOk()->json('data');

        $draft = $this->postJson('/api/accounting/journals', [
            'date' => '2026-09-15',
            'description' => 'Utilities',
            'lines' => $lines,
        ])->assertCreated()->json('data');

        $stored = AccountingJournalLine::query()->where('accounting_journal_id', $draft['id']);

        $this->assertSame((int) (clone $stored)->sum('debit'), $preview['total_debit']);
        $this->assertSame((int) (clone $stored)->sum('credit'), $preview['total_credit']);
        $this->assertSame($draft['total_debit'], $preview['total_debit']);
        $this->assertSame($draft['total_credit'], $preview['total_credit']);
        $this->assertSame((clone $stored)->count(), $preview['line_count']);

        $posted = app(JournalPoster::class)->post(AccountingJournal::findOrFail($draft['id']), $this->admin->id);
        $this->assertSame($posted->total_debit, $preview['total_debit']);
        $this->assertSame($posted->total_credit, $preview['total_credit']);
    }

    public function test_a_balanced_body(): void
    {
        $this->preview([[100050, 0], [0, 50025], [0, 50025]])->assertOk()->assertExactJson(['data' => [
            'total_debit' => 100050,
            'total_credit' => 100050,
            'difference' => 0,
            'out_by' => 0,
            'heavier_side' => null,
            'is_balanced' => true,
            'line_count' => 3,
        ]]);
    }

    public function test_an_unbalanced_body_names_the_heavier_side_and_the_size(): void
    {
        $this->preview([[150000, 0], [0, 100000]])->assertOk()
            ->assertJsonPath('data.difference', 50000)
            ->assertJsonPath('data.out_by', 50000)
            ->assertJsonPath('data.heavier_side', 'debit')
            ->assertJsonPath('data.is_balanced', false);

        $this->preview([[1, 0], [0, 100000]])->assertOk()
            ->assertJsonPath('data.difference', -99999)
            ->assertJsonPath('data.out_by', 99999)
            ->assertJsonPath('data.heavier_side', 'credit')
            ->assertJsonPath('data.is_balanced', false);
    }

    public function test_an_empty_body_and_a_one_line_body(): void
    {
        $zero = [
            'total_debit' => 0, 'total_credit' => 0, 'difference' => 0, 'out_by' => 0,
            'heavier_side' => null, 'is_balanced' => true, 'line_count' => 0,
        ];

        $this->postJson('/api/accounting/journals/preview', [])->assertOk()->assertExactJson(['data' => $zero]);
        $this->postJson('/api/accounting/journals/preview', ['lines' => []])->assertOk()->assertExactJson(['data' => $zero]);

        // One line and blank rows: the form is mid-entry. No two-line minimum
        // and no account check, so the totals still answer.
        $this->postJson('/api/accounting/journals/preview', ['lines' => [
            ['debit' => 2500, 'credit' => 0],
        ]])->assertOk()
            ->assertJsonPath('data.total_debit', 2500)
            ->assertJsonPath('data.out_by', 2500)
            ->assertJsonPath('data.heavier_side', 'debit')
            ->assertJsonPath('data.line_count', 1);

        $this->postJson('/api/accounting/journals/preview', ['lines' => [
            ['account_id' => null, 'debit' => 0, 'credit' => 0],
            ['account_id' => 999999, 'debit' => 0, 'credit' => 700],
        ]])->assertOk()
            ->assertJsonPath('data.total_credit', 700)
            ->assertJsonPath('data.heavier_side', 'credit')
            ->assertJsonPath('data.line_count', 2);
    }

    public function test_lines_at_the_ceiling_answer_exactly_and_past_it_answer_422(): void
    {
        $max = Money::maxCentavos();
        $lines = array_fill(0, JournalPoster::MAX_LINES - 1, ['debit' => $max, 'credit' => 0]);
        $lines[] = ['debit' => 0, 'credit' => $max];

        $this->postJson('/api/accounting/journals/preview', ['lines' => $lines])->assertOk()
            ->assertJsonPath('data.total_debit', $max * (JournalPoster::MAX_LINES - 1))
            ->assertJsonPath('data.total_credit', $max)
            ->assertJsonPath('data.difference', $max * (JournalPoster::MAX_LINES - 2))
            ->assertJsonPath('data.out_by', $max * (JournalPoster::MAX_LINES - 2))
            ->assertJsonPath('data.line_count', JournalPoster::MAX_LINES);

        $this->preview([[$max + 1, 0]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.debit');
        $this->preview([[0, -1]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.credit');
        $this->postJson('/api/accounting/journals/preview', ['lines' => [['debit' => 10.5, 'credit' => 0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.debit');
        $this->postJson('/api/accounting/journals/preview', ['lines' => [['debit' => '1e3', 'credit' => 0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.debit');

        $tooMany = array_fill(0, JournalPoster::MAX_LINES + 1, ['debit' => 1, 'credit' => 0]);
        $this->postJson('/api/accounting/journals/preview', ['lines' => $tooMany])
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
    }

    public function test_it_needs_journals_create(): void
    {
        $this->actingAs($this->userWithNoRole());
        $this->preview([[100, 0], [0, 100]])->assertForbidden();

        $viewer = $this->userWithNoRole();
        $viewer->givePermissionTo('journals:view');
        $this->actingAs($viewer);
        $this->preview([[100, 0], [0, 100]])->assertForbidden();

        $creator = $this->userWithNoRole();
        $creator->givePermissionTo('journals:create');
        $this->actingAs($creator);
        $this->preview([[100, 0], [0, 100]])->assertOk();
    }

    public function test_it_writes_nothing(): void
    {
        $this->postSimpleJournal('5030', '1010', 5000);

        $before = $this->footprint();

        $this->preview([[100, 0], [0, 100]])->assertOk();
        $this->preview([[100, 0]])->assertOk();

        $this->assertSame($before, $this->footprint());
    }

    /** @param  list<array{0:int, 1:int}>  $sides */
    private function preview(array $sides): TestResponse
    {
        return $this->postJson('/api/accounting/journals/preview', [
            'lines' => array_map(fn (array $side): array => [
                'account_id' => $this->account($side[0] > 0 ? '5030' : '1010'),
                'debit' => $side[0],
                'credit' => $side[1],
            ], $sides),
        ]);
    }

    /** @return array<string, int|null> */
    private function footprint(): array
    {
        return [
            'journals' => AccountingJournal::query()->count(),
            'journal_max_id' => AccountingJournal::query()->max('id'),
            'lines' => AccountingJournalLine::query()->count(),
            'line_max_id' => AccountingJournalLine::query()->max('id'),
        ];
    }
}

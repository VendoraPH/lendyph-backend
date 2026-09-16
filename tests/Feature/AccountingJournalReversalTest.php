<?php

namespace Tests\Feature;

use App\Models\AccountingJournal;
use App\Services\Accounting\TrialBalanceBuilder;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Reversal: the only way to correct a posted entry.
 *
 * The rule the whole module rests on is that a posted entry is never edited and
 * never deleted. Correcting one means writing a second entry that mirrors it,
 * so both halves stay visible and the pair nets to zero — which is what makes
 * the books auditable rather than merely current. A reader can see what was
 * believed, when, and what replaced it.
 */
class AccountingJournalReversalTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    public function test_a_reversal_mirrors_every_line_and_links_both_entries(): void
    {
        // The loan collection shape: one debit against three credits.
        $original = $this->postJournal([
            ['account_id' => $this->account('1010'), 'debit' => 500000, 'credit' => 0],
            ['account_id' => $this->account('1110'), 'debit' => 0, 'credit' => 400000],
            ['account_id' => $this->account('4010'), 'debit' => 0, 'credit' => 90000],
            ['account_id' => $this->account('4020'), 'debit' => 0, 'credit' => 10000],
        ], ['source' => 'loan_collection', 'reference' => 'COL-10254', 'description' => 'Collection from Juan Dela Cruz']);

        $response = $this->postJson("/api/accounting/journals/{$original->id}/reverse", [
            'date' => '2026-09-20',
            'reason' => 'Posted against the wrong borrower',
        ])->assertCreated();

        $reversal = AccountingJournal::query()->findOrFail($response->json('data.id'));

        $this->assertSame('reversal', $reversal->source);
        $this->assertSame('posted', $reversal->status);
        $this->assertSame('2026-09-20', $reversal->date->toDateString());
        $this->assertSame($original->id, $reversal->reverses_journal_id);

        // Every debit becomes a credit against the SAME account, in the same
        // order, so the pair nets to zero account by account.
        $mirrored = $reversal->lines()->get()->map(fn ($line): array => [
            $line->accounting_account_id, (int) $line->debit, (int) $line->credit,
        ])->all();

        $this->assertSame([
            [$this->account('1010'), 0, 500000],
            [$this->account('1110'), 400000, 0],
            [$this->account('4010'), 90000, 0],
            [$this->account('4020'), 10000, 0],
        ], $mirrored);

        $this->assertSame(500000, (int) $reversal->total_debit);
        $this->assertSame(500000, (int) $reversal->total_credit);

        // Both link columns, in both directions.
        $original->refresh();
        $this->assertSame('reversed', $original->status);
        $this->assertSame($reversal->id, $original->reversed_by_journal_id);
    }

    public function test_the_reversal_names_the_entry_it_undoes_and_keeps_the_reason(): void
    {
        $original = $this->postSimpleJournal('5030', '1010', 350000, [
            'description' => 'Electricity for September',
        ]);

        $response = $this->postJson("/api/accounting/journals/{$original->id}/reverse", [
            'reason' => 'Duplicate of JE-000001',
        ])->assertCreated();

        $description = $response->json('data.description');

        $this->assertStringContainsString('JE-000001', $description);
        $this->assertStringContainsString('Electricity for September', $description);
        // There is no column for the operator's reason, and it is the most
        // useful half of a reversal — so it is folded into the description
        // rather than dropped.
        $this->assertStringContainsString('Duplicate of JE-000001', $description);
    }

    public function test_the_original_entry_is_untouched_apart_from_its_two_link_columns(): void
    {
        $original = $this->postSimpleJournal('5030', '1010', 350000);
        $before = $original->replicate();

        $this->postJson("/api/accounting/journals/{$original->id}/reverse")->assertCreated();

        $original->refresh();

        // What was believed, and when, has to survive the correction.
        $this->assertSame($before->journal_no, $original->journal_no);
        $this->assertSame($before->date->toDateString(), $original->date->toDateString());
        $this->assertSame($before->description, $original->description);
        $this->assertSame((int) $before->total_debit, (int) $original->total_debit);
        $this->assertSame(2, $original->lines()->count());
    }

    public function test_the_pair_nets_to_zero_in_the_trial_balance(): void
    {
        $original = $this->postSimpleJournal('5030', '1010', 350000);

        // A second, unrelated entry so the report is not trivially empty.
        $this->postSimpleJournal('1010', '3010', 1000000);

        $this->postJson("/api/accounting/journals/{$original->id}/reverse", ['date' => '2026-09-20'])
            ->assertCreated();

        $trialBalance = app(TrialBalanceBuilder::class)->build('2026-12-31');

        $rows = collect($trialBalance['rows'])->keyBy('account_code');

        // Electricity moved only in the reversed pair, so it nets out and drops
        // off the report entirely.
        $this->assertFalse($rows->has('5030'));

        // Cash keeps only the capital contribution: 350000 out, 350000 back,
        // 1000000 in.
        $this->assertSame(1000000, $rows['1010']['debit']);
        $this->assertTrue($trialBalance['is_balanced']);
    }

    public function test_reversing_twice_is_refused(): void
    {
        $original = $this->postSimpleJournal('5030', '1010', 350000);

        $this->postJson("/api/accounting/journals/{$original->id}/reverse")->assertCreated();

        $this->postJson("/api/accounting/journals/{$original->id}/reverse")
            ->assertStatus(422)
            ->assertJsonValidationErrors('journal');

        // Exactly one reversal exists — a second would double the correction
        // and leave the books off by the original amount.
        $this->assertSame(1, AccountingJournal::query()->where('source', 'reversal')->count());
    }

    public function test_a_draft_cannot_be_reversed(): void
    {
        $draft = $this->draftJournal([
            ['account_id' => $this->account('5030'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100],
        ]);

        // A draft is not in the books, so there is nothing to undo. Deleting it
        // is the correct remedy and is allowed.
        $this->postJson("/api/accounting/journals/{$draft->id}/reverse")
            ->assertStatus(422)
            ->assertJsonValidationErrors('journal');
    }

    public function test_a_reversal_can_itself_be_reversed(): void
    {
        $original = $this->postSimpleJournal('5030', '1010', 350000);

        $first = $this->postJson("/api/accounting/journals/{$original->id}/reverse")->assertCreated();
        $reversalId = $first->json('data.id');

        // Reversing the reversal re-instates the original amount — a real
        // remedy for a reversal posted in error, and the reason the rule is
        // "not already reversed" rather than "not a reversal".
        $second = $this->postJson("/api/accounting/journals/{$reversalId}/reverse")->assertCreated();

        $this->assertSame($reversalId, AccountingJournal::query()->findOrFail($second->json('data.id'))->reverses_journal_id);

        $rows = collect(app(TrialBalanceBuilder::class)->build('2026-12-31')['rows'])->keyBy('account_code');
        $this->assertSame(350000, $rows['5030']['debit']);
    }

    public function test_a_bookkeeper_may_draft_but_may_neither_post_nor_reverse(): void
    {
        $original = $this->postSimpleJournal('5030', '1010', 350000);

        $draft = $this->draftJournal([
            ['account_id' => $this->account('5030'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100],
        ]);

        // Preparer and approver being different people is what this split makes
        // real: drafting is reversible and nothing reaches the ledger until
        // someone else posts it, while posting makes an entry immutable and
        // reversing writes a second one against it.
        $this->actingAs($this->userWithRole('general_bookkeeper'));

        $this->getJson("/api/accounting/journals/{$original->id}")->assertOk();
        $this->postJson("/api/accounting/journals/{$draft->id}/post")->assertForbidden();
        $this->postJson("/api/accounting/journals/{$original->id}/reverse")->assertForbidden();

        $this->assertSame('posted', $original->fresh()->status);
        $this->assertSame('draft', $draft->fresh()->status);
    }
}

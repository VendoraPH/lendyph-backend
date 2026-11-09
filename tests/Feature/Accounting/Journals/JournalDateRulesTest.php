<?php

namespace Tests\Feature\Accounting\Journals;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Manual journal dates (follow-up round 3, Lead's call 1).
 *
 * - A manual journal with no branch and a line on the allowance account (the
 *   `allowance_credit_losses` mapping, 1200 by default) can't be dated after
 *   today in Manila, on create, edit or reversal. An allowance run reads the
 *   no-branch 1200 balance as of its own date, so an entry waiting in the
 *   future could move that balance after a run already provided against it.
 * - A manual journal's date is a plain day (`Y-m-d`), so a date sent with an
 *   offset is refused instead of being stored as the sender's day.
 */
class JournalDateRulesTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const TODAY = 'Nov 11, 2026';

    private const FUTURE_REFUSAL = 'An entry with no branch that has a line on 1200 Allowance for Credit Losses can\'t be dated after today ('.self::TODAY.'). Date it '.self::TODAY.' or earlier, or choose a branch.';

    private const REVERSAL_REFUSAL = 'This entry has no branch and a line on 1200 Allowance for Credit Losses, so its reversal can\'t be dated after today ('.self::TODAY.'). Date it from Nov 1, 2026 to '.self::TODAY.'.';

    private const FUTURE_ENTRY_REVERSAL_REFUSAL = 'This entry has no branch, a line on 1200 Allowance for Credit Losses and a date after today ('.self::TODAY.'), so its reversal can only be dated on the entry\'s own date. Date it Dec 1, 2026.';

    protected function setUp(): void
    {
        parent::setUp();
        // 00:30 on Nov 11 in Manila is still Nov 10 in UTC: "today" has to be
        // Manila's.
        $this->travelTo(Carbon::parse('2026-11-11 00:30:00', 'Asia/Manila'));
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    /** @return array<string, mixed> */
    private function entry(string $date, string $debitCode = '1200', string $creditCode = '3050', ?int $branchId = null): array
    {
        return [
            'date' => $date,
            'description' => 'Move the allowance',
            'branch_id' => $branchId,
            'lines' => [
                ['account_id' => $this->account($debitCode), 'debit' => 70_000, 'credit' => 0],
                ['account_id' => $this->account($creditCode), 'debit' => 0, 'credit' => 70_000],
            ],
        ];
    }

    public function test_a_no_branch_allowance_entry_dated_after_today_is_refused_on_create(): void
    {
        $this->postJson('/api/accounting/journals', $this->entry('2026-11-12'))
            ->assertStatus(422)
            ->assertJsonPath('errors.date.0', self::FUTURE_REFUSAL);

        $this->assertSame(0, AccountingJournal::query()->count());

        // A credit on the allowance counts the same as a debit.
        $this->postJson('/api/accounting/journals', $this->entry('2026-12-31', '3050', '1200'))
            ->assertStatus(422)
            ->assertJsonPath('errors.date.0', self::FUTURE_REFUSAL);

        // Today in Manila is allowed, and so is any earlier day.
        $this->postJson('/api/accounting/journals', $this->entry('2026-11-11'))->assertCreated();
        $this->postJson('/api/accounting/journals', $this->entry('2026-11-10'))->assertCreated();
    }

    public function test_a_future_date_is_still_allowed_with_a_branch_or_without_an_allowance_line(): void
    {
        $this->postJson('/api/accounting/journals', $this->entry('2026-11-12', branchId: $this->branch->id))
            ->assertCreated();

        $this->postJson('/api/accounting/journals', $this->entry('2026-11-12', '5030', '1010'))
            ->assertCreated();
    }

    public function test_a_no_branch_allowance_draft_cant_be_edited_to_a_date_after_today(): void
    {
        $id = $this->postJson('/api/accounting/journals', $this->entry('2026-11-11'))->assertCreated()->json('data.id');

        $this->putJson("/api/accounting/journals/{$id}", $this->entry('2026-11-12'))
            ->assertStatus(422)
            ->assertJsonPath('errors.date.0', self::FUTURE_REFUSAL);

        $this->assertSame('2026-11-11', AccountingJournal::query()->findOrFail($id)->date->toDateString());

        $this->putJson("/api/accounting/journals/{$id}", $this->entry('2026-11-09'))->assertOk();
    }

    public function test_the_allowance_account_is_the_one_the_mapping_names(): void
    {
        $allowance = AccountingAccount::query()->findOrFail($this->account('1200'));
        $other = AccountingAccount::create([
            'code' => '1205',
            'name' => 'Allowance for Doubtful Accounts',
            'type' => 'asset',
            'is_contra' => true,
            'parent_id' => $allowance->parent_id,
            'created_by' => $this->admin->id,
        ]);
        AccountingAccountMapping::query()->where('role', 'allowance_credit_losses')->update(['accounting_account_id' => $other->id]);

        $lines = fn (int $accountId): array => [
            ['account_id' => $accountId, 'debit' => 70_000, 'credit' => 0],
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 70_000],
        ];

        $this->postJson('/api/accounting/journals', ['date' => '2026-11-12', 'description' => 'Mapped', 'lines' => $lines($other->id)])
            ->assertStatus(422)
            ->assertJsonPath('errors.date.0', str_replace('1200 Allowance for Credit Losses', '1205 Allowance for Doubtful Accounts', self::FUTURE_REFUSAL));

        // 1200 is no longer the allowance, so it is an ordinary account here.
        $this->postJson('/api/accounting/journals', ['date' => '2026-11-12', 'description' => 'Unmapped', 'lines' => $lines($allowance->id)])
            ->assertCreated();
    }

    public function test_a_no_branch_allowance_entry_cant_be_reversed_into_the_future(): void
    {
        $original = $this->postSimpleJournal('1200', '3050', 70_000, ['branch_id' => null, 'date' => '2026-11-01']);

        $this->postJson("/api/accounting/journals/{$original->id}/reverse", ['date' => '2026-11-12'])
            ->assertStatus(422)
            ->assertJsonPath('errors.date.0', self::REVERSAL_REFUSAL);

        // Its Manila day is what counts: 23:00 on Nov 11 at UTC-1 is Nov 12 in Manila.
        $this->postJson("/api/accounting/journals/{$original->id}/reverse", ['date' => '2026-11-11T23:00:00-01:00'])
            ->assertStatus(422)
            ->assertJsonPath('errors.date.0', self::REVERSAL_REFUSAL);

        $this->assertSame(0, AccountingJournal::query()->where('source', 'reversal')->count());
        $this->assertSame('posted', $original->fresh()->status);

        // Today, the default, is allowed.
        $this->postJson("/api/accounting/journals/{$original->id}/reverse")->assertCreated();
    }

    public function test_before_the_entry_the_refusal_names_the_same_allowed_range(): void
    {
        $original = $this->postSimpleJournal('1200', '3050', 70_000, ['branch_id' => null, 'date' => '2026-11-01']);

        $this->postJson("/api/accounting/journals/{$original->id}/reverse", ['date' => '2026-10-31'])
            ->assertStatus(422)
            ->assertJsonPath('errors.date.0', 'The reversal can\'t be dated before the entry it reverses (Nov 1, 2026). Date it from Nov 1, 2026 to '.self::TODAY.'.');

        $today = $this->postSimpleJournal('1200', '3050', 70_000, ['branch_id' => null, 'date' => '2026-11-11']);

        $this->postJson("/api/accounting/journals/{$today->id}/reverse", ['date' => '2026-11-12'])
            ->assertStatus(422)
            ->assertJsonPath('errors.date.0', 'This entry has no branch and a line on 1200 Allowance for Credit Losses, so its reversal can\'t be dated after today ('.self::TODAY.'). Date it '.self::TODAY.'.');
    }

    public function test_an_entry_already_posted_with_a_future_date_reverses_on_its_own_date(): void
    {
        // Posted before the rule existed: the poster itself never refused it.
        $original = $this->postSimpleJournal('1200', '3050', 70_000, ['branch_id' => null, 'date' => '2026-12-01']);

        // Today (the default), a day before it and a day after it all get the
        // one message, which names the one day that satisfies both rules.
        $this->postJson("/api/accounting/journals/{$original->id}/reverse")
            ->assertStatus(422)
            ->assertJsonPath('errors.date', [self::FUTURE_ENTRY_REVERSAL_REFUSAL]);

        foreach (['2026-11-30', '2026-12-02'] as $date) {
            $this->postJson("/api/accounting/journals/{$original->id}/reverse", ['date' => $date])
                ->assertStatus(422)
                ->assertJsonPath('errors.date', [self::FUTURE_ENTRY_REVERSAL_REFUSAL]);
        }

        $this->assertSame('posted', $original->fresh()->status);

        // The pair cancels out on the entry's own date, so that one is allowed.
        $id = $this->postJson("/api/accounting/journals/{$original->id}/reverse", ['date' => '2026-12-01'])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame('2026-12-01', AccountingJournal::query()->findOrFail($id)->date->toDateString());
        $this->assertSame('reversed', $original->fresh()->status);
    }

    public function test_a_future_no_branch_allowance_draft_saved_before_the_rule_cant_be_posted(): void
    {
        // Saved through the poster, as a draft saved before this rule would be.
        $draft = $this->draftJournal([
            ['account_id' => $this->account('1200'), 'debit' => 70_000, 'credit' => 0],
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 70_000],
        ], ['branch_id' => null, 'date' => '2026-11-12']);

        $this->postJson("/api/accounting/journals/{$draft->id}/post")
            ->assertStatus(422)
            ->assertJsonPath('errors.date', [self::FUTURE_REFUSAL]);

        $this->assertSame('draft', $draft->fresh()->status);

        // Dated today, or given a branch, it posts.
        $this->putJson("/api/accounting/journals/{$draft->id}", $this->entry('2026-11-11'))->assertOk();
        $this->postJson("/api/accounting/journals/{$draft->id}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        $branched = $this->draftJournal([
            ['account_id' => $this->account('1200'), 'debit' => 70_000, 'credit' => 0],
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 70_000],
        ], ['date' => '2026-11-12']);
        $this->postJson("/api/accounting/journals/{$branched->id}/post")->assertOk();
    }

    public function test_posting_still_needs_journals_post(): void
    {
        $draft = $this->draftJournal([
            ['account_id' => $this->account('5030'), 'debit' => 100, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 100],
        ]);

        $this->actingAs($this->userWithNoRole());

        $this->postJson("/api/accounting/journals/{$draft->id}/post")->assertForbidden();
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_a_reversal_may_still_be_future_dated_with_a_branch_or_without_an_allowance_line(): void
    {
        $branched = $this->postSimpleJournal('1200', '3050', 70_000, ['date' => '2026-11-01']);
        $this->postJson("/api/accounting/journals/{$branched->id}/reverse", ['date' => '2026-11-12'])->assertCreated();

        $plain = $this->postSimpleJournal('5030', '1010', 70_000, ['branch_id' => null, 'date' => '2026-11-01']);
        $this->postJson("/api/accounting/journals/{$plain->id}/reverse", ['date' => '2026-11-12'])->assertCreated();
    }

    public function test_a_manual_journal_date_must_be_a_plain_day(): void
    {
        $refusal = 'Enter the entry date as a plain date (year-month-day), with no time or time zone.';

        foreach (['2026-11-10T23:30:00+09:00', '2026-11-10 08:00:00', 'next tuesday', '2026-02-30'] as $date) {
            $this->postJson('/api/accounting/journals', $this->entry($date, '5030', '1010'))
                ->assertStatus(422)
                ->assertJsonPath('errors.date.0', $refusal);
        }

        $this->assertSame(0, AccountingJournal::query()->count());

        $id = $this->postJson('/api/accounting/journals', $this->entry('2026-11-10', '5030', '1010'))->assertCreated()->json('data.id');

        $this->putJson("/api/accounting/journals/{$id}", $this->entry('2026-11-09T23:30:00-08:00', '5030', '1010'))
            ->assertStatus(422)
            ->assertJsonPath('errors.date.0', $refusal);

        $this->assertSame('2026-11-10', AccountingJournal::query()->findOrFail($id)->date->toDateString());

        $this->postJson('/api/accounting/journals', ['description' => 'No date', 'lines' => $this->entry('2026-11-10', '5030', '1010')['lines']])
            ->assertStatus(422)
            ->assertJsonPath('errors.date.0', 'Enter the entry date.');
    }
}

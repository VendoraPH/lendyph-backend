<?php

namespace Tests\Feature\Ledger\Deferral;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\AccountingJournal;
use App\Models\Branch;
use App\Models\Loan;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\PostingRules;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * A restructure of a restructure (Arvin, 2026-11-09): the source S holds its
 * own unrecognised deferral D. J2 uses D first against the principal written
 * off; whatever it doesn't use carries to the new loan N in J1, by part, and
 * is recognised with N's own capitalised amounts as N's principal is
 * collected. S ends at zero, N ends at zero, and 1210 with them.
 *
 * In every test S is a restructure holding ₱7,000.00 interest and ₱3,000.00
 * penalty, one ₱10,000.00 collection in: D = ₱5,833.33 interest and
 * ₱2,500.00 penalty.
 */
class RestructureCarryTest extends TestCase
{
    use DeferralScenarios, PostsJournals, SetupLendyPH;

    private Loan $first;

    private Loan $source;

    private Loan $newBase;

    /** Only the chain of three uses it; released, like the others, before the chart exists. */
    private Loan $thirdBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->first = $this->createReleasedLoan();
        $sourceBase = $this->createReleasedLoan();
        $this->newBase = $this->createReleasedLoan();
        $this->thirdBase = $this->createReleasedLoan();
        $this->source = $this->restructureOf($sourceBase, $this->first, 5_000_000, 700_000, 300_000, 0);
        $this->seedChartOfAccounts();

        $this->release($this->source, $this->first);
        $this->pay($this->source);
        $this->assertSame(['interest' => 583_333, 'penalty' => 250_000], $this->poster()->unrecognisedDeferral($this->source->fresh()));
    }

    public function test_with_nothing_written_off_all_of_the_deferral_carries(): void
    {
        $new = $this->restructureOf($this->newBase, $this->source, 6_000_000, 0, 0, 0);

        $result = $this->release($new, $this->source);

        $this->assertNull($result['write_off']);
        $this->assertCarried($result['release'], 583_333, 250_000);
        $this->assertSame(['interest' => 0, 'penalty' => 0], $this->poster()->unrecognisedDeferral($this->source->fresh()));
        $this->assertSame(['interest' => 583_333, 'penalty' => 250_000], $this->poster()->unrecognisedDeferral($new->fresh()));
        $this->assertSame(833_333, $this->unearnedBalance());

        $this->assertRecognisedToZero($new, 583_333, 250_000);
    }

    public function test_a_write_off_smaller_than_the_deferral_carries_the_rest(): void
    {
        $new = $this->restructureOf($this->newBase, $this->source, 6_000_000, 0, 0, 300_000);

        $result = $this->release($new, $this->source);

        $this->assertSame(300_000, $this->described($result['write_off'], PostingRules::DEFERRAL_WRITTEN_OFF_LINE, 'debit'));
        $this->assertSame(0, $this->sumOn($result['write_off'], '5140', 'debit'));
        $this->assertCarried($result['release'], 373_333, 160_000);
        $this->assertSame(['interest' => 0, 'penalty' => 0], $this->poster()->unrecognisedDeferral($this->source->fresh()));
        $this->assertSame(['interest' => 373_333, 'penalty' => 160_000], $this->poster()->unrecognisedDeferral($new->fresh()));
        $this->assertSame(533_333, $this->unearnedBalance());

        $this->assertRecognisedToZero($new, 373_333, 160_000);
    }

    public function test_a_write_off_at_or_above_the_deferral_carries_nothing(): void
    {
        $new = $this->restructureOf($this->newBase, $this->source, 6_000_000, 0, 0, 900_000);

        $result = $this->release($new, $this->source);

        $this->assertSame(833_333, $this->described($result['write_off'], PostingRules::DEFERRAL_WRITTEN_OFF_LINE, 'debit'));
        $this->assertSame(66_667, $this->sumOn($result['write_off'], '5140', 'debit'));
        $this->assertSame(0, $this->sumOn($result['release'], '1210', 'debit'));
        $this->assertSame(0, $this->sumOn($result['release'], '1210', 'credit'));
        $this->assertSame(['interest' => 0, 'penalty' => 0], $this->poster()->unrecognisedDeferral($this->source->fresh()));
        $this->assertSame(['interest' => 0, 'penalty' => 0], $this->poster()->unrecognisedDeferral($new->fresh()));
        $this->assertSame(0, $this->unearnedBalance());
        $this->assertEveryJournalBalances();
    }

    /** @return array<string, array{0: int}> */
    public static function writeOffs(): array
    {
        return [
            'nothing written off' => [0],
            'less than the deferral written off' => [300_000],
            'more than the deferral written off' => [900_000],
        ];
    }

    /**
     * The builders that write nothing produce exactly the lines posted, carry
     * and write-off included, before the release and after it (the release
     * diff reads them after).
     */
    #[DataProvider('writeOffs')]
    public function test_the_builders_equal_what_is_posted(int $writtenOff): void
    {
        $new = $this->restructureOf($this->newBase, $this->source, 6_000_000, 0, 0, $writtenOff);
        $map = AccountMap::resolve();
        $releaseBefore = $this->builtLines($this->poster()->restructureReleasePosting($new, $map, 'cash'));
        $writeOffBefore = $this->poster()->restructureWriteOffPosting($new, $this->source->fresh(), $map);

        $result = $this->release($new, $this->source);

        $this->assertSame($this->postedLines($result['release']), $releaseBefore, 'J1, built before the release');
        $this->assertSame(
            $this->postedLines($result['release']),
            $this->builtLines($this->poster()->restructureReleasePosting($new->fresh(), $map, 'cash')),
            'J1, built after the release',
        );

        if ($writtenOff === 0) {
            $this->assertNull($writeOffBefore);
            $this->assertNull($this->poster()->restructureWriteOffPosting($new->fresh(), $this->source->fresh(), $map));

            return;
        }

        $this->assertSame($this->postedLines($result['write_off']), $this->builtLines($writeOffBefore), 'J2, built before the release');
        $this->assertSame(
            $this->postedLines($result['write_off']),
            $this->builtLines($this->poster()->restructureWriteOffPosting($new->fresh(), $this->source->fresh(), $map)),
            'J2, built after the release',
        );
    }

    /**
     * L1 → L2 → L3. L2 has its own capitalised ₱6,000.00 / ₱4,000.00 plus
     * L1's carry; L3 carries all of L2's rest. Every capitalised centavo ends
     * recognised into 4010 and 4020, and nothing is left on 1210.
     */
    public function test_a_chain_of_three_restructures_carries_correctly(): void
    {
        $second = $this->restructureOf($this->newBase, $this->source, 5_000_000, 600_000, 400_000, 0);

        $this->assertCarried($this->release($second, $this->source)['release'], 583_333, 250_000);
        $this->assertSame(['interest' => 1_183_333, 'penalty' => 650_000], $this->poster()->unrecognisedDeferral($second->fresh()));

        $this->pay($second);
        $this->pay($second);
        $left = $this->poster()->unrecognisedDeferral($second->fresh());
        $this->assertSame(['interest' => 788_889, 'penalty' => 433_333], $left);

        $third = $this->restructureOf($this->thirdBase, $second->fresh(), 6_000_000, 0, 0, 0);
        $this->assertCarried($this->release($third, $second)['release'], $left['interest'], $left['penalty']);
        $this->assertSame(['interest' => 0, 'penalty' => 0], $this->poster()->unrecognisedDeferral($second->fresh()));
        $this->assertSame($left, $this->poster()->unrecognisedDeferral($third->fresh()));
        $this->assertSame($this->unrecognisedTotal([$this->source, $second, $third]), $this->unearnedBalance());

        $this->payUntilCompleted($third);

        foreach ([$this->source, $second, $third] as $loan) {
            $this->assertSame(['interest' => 0, 'penalty' => 0], $this->poster()->unrecognisedDeferral($loan->fresh()));
        }

        $recognised = array_map(fn (Loan $loan): array => $this->recognisedOn($loan), [$this->source, $second, $third]);
        $this->assertSame(700_000 + 600_000, array_sum(array_column($recognised, 'interest')));
        $this->assertSame(300_000 + 400_000, array_sum(array_column($recognised, 'penalty')));
        $this->assertSame(0, $this->unearnedBalance());
        $this->assertEveryJournalBalances();
    }

    /** A void of a collection that recognised carried deferral reverses exactly that; the next one catches up. */
    public function test_a_void_after_a_carry_reverses_exactly(): void
    {
        $new = $this->restructureOf($this->newBase, $this->source, 6_000_000, 0, 0, 0);
        $this->release($new, $this->source);

        $this->pay($new);
        $second = $this->pay($new);
        $recognised = $this->journalFor($second, 'loan_collection');
        $before = $this->poster()->unrecognisedDeferral($new->fresh());

        $this->patchJson("/api/repayments/{$second->id}/void", ['void_reason' => 'Keyed twice'])->assertOk();

        $reversal = AccountingJournal::findOrFail($recognised->fresh()->reversed_by_journal_id);
        foreach ([PostingRules::DEFERRED_INTEREST_LINE, PostingRules::DEFERRED_PENALTY_LINE] as $marker) {
            $this->assertGreaterThan(0, $this->described($recognised, $marker, 'credit'));
            $this->assertSame($this->described($recognised, $marker, 'credit'), $this->described($reversal, $marker, 'debit'));
        }
        $this->assertSame($this->sumOn($recognised, '1210', 'debit'), $this->sumOn($reversal, '1210', 'credit'));
        $this->assertSame(
            ['interest' => $before['interest'] + $this->described($recognised, PostingRules::DEFERRED_INTEREST_LINE, 'credit'),
                'penalty' => $before['penalty'] + $this->described($recognised, PostingRules::DEFERRED_PENALTY_LINE, 'credit')],
            $this->poster()->unrecognisedDeferral($new->fresh()),
        );

        $this->assertRecognisedToZero($new, 583_333, 250_000);
    }

    /**
     * A partial reduction on S before it is restructured: its share stays off
     * D, J2 writes off less than what is left, and only the rest carries. S
     * ends at zero, N recognises its carry to zero, and the builders equal the
     * lines posted.
     */
    public function test_a_partial_reduction_then_a_write_off_below_what_is_left_ends_at_zero(): void
    {
        // ₱10,000.00 of S's ₱50,000.00: a fifth of D cleared, ₱6,666.66 left.
        $reduction = $this->journalFor($this->applyReduction($this->source, -1_000_000), 'adjustment');
        $this->assertSame(166_667, $this->sumOn($reduction, '1210', 'debit'));
        $left = $this->poster()->unrecognisedDeferral($this->source->fresh());
        $this->assertSame(['interest' => 466_666, 'penalty' => 200_000], $left);

        $new = $this->restructureOf($this->newBase, $this->source, 6_000_000, 0, 0, 300_000);
        $map = AccountMap::resolve();
        $releaseBefore = $this->builtLines($this->poster()->restructureReleasePosting($new, $map, 'cash'));
        $writeOffBefore = $this->builtLines($this->poster()->restructureWriteOffPosting($new, $this->source->fresh(), $map));

        $result = $this->release($new, $this->source);

        $this->assertSame(300_000, $this->described($result['write_off'], PostingRules::DEFERRAL_WRITTEN_OFF_LINE, 'debit'));
        $this->assertSame(0, $this->sumOn($result['write_off'], '5140', 'debit'));
        $carry = PostingRules::restructureCarry($left['interest'], $left['penalty'], 300_000);
        $this->assertSame(366_666, $carry['interest'] + $carry['penalty']);
        $this->assertCarried($result['release'], $carry['interest'], $carry['penalty']);
        $this->assertSame(['interest' => 0, 'penalty' => 0], $this->poster()->unrecognisedDeferral($this->source->fresh()));
        $this->assertSame($carry, $this->poster()->unrecognisedDeferral($new->fresh()));
        $this->assertSame(366_666, $this->unearnedBalance());

        $this->assertSame($this->postedLines($result['release']), $releaseBefore, 'J1, built before the release');
        $this->assertSame(
            $this->postedLines($result['release']),
            $this->builtLines($this->poster()->restructureReleasePosting($new->fresh(), $map, 'cash')),
            'J1, built after the release',
        );
        $this->assertSame($this->postedLines($result['write_off']), $writeOffBefore, 'J2, built before the release');
        $this->assertSame(
            $this->postedLines($result['write_off']),
            $this->builtLines($this->poster()->restructureWriteOffPosting($new->fresh(), $this->source->fresh(), $map)),
            'J2, built after the release',
        );

        $this->assertRecognisedToZero($new, $carry['interest'], $carry['penalty']);
    }

    /**
     * A new loan in another branch than its source is refused before anything
     * posts: J1 and J2 would land in two branches. A member's branch can't
     * change while a loan is collectible, so only an imported or hand-edited
     * row gets here.
     */
    public function test_a_new_loan_in_another_branch_than_its_source_is_refused_and_posts_nothing(): void
    {
        $new = $this->restructureOf($this->newBase, $this->source, 6_000_000, 0, 0, 300_000);
        $new->update(['branch_id' => Branch::factory()->create()->id]);
        $journals = AccountingJournal::count();

        try {
            $this->release($new, $this->source);
            $this->fail('A restructure across two branches was posted.');
        } catch (CannotPostToTheBooksException $e) {
            $this->assertStringContainsString('in a different branch from', $e->getMessage());
            $this->assertStringContainsString('Nothing has been saved.', $e->getMessage());
        }

        $this->assertSame($journals, AccountingJournal::count());
        $this->assertSame(['interest' => 583_333, 'penalty' => 250_000], $this->poster()->unrecognisedDeferral($this->source->fresh()));
    }

    /** J1 moved exactly this much off S and onto N, by part, on 1210. */
    private function assertCarried(AccountingJournal $release, int $interest, int $penalty): void
    {
        $this->assertSame($interest, $this->described($release, PostingRules::DEFERRED_INTEREST_CARRIED_OUT_LINE, 'debit'));
        $this->assertSame($penalty, $this->described($release, PostingRules::DEFERRED_PENALTY_CARRIED_OUT_LINE, 'debit'));
        $this->assertSame($interest, $this->described($release, PostingRules::DEFERRED_INTEREST_CARRIED_IN_LINE, 'credit'));
        $this->assertSame($penalty, $this->described($release, PostingRules::DEFERRED_PENALTY_CARRIED_IN_LINE, 'credit'));
        $this->assertSame($release->total_debit, $release->total_credit);
    }

    /** Collect `$new` to completion: everything it holds is recognised, by part, ending at exactly zero. */
    private function assertRecognisedToZero(Loan $new, int $interest, int $penalty): void
    {
        $this->payUntilCompleted($new);

        $this->assertSame(['interest' => $interest, 'penalty' => $penalty], $this->recognisedOn($new));
        $this->assertSame(['interest' => 0, 'penalty' => 0], $this->poster()->unrecognisedDeferral($new->fresh()));
        $this->assertSame(0, $this->unearnedBalance());
        $this->assertEveryJournalBalances();
    }
}

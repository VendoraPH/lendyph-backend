<?php

namespace Tests\Feature\Savings\Interest;

use App\Models\AccountingJournal;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsProduct;
use App\Models\SavingsTaxRule;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Savings\SavingsLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Interest runs (design §5, §8 "Interest runs"): preview = draft = posted,
 * one live run per product, branch and period, figures re-checked under the
 * account locks at posting, only the latest run reversed, and a credited
 * period closed to back-dated rows.
 *
 * The history: a quarterly product at 3.65% (so the gross is the period's
 * centavo-days ÷ 10,000) with a ₱1,000.00 minimum ADB. Account A opens on
 * Jul 1 with ₱10,000.00 and adds ₱5,000.00 on Aug 15; account B opens on
 * Jul 1 with ₱500.00. For Jul 1 to Sep 30 (92 days) A holds 45 days ×
 * ₱10,000.00 and 47 days × ₱15,000.00 = 115,500,000 centavo-days, so ₱115.50
 * gross and an ADB of ₱12,554.35; B's ADB is ₱500.00, below the minimum.
 */
class InterestRunLifecycleTest extends TestCase
{
    use SavingsHistory, SetupLendyPH;

    private SavingsProduct $product;

    private SavingsAccount $a;

    private SavingsAccount $b;

    private User $maker;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->cooperativeBooks();
        $this->maker = $this->userWith(['savings:view', 'savings:post']);
        $this->checker = $this->userWith(['savings:view', 'savings:approve']);

        $this->onDay('2026-07-01');
        $this->product = $this->regularProduct(['minimum_adb_for_interest' => '1000.00']);
        $this->a = $this->openAccount($this->product, 1_000_000);
        $this->b = $this->openAccount($this->product, 50_000);

        $this->onDay('2026-08-15');
        $this->deposit($this->a, 500_000);

        $this->onDay('2026-10-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function body(string $periodStart = '2026-07-01', array $extra = []): array
    {
        return array_merge(['product_id' => $this->product->id, 'period_start' => $periodStart], $extra);
    }

    /** Create, approve and post one run for the period; returns its id. */
    private function postedRun(string $periodStart = '2026-07-01'): int
    {
        $id = $this->actingAs($this->maker)->postJson('/api/savings/interest-runs', $this->body($periodStart))->assertCreated()->json('data.0.id');
        $this->actingAs($this->checker)->postJson("/api/savings/interest-runs/{$id}/approve")->assertOk();
        $this->actingAs($this->maker)->postJson("/api/savings/interest-runs/{$id}/post")->assertOk();

        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function figures(array $lines): array
    {
        return collect($lines)
            ->map(fn (array $line) => collect($line)->only(['account_id', 'days', 'average_daily_balance', 'rate', 'gross_interest', 'tax_rate', 'withholding_tax', 'net_interest', 'skip_reason'])->all())
            ->sortBy('account_id')
            ->values()
            ->all();
    }

    public function test_the_preview_equals_the_draft_equals_what_posts(): void
    {
        SavingsTaxRule::factory()->create(['holder_type' => SavingsTaxRule::HOLDER_MEMBER, 'rate' => '20.0000', 'effective_from' => '2026-01-01']);

        $preview = $this->actingAs($this->maker)->postJson('/api/savings/interest-runs/preview', $this->body())->assertOk()->json('data');

        $this->assertSame('2026-07-01', $preview['period_start']);
        $this->assertSame('2026-09-30', $preview['period_end']);
        $this->assertCount(1, $preview['runs']);
        $this->assertSame(['id' => $this->branch->id, 'name' => $this->branch->name], $preview['runs'][0]['branch']);
        $this->assertSame(['account_count' => 2, 'total_gross' => '115.50', 'total_tax' => '23.10', 'total_net' => '92.40'], $preview['totals']);

        $lineA = collect($preview['runs'][0]['lines'])->firstWhere('account_id', $this->a->id);
        $this->assertSame(92, $lineA['days']);
        $this->assertSame('12554.35', $lineA['average_daily_balance']);
        $this->assertSame('3.6500', $lineA['rate']);
        $this->assertSame('115.50', $lineA['gross_interest']);
        $this->assertSame('20.0000', $lineA['tax_rate']);
        $this->assertSame('23.10', $lineA['withholding_tax']);
        $this->assertSame('92.40', $lineA['net_interest']);
        $this->assertNull($lineA['skip_reason']);
        $this->assertSame('below_minimum_adb', collect($preview['runs'][0]['lines'])->firstWhere('account_id', $this->b->id)['skip_reason']);
        $this->assertSame(0, SavingsInterestRun::query()->count(), 'A preview writes nothing.');

        $created = $this->actingAs($this->maker)->postJson('/api/savings/interest-runs', $this->body())->assertCreated()->json('data');
        $this->assertCount(1, $created);
        $this->assertSame('draft', $created[0]['status']);
        $this->assertSame(['id' => $this->maker->id, 'name' => $this->maker->full_name ?: $this->maker->username], $created[0]['created_by']);
        $id = $created[0]['id'];

        $draft = $this->actingAs($this->maker)->getJson("/api/savings/interest-runs/{$id}")->assertOk()->json('data');
        $this->assertSame($this->figures($preview['runs'][0]['lines']), $this->figures($draft['lines']));
        $this->assertSame(['115.50', '23.10', '92.40', 2], [$draft['total_gross'], $draft['total_tax'], $draft['total_net'], $draft['account_count']]);

        $this->actingAs($this->checker)->postJson("/api/savings/interest-runs/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approved_by.id', $this->checker->id);
        $posted = $this->actingAs($this->maker)->postJson("/api/savings/interest-runs/{$id}/post")->assertOk()->json('data');

        $this->assertSame('posted', $posted['status']);
        $this->assertSame($this->maker->id, $posted['posted_by']['id']);
        $this->assertSame($this->figures($draft['lines']), $this->figures($posted['lines']));

        $interest = SavingsTransaction::query()->where('interest_run_id', $id)->where('type', 'interest')->sole();
        $tax = SavingsTransaction::query()->where('interest_run_id', $id)->where('type', 'withholding_tax')->sole();
        $this->assertSame([$this->a->id, '115.50', '2026-10-01', '2026-09-30'], [$interest->account_id, $interest->credit, $interest->date->toDateString(), $interest->value_date->toDateString()]);
        $this->assertSame([$this->a->id, '23.10', '2026-09-30'], [$tax->account_id, $tax->debit, $tax->value_date->toDateString()]);

        $this->a->refresh();
        $this->assertSame('15092.40', $this->a->ledger_balance);
        $this->assertSame('2026-09-30', $this->a->interest_credited_through->toDateString());
        $this->assertSame('2026-09-30', $this->b->refresh()->interest_credited_through->toDateString());

        $journal = AccountingJournal::query()->where('postable_type', SavingsInterestRun::class)->where('postable_id', $id)->sole();
        $this->assertSame('savings_interest', $journal->source);
        $this->assertSame($this->branch->id, $journal->branch_id);
        $this->assertSame(
            [['5160', 11_550, 0], ['2050', 0, 9_240], ['2210', 0, 2_310]],
            $journal->lines()->with('account')->orderBy('id')->get()->map(fn ($l) => [$l->account->code, (int) $l->debit, (int) $l->credit])->all(),
        );
    }

    public function test_a_second_live_run_for_the_same_period_is_409(): void
    {
        $this->actingAs($this->maker)->postJson('/api/savings/interest-runs', $this->body())->assertCreated();
        $this->actingAs($this->maker)->postJson('/api/savings/interest-runs', $this->body())->assertStatus(409);

        $this->assertSame(1, SavingsInterestRun::query()->count());
    }

    public function test_figures_that_changed_after_the_draft_are_409_and_nothing_posts(): void
    {
        $id = $this->actingAs($this->maker)->postJson('/api/savings/interest-runs', $this->body())->assertCreated()->json('data.0.id');
        $this->actingAs($this->checker)->postJson("/api/savings/interest-runs/{$id}/approve")->assertOk();

        // Back-dated to yesterday, Sep 30: the last day of the period moves.
        $this->deposit($this->a, 100_000, ['date' => '2026-09-30']);

        $this->actingAs($this->maker)->postJson("/api/savings/interest-runs/{$id}/post")
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'changed'));

        $this->assertSame('approved', SavingsInterestRun::query()->findOrFail($id)->status);
        $this->assertSame(0, SavingsTransaction::query()->where('interest_run_id', $id)->count());
        $this->assertNull($this->a->refresh()->interest_credited_through);
        $this->assertSame(0, AccountingJournal::query()->where('postable_type', SavingsInterestRun::class)->count());
    }

    public function test_posting_twice_is_409_and_posts_one_journal(): void
    {
        $id = $this->postedRun();

        $this->actingAs($this->maker)->postJson("/api/savings/interest-runs/{$id}/post")->assertStatus(409);

        $this->assertSame(1, SavingsTransaction::query()->where('interest_run_id', $id)->where('type', 'interest')->count());
        $this->assertSame(1, AccountingJournal::query()->where('postable_type', SavingsInterestRun::class)->count());
    }

    public function test_a_draft_can_not_be_posted_before_approval(): void
    {
        $id = $this->actingAs($this->maker)->postJson('/api/savings/interest-runs', $this->body())->assertCreated()->json('data.0.id');

        $this->actingAs($this->maker)->postJson("/api/savings/interest-runs/{$id}/post")->assertStatus(409);
        $this->assertSame(0, SavingsTransaction::query()->where('interest_run_id', $id)->count());
    }

    public function test_only_the_latest_run_is_reversed_then_its_period_runs_again(): void
    {
        $q3 = $this->postedRun('2026-07-01');

        $this->onDay('2027-01-02');
        $q4 = $this->postedRun('2026-10-01');
        $q4Gross = SavingsInterestRun::query()->findOrFail($q4)->total_gross;
        $balance = $this->a->refresh()->ledger_balance;

        $this->actingAs($this->checker)->postJson("/api/savings/interest-runs/{$q3}/reverse", ['reason' => 'Wrong rate'])->assertStatus(422);

        $this->actingAs($this->checker)->postJson("/api/savings/interest-runs/{$q4}/reverse", ['reason' => 'Wrong rate'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed')
            ->assertJsonPath('data.reverse_reason', 'Wrong rate')
            ->assertJsonPath('data.reversed_by.id', $this->checker->id);

        $interest = SavingsTransaction::query()->where('interest_run_id', $q4)->where('type', 'interest')->sole();
        $reversal = SavingsTransaction::query()->where('reverses_transaction_id', $interest->id)->sole();
        $this->assertSame([$interest->credit, '2026-12-31', '2027-01-02'], [$reversal->debit, $reversal->value_date->toDateString(), $reversal->date->toDateString()]);
        $this->assertSame(
            number_format((float) $balance - (float) $q4Gross, 2, '.', ''),
            $this->a->refresh()->ledger_balance,
        );
        $this->assertSame('2026-09-30', $this->a->interest_credited_through->toDateString());
        $this->assertSame('reversed', AccountingJournal::query()->where('postable_type', SavingsInterestRun::class)->where('postable_id', $q4)->value('status'));

        $again = $this->actingAs($this->maker)->postJson('/api/savings/interest-runs', $this->body('2026-10-01'))->assertCreated()->json('data.0');
        $this->assertNotSame($q4, $again['id']);
        $this->assertSame($q4Gross, $again['total_gross']);
    }

    public function test_a_back_dated_row_into_a_credited_period_is_422(): void
    {
        $this->postedRun();

        try {
            DB::transaction(fn () => app(SavingsLedger::class)->credit($this->a, 'deposit', 10_000, ['method' => 'cash', 'value_date' => '2026-09-30']));
            $this->fail('The back-dated deposit was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('value_date', $e->errors());
        }

        $this->assertSame(0, SavingsTransaction::query()->where('account_id', $this->a->id)->where('type', 'deposit')->where('value_date', '2026-09-30')->count());
    }

    public function test_the_period_must_have_ended_be_aligned_and_follow_the_last_posted_one(): void
    {
        $this->actingAs($this->maker)->postJson('/api/savings/interest-runs/preview', $this->body('2026-10-01'))
            ->assertStatus(422)->assertJsonValidationErrors('period_start');
        $this->actingAs($this->maker)->postJson('/api/savings/interest-runs', $this->body('2026-08-01'))
            ->assertStatus(422)->assertJsonValidationErrors('period_start');
        // A draft for Q3 that isn't posted blocks Q4.
        $q3 = $this->actingAs($this->maker)->postJson('/api/savings/interest-runs', $this->body('2026-07-01'))->assertCreated()->json('data.0.id');
        $this->onDay('2027-01-02');
        $this->actingAs($this->maker)->postJson('/api/savings/interest-runs', $this->body('2026-10-01'))
            ->assertStatus(422)->assertJsonValidationErrors('period_start');

        // Once Q3 is posted, the next period is Q4: skipping to Q1 is refused.
        $this->actingAs($this->checker)->postJson("/api/savings/interest-runs/{$q3}/approve")->assertOk();
        $this->actingAs($this->maker)->postJson("/api/savings/interest-runs/{$q3}/post")->assertOk();
        $this->onDay('2027-04-02');
        $this->actingAs($this->maker)->postJson('/api/savings/interest-runs', $this->body('2027-01-01'))
            ->assertStatus(422)
            ->assertJsonPath('errors.period_start.0', fn (string $message) => str_contains($message, 'Oct 1, 2026'));

        $this->assertSame(1, SavingsInterestRun::query()->count());
    }

    public function test_the_maker_can_not_decide_and_a_rejection_frees_the_period(): void
    {
        $id = $this->actingAs($this->maker)->postJson('/api/savings/interest-runs', $this->body())->assertCreated()->json('data.0.id');
        $both = $this->userWith(['savings:view', 'savings:post', 'savings:approve']);
        $this->actingAs($both)->postJson('/api/savings/interest-runs', $this->body())->assertStatus(409);

        $this->actingAs($this->checker)->postJson("/api/savings/interest-runs/{$id}/reject", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($this->checker)->postJson("/api/savings/interest-runs/{$id}/reject", ['reason' => 'Rate not confirmed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reject_reason', 'Rate not confirmed')
            ->assertJsonPath('data.rejected_by.id', $this->checker->id);
        $this->actingAs($this->checker)->postJson("/api/savings/interest-runs/{$id}/approve")->assertStatus(409);

        $mine = $this->actingAs($both)->postJson('/api/savings/interest-runs', $this->body())->assertCreated()->json('data.0.id');
        $this->actingAs($both)->postJson("/api/savings/interest-runs/{$mine}/approve")->assertForbidden();
        $this->actingAs($both)->postJson("/api/savings/interest-runs/{$mine}/reject", ['reason' => 'Mine'])->assertForbidden();
        $this->assertSame('draft', SavingsInterestRun::query()->findOrFail($mine)->status);
    }

    public function test_lines_that_earn_nothing_say_why_and_a_run_with_no_interest_posts_no_journal(): void
    {
        $this->onDay('2026-07-01');
        $quiet = $this->regularProduct(['minimum_adb_for_interest' => '0.00']);
        $empty = $this->openAccount($quiet);
        $credited = $this->openAccount($quiet, 10_000);
        $credited->forceFill(['interest_credited_through' => '2026-09-30'])->save();
        $closed = $this->openAccount($quiet, 0, null, ['status' => SavingsAccount::STATUS_CLOSED, 'closed_on' => '2026-08-01', 'open_key' => null]);
        $noRate = $this->regularProduct([], null);
        $this->openAccount($noRate, 100_000);
        $this->onDay('2026-10-01');

        $lines = collect($this->actingAs($this->maker)->postJson('/api/savings/interest-runs/preview', ['product_id' => $quiet->id, 'period_start' => '2026-07-01'])
            ->assertOk()->json('data.runs.0.lines'))->keyBy('account_id');
        $this->assertSame('no_balance', $lines[$empty->id]['skip_reason']);
        $this->assertSame('no_days', $lines[$credited->id]['skip_reason']);
        $this->assertSame('closed', $lines[$closed->id]['skip_reason']);
        $this->assertSame('0.00', $lines[$closed->id]['gross_interest']);

        $this->assertSame('no_rate', $this->actingAs($this->maker)->postJson('/api/savings/interest-runs/preview', ['product_id' => $noRate->id, 'period_start' => '2026-07-01'])
            ->assertOk()->json('data.runs.0.lines.0.skip_reason'));

        $id = $this->actingAs($this->maker)->postJson('/api/savings/interest-runs', ['product_id' => $quiet->id, 'period_start' => '2026-07-01'])->assertCreated()->json('data.0.id');
        $this->actingAs($this->checker)->postJson("/api/savings/interest-runs/{$id}/approve")->assertOk();
        $this->actingAs($this->maker)->postJson("/api/savings/interest-runs/{$id}/post")->assertOk()->assertJsonPath('data.total_gross', '0.00');

        $this->assertSame(0, SavingsTransaction::query()->where('interest_run_id', $id)->count());
        $this->assertSame(0, AccountingJournal::query()->where('postable_type', SavingsInterestRun::class)->where('postable_id', $id)->count());
        $this->assertSame('2026-09-30', $empty->refresh()->interest_credited_through->toDateString());
        $this->assertSame(SavingsAccount::STATUS_CLOSED, $closed->refresh()->status);
        $this->assertNull($closed->interest_credited_through);
    }

    public function test_a_time_deposit_product_has_no_interest_runs(): void
    {
        $td = SavingsProduct::factory()->timeDeposit()->create();

        $this->actingAs($this->maker)->postJson('/api/savings/interest-runs/preview', ['product_id' => $td->id, 'period_start' => '2026-07-01'])
            ->assertStatus(422)->assertJsonValidationErrors('product_id');
    }

    public function test_another_branchs_runs_are_hidden(): void
    {
        $far = Branch::factory()->create();
        $this->onDay('2026-07-01');
        $this->openAccount($this->product, 1_000_000, $far);
        $this->onDay('2026-10-01');

        $runs = $this->postJson('/api/savings/interest-runs', $this->body())->assertCreated()->json('data');
        $this->assertCount(2, $runs, 'super_admin sees every branch: one draft each.');
        $farRun = collect($runs)->firstWhere('branch.id', $far->id)['id'];

        $this->actingAs($this->maker)->getJson('/api/savings/interest-runs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
        $this->actingAs($this->maker)->getJson("/api/savings/interest-runs/{$farRun}")->assertNotFound();
        $this->actingAs($this->checker)->postJson("/api/savings/interest-runs/{$farRun}/approve")->assertNotFound();
        $this->actingAs($this->checker)->postJson("/api/savings/interest-runs/{$farRun}/reject", ['reason' => 'x'])->assertNotFound();
        $this->actingAs($this->maker)->postJson("/api/savings/interest-runs/{$farRun}/post")->assertNotFound();
        $this->actingAs($this->checker)->postJson("/api/savings/interest-runs/{$farRun}/reverse", ['reason' => 'x'])->assertNotFound();
        $this->actingAs($this->maker)->postJson('/api/savings/interest-runs/preview', $this->body('2026-07-01', ['branch_ids' => [$far->id]]))
            ->assertStatus(422)->assertJsonValidationErrors('branch_ids.0');
    }
}

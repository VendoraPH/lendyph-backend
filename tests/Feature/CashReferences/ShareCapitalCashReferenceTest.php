<?php

namespace Tests\Feature\CashReferences;

use App\Models\Borrower;
use App\Models\Role;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\CashReferences;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 2, decision 5: a cash share capital entry gets
 * `CSH-[id]-[year]-[0001]` when it saves, on the ledger, a member's pledge
 * entry and each row of a bulk batch.
 *
 * - `[id]` is the primary super admin (the lowest-id active super_admin),
 *   padded to at least 4 digits.
 * - `[year]` is the entry date's year in Manila, and each year starts again
 *   at 0001.
 * - A typed reference is ignored for cash; other methods keep theirs and take
 *   no number. Auto-credit stays unnumbered.
 * - The preview answers the next number for a date (today by default) and
 *   reserves nothing.
 */
class ShareCapitalCashReferenceTest extends TestCase
{
    use SetupLendyPH;

    private Borrower $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'Asia/Manila'));
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_cash_ledger_entry_gets_the_next_number_of_its_series(): void
    {
        $owner = $this->owner();

        $this->ledgerEntry()->assertCreated()->assertJsonPath('data.reference_number', "CSH-{$owner}-2026-0001");
        $this->ledgerEntry()->assertCreated()->assertJsonPath('data.reference_number', "CSH-{$owner}-2026-0002");

        $this->assertSame(["CSH-{$owner}-2026-0001", "CSH-{$owner}-2026-0002"], ShareCapitalLedger::query()->orderBy('id')->pluck('reference_number')->all());
    }

    public function test_a_members_pledge_entry_is_numbered_the_same_way(): void
    {
        $this->postJson("/api/pledges/{$this->member->shareCapitalPledge->id}/entries", ['amount' => 250, 'type' => 'credit', 'date' => '2026-11-24'])
            ->assertCreated()
            ->assertJsonPath('data.method', 'cash')
            ->assertJsonPath('data.reference_number', "CSH-{$this->owner()}-2026-0001");
    }

    public function test_a_typed_reference_is_ignored_for_cash(): void
    {
        $this->ledgerEntry(['method' => 'cash', 'reference_number' => 'OR-TYPED'])
            ->assertCreated()
            ->assertJsonPath('data.reference_number', "CSH-{$this->owner()}-2026-0001");

        $this->assertFalse(ShareCapitalLedger::query()->where('reference_number', 'OR-TYPED')->exists());
    }

    public function test_another_method_keeps_its_typed_reference_and_takes_no_number(): void
    {
        $this->ledgerEntry(['method' => 'gcash', 'reference_number' => 'GC-778899'])
            ->assertCreated()
            ->assertJsonPath('data.reference_number', 'GC-778899');

        $this->assertSame(0, DB::table('cash_reference_sequences')->count());
        $this->ledgerEntry()->assertJsonPath('data.reference_number', "CSH-{$this->owner()}-2026-0001");
    }

    public function test_each_year_starts_again_at_0001_and_the_entry_date_decides_the_year(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-01 08:00:00', 'Asia/Manila'));
        $owner = $this->owner();

        $this->ledgerEntry(['date' => '2026-12-31'])->assertCreated()->assertJsonPath('data.reference_number', "CSH-{$owner}-2026-0001");
        $this->ledgerEntry(['date' => '2027-01-01'])->assertCreated()->assertJsonPath('data.reference_number', "CSH-{$owner}-2027-0001");
        $this->ledgerEntry(['date' => '2027-01-01'])->assertCreated()->assertJsonPath('data.reference_number', "CSH-{$owner}-2027-0002");
        $this->ledgerEntry(['date' => '2026-12-31'])->assertCreated()->assertJsonPath('data.reference_number', "CSH-{$owner}-2026-0002");
    }

    public function test_the_sequence_is_padded_to_four_digits_and_grows_past_them(): void
    {
        $owner = $this->owner();
        DB::table('cash_reference_sequences')->insert(['prefix' => 'CSH', 'owner_id' => (int) $owner, 'year' => 2026, 'last_number' => 9998]);

        $this->ledgerEntry()->assertJsonPath('data.reference_number', "CSH-{$owner}-2026-9999");
        $this->ledgerEntry()->assertJsonPath('data.reference_number', "CSH-{$owner}-2026-10000");
    }

    public function test_the_id_is_the_lowest_id_active_super_admin_padded_to_at_least_four_digits(): void
    {
        $first = $this->superAdmin();
        $second = $this->superAdmin();
        User::query()->whereKeyNot([$first->id, $second->id])
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'))
            ->update(['status' => 'inactive']);
        $this->actingAs($second);

        $this->ledgerEntry()->assertCreated()->assertJsonPath('data.reference_number', sprintf('CSH-%04d-2026-0001', $first->id));

        // Another id is another series, from 0001.
        $first->update(['status' => 'inactive']);
        $this->ledgerEntry()->assertCreated()->assertJsonPath('data.reference_number', sprintf('CSH-%04d-2026-0001', $second->id));

        $this->assertSame('CSH-12345-2026-0007', CashReferences::format('CSH', 12345, 2026, 7));
        $this->assertSame('TD-0012-2026-10000', CashReferences::format('TD', 12, 2026, 10000));
    }

    public function test_with_no_active_super_admin_a_cash_entry_is_refused_in_words_and_the_preview_has_none(): void
    {
        $clerk = $this->userWith(['share_capital:view', 'share_capital:create']);
        User::query()->whereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'))->update(['status' => 'inactive']);
        $this->actingAs($clerk);

        $this->ledgerEntry()->assertUnprocessable()->assertJsonPath('errors.reference_number.0', CashReferences::NO_OWNER);
        $this->getJson('/api/share-capital/cash-reference/next')->assertOk()->assertJsonPath('data.reference_number', null);
        $this->ledgerEntry(['method' => 'gcash', 'reference_number' => 'GC-1'])->assertCreated();
        $this->assertSame(1, ShareCapitalLedger::query()->count());
    }

    public function test_each_bulk_cash_row_gets_its_own_number_in_row_order(): void
    {
        $members = [$this->member, Borrower::factory()->create(['branch_id' => $this->branch->id]), Borrower::factory()->create(['branch_id' => $this->branch->id])];
        $owner = $this->owner();
        $expected = ["CSH-{$owner}-2026-0001", "CSH-{$owner}-2026-0002", "CSH-{$owner}-2026-0003"];

        $this->postJson('/api/pledges/bulk-entries', $this->bulk($members, ['reference_number' => 'OR-TYPED']))
            ->assertCreated()
            ->assertJsonPath('count', 3)
            ->assertJsonPath('data.processed_count', 3)
            ->assertJsonPath('data.reference_numbers', $expected);

        foreach ($members as $i => $member) {
            $this->assertSame($expected[$i], ShareCapitalLedger::query()->where('borrower_id', $member->id)->value('reference_number'));
        }
    }

    public function test_a_bulk_batch_by_another_method_keeps_its_one_reference_and_takes_no_number(): void
    {
        $members = [$this->member, Borrower::factory()->create(['branch_id' => $this->branch->id])];

        $this->postJson('/api/pledges/bulk-entries', $this->bulk($members, ['method' => 'bank_transfer', 'reference_number' => 'BDO-55']))
            ->assertCreated()
            ->assertJsonPath('data.reference_numbers', ['BDO-55', 'BDO-55']);

        $this->assertSame(['BDO-55'], ShareCapitalLedger::query()->pluck('reference_number')->unique()->values()->all());
        $this->assertSame(0, DB::table('cash_reference_sequences')->count());
    }

    public function test_auto_credit_stays_unnumbered(): void
    {
        $this->member->shareCapitalPledge->update(['auto_credit' => true, 'amount' => 500]);

        $this->postJson('/api/auto-credit/process', ['cycle' => '30', 'method' => 'cash'])->assertCreated();

        $this->assertNull(ShareCapitalLedger::query()->sole()->reference_number);
        $this->assertSame(0, DB::table('cash_reference_sequences')->count());
    }

    public function test_the_preview_answers_the_next_number_and_reserves_nothing(): void
    {
        $owner = $this->owner();

        $this->getJson('/api/share-capital/cash-reference/next')
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['reference_number' => "CSH-{$owner}-2026-0001"]]);
        $this->getJson('/api/share-capital/cash-reference/next')->assertJsonPath('data.reference_number', "CSH-{$owner}-2026-0001");
        $this->assertSame(0, DB::table('cash_reference_sequences')->count());

        $this->ledgerEntry()->assertJsonPath('data.reference_number', "CSH-{$owner}-2026-0001");
        $this->getJson('/api/share-capital/cash-reference/next')->assertJsonPath('data.reference_number', "CSH-{$owner}-2026-0002");
    }

    public function test_the_preview_takes_the_date_the_entry_will_carry(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-01 08:00:00', 'Asia/Manila'));
        $owner = $this->owner();
        $this->ledgerEntry(['date' => '2026-12-31'])->assertCreated()->assertJsonPath('data.reference_number', "CSH-{$owner}-2026-0001");

        $this->getJson('/api/share-capital/cash-reference/next?date=2026-12-31')->assertOk()->assertJsonPath('data.reference_number', "CSH-{$owner}-2026-0002");
        $this->getJson('/api/share-capital/cash-reference/next?date=2027-01-01')->assertOk()->assertJsonPath('data.reference_number', "CSH-{$owner}-2027-0001");
        $this->getJson('/api/share-capital/cash-reference/next')->assertOk()->assertJsonPath('data.reference_number', "CSH-{$owner}-2027-0001");

        foreach (['20261231', '2026-12-32', '12/31/2026'] as $written) {
            $this->getJson('/api/share-capital/cash-reference/next?date='.urlencode($written))
                ->assertUnprocessable()
                ->assertJsonPath('errors.date.0', 'The entry date must be a date written as year-month-day, such as 2026-11-24.');
        }
    }

    public function test_the_preview_needs_the_permission_to_record_an_entry(): void
    {
        $this->actingAs($this->userWith(['share_capital:view']));
        $this->getJson('/api/share-capital/cash-reference/next')->assertForbidden();

        $this->actingAs($this->userWith(['share_capital:create']));
        $this->getJson('/api/share-capital/cash-reference/next')->assertOk();
    }

    /** The primary super admin's id as the number shows it. */
    private function owner(): string
    {
        $id = User::query()->where('status', 'active')
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'))
            ->min('id');

        return str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    private function ledgerEntry(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $this->member->id,
            'date' => '2026-11-24',
            'description' => 'Contribution',
            'type' => 'credit',
            'amount' => 1000,
            ...$overrides,
        ]);
    }

    /**
     * @param  list<Borrower>  $members
     * @return array<string, mixed>
     */
    private function bulk(array $members, array $overrides = []): array
    {
        return [
            'entries' => array_map(fn (Borrower $member) => ['pledge_id' => $member->shareCapitalPledge->id, 'amount' => 100, 'type' => 'credit', 'date' => '2026-11-24'], $members),
            ...$overrides,
        ];
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $user->assignRole('super_admin');

        return $user;
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'csh_probe_'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $user->branches()->sync([$this->branch->id]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}

<?php

namespace Tests\Feature\Cda\Members;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\PortalCode;
use App\Models\PortalUser;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Cda\MembershipDetails;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The member page's Membership section (finish run round 2, decision 15):
 * PATCH /borrowers/{borrower}/membership changes the membership date, member
 * type and membership number, with `borrowers:update` and the member's branch.
 * The number follows the members import's rules; a number another member holds
 * is refused in words naming the number only. Only a member has these details,
 * a former member's can be corrected but never dated after the membership
 * ended, and one audit row holds the old and new values. A new sign-in number
 * closes the member's open portal code; their sessions carry on.
 */
class MembershipDetailsTest extends TestCase
{
    use PortalTestHelpers, SetupLendyPH;

    private const FORMAT = 'The membership date must be a date written as year-month-day, such as 2026-01-15.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-11 00:30:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // The change and its audit row ----------------------------------------

    public function test_the_details_change_with_one_audit_row_holding_the_old_and_new_values(): void
    {
        $member = $this->member();
        $before = (int) AuditLog::query()->max('id');

        $this->patchJson($this->url($member), [
            'membership_date' => '2018-07-15',
            'member_type' => 'associate',
            'membership_number' => 'mem 0042',
        ])->assertOk()
            ->assertJsonPath('data.id', $member->id)
            ->assertJsonPath('data.membership_date', '2018-07-15')
            ->assertJsonPath('data.member_type', 'associate')
            ->assertJsonPath('data.membership_number', 'MEM0042');

        $fresh = $member->fresh();
        $this->assertSame('2018-07-15', $fresh->membership_date->toDateString());
        $this->assertSame('associate', $fresh->member_type);
        $this->assertSame('MEM0042', $fresh->membership_number);

        $row = AuditLog::query()->where('id', '>', $before)->sole();
        $this->assertSame('updated', $row->action);
        $this->assertSame((new Borrower)->getMorphClass(), $row->auditable_type);
        $this->assertSame($member->id, (int) $row->auditable_id);
        $this->assertSame($this->admin->id, (int) $row->user_id);
        $this->assertSameValues(
            ['membership_date' => '2019-03-01', 'member_type' => 'regular', 'membership_number' => $member->borrower_code],
            $row->old_values,
        );
        $this->assertSameValues(
            ['membership_date' => '2018-07-15', 'member_type' => 'associate', 'membership_number' => 'MEM0042'],
            $row->new_values,
        );
        $this->assertSame('Membership details changed: membership date, member type and membership number.', $row->description);
    }

    public function test_only_what_changed_is_audited_and_a_save_that_changes_nothing_writes_nothing(): void
    {
        $member = $this->member();
        $before = (int) AuditLog::query()->max('id');

        $this->patchJson($this->url($member), [
            'membership_date' => '2019-03-01',
            'member_type' => 'associate',
            'membership_number' => $member->borrower_code,
        ])->assertOk();

        $row = AuditLog::query()->where('id', '>', $before)->sole();
        $this->assertSame(['member_type' => 'regular'], $row->old_values);
        $this->assertSame(['member_type' => 'associate'], $row->new_values);
        $this->assertSame('Membership details changed: member type.', $row->description);

        // The same values again, the number as typed in lower case.
        $after = (int) AuditLog::query()->max('id');

        $this->patchJson($this->url($member), [
            'membership_date' => '2019-03-01',
            'member_type' => 'associate',
            'membership_number' => strtolower($member->borrower_code),
        ])->assertOk()->assertJsonPath('data.membership_number', $member->borrower_code);

        $this->assertSame($after, (int) AuditLog::query()->max('id'), 'a save that changes nothing writes nothing');
    }

    public function test_only_the_membership_fields_change_through_this_route(): void
    {
        $member = $this->member();

        $this->patchJson($this->url($member), ['member_type' => 'associate', 'first_name' => 'Changed', 'status' => 'inactive', 'branch_id' => 999])
            ->assertOk();

        $fresh = $member->fresh();
        $this->assertSame('associate', $fresh->member_type);
        $this->assertSame($member->first_name, $fresh->first_name);
        $this->assertSame('active', $fresh->status);
        $this->assertSame($this->branch->id, (int) $fresh->branch_id);

        $this->patchJson($this->url($member), ['first_name' => 'Changed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['membership' => 'Send the membership date, member type or membership number to change.']);
    }

    public function test_the_first_membership_date_brings_the_member_code_and_the_audit_row_says_so(): void
    {
        $member = $this->member(['membership_date' => null]);
        $this->assertNull($member->fresh()->membership_number);
        $before = (int) AuditLog::query()->max('id');

        $this->patchJson($this->url($member), ['membership_date' => '2020-01-15'])
            ->assertOk()
            ->assertJsonPath('data.membership_number', $member->borrower_code);

        $row = AuditLog::query()->where('id', '>', $before)->sole();
        $this->assertSameValues(['membership_date' => null, 'membership_number' => null], $row->old_values);
        $this->assertSameValues(['membership_date' => '2020-01-15', 'membership_number' => $member->borrower_code], $row->new_values);
        $this->assertSame('Membership details changed: membership date and membership number.', $row->description);
    }

    // Permission and branch ------------------------------------------------

    public function test_it_needs_borrowers_update(): void
    {
        $member = $this->member();
        $this->actingAs($this->staffIn([$this->branch], ['borrowers:view']));

        $this->patchJson($this->url($member), ['member_type' => 'associate'])->assertForbidden();

        $this->assertSame('regular', $member->fresh()->member_type);
    }

    public function test_a_branch_user_edits_their_own_branch_and_another_branch_answers_404(): void
    {
        $second = Branch::factory()->create(['name' => 'Second Branch', 'code' => 'SEC']);
        $theirs = $this->member();
        $other = $this->member(['branch_id' => $second->id]);
        $this->actingAs($this->staffIn([$this->branch], ['borrowers:view', 'borrowers:update']));

        $this->patchJson($this->url($theirs), ['member_type' => 'associate'])->assertOk();
        $this->patchJson($this->url($other), ['member_type' => 'associate'])->assertNotFound();

        $this->assertSame('associate', $theirs->fresh()->member_type);
        $this->assertSame('regular', $other->fresh()->member_type);
    }

    public function test_the_callers_reach_is_read_again_under_the_lock(): void
    {
        $member = $this->member();
        $caller = $this->staffIn([$this->branch], ['borrowers:view', 'borrowers:update']);
        // The member moved to a branch this caller can't see after the route bound them.
        $third = Branch::factory()->create(['name' => 'Third Branch', 'code' => 'THR']);
        Borrower::query()->whereKey($member->id)->update(['branch_id' => $third->id]);

        try {
            app(MembershipDetails::class)->change($member, ['member_type' => 'associate'], $caller);
            $this->fail('A member outside the caller\'s branches was changed.');
        } catch (ModelNotFoundException) {
            // 404, as for a member the caller never saw.
        }

        $this->assertSame('regular', $member->fresh()->member_type);
    }

    // The membership date ---------------------------------------------------

    /** @return array<string, array{mixed, string}> */
    public static function refusedDates(): array
    {
        return [
            'written with slashes' => ['2019/03/01', self::FORMAT],
            'bare digits' => ['20190301', self::FORMAT],
            'a number' => [20190301, self::FORMAT],
            'a day that does not exist' => ['2019-02-30', self::FORMAT],
            'after today' => ['2026-11-12', 'The membership date can\'t be in the future.'],
            'blank' => [null, 'Enter the membership date. It can be changed but not cleared.'],
        ];
    }

    #[DataProvider('refusedDates')]
    public function test_the_membership_date_is_year_month_day_and_not_after_today(mixed $date, string $words): void
    {
        $member = $this->member();

        $this->patchJson($this->url($member), ['membership_date' => $date])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['membership_date' => $words])
            ->assertJsonCount(1, 'errors.membership_date');

        $this->assertSame('2019-03-01', $member->fresh()->membership_date->toDateString());
    }

    public function test_today_is_a_membership_date(): void
    {
        $member = $this->member();

        $this->patchJson($this->url($member), ['membership_date' => '2026-11-11'])
            ->assertOk()
            ->assertJsonPath('data.membership_date', '2026-11-11');
    }

    // The member type -------------------------------------------------------

    /** @return array<string, array{mixed}> */
    public static function refusedTypes(): array
    {
        return [
            'not a member type' => ['honorary'],
            'its label rather than its value' => ['Regular'],
            'blank' => [null],
        ];
    }

    #[DataProvider('refusedTypes')]
    public function test_the_member_type_is_regular_or_associate(mixed $type): void
    {
        $member = $this->member();

        $this->patchJson($this->url($member), ['member_type' => $type])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['member_type' => 'Choose Regular or Associate for the member type.']);

        $this->assertSame('regular', $member->fresh()->member_type);
    }

    // The membership number -------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function refusedNumbers(): array
    {
        $code = 'The membership number can\'t start with BRW: member codes start that way, and the app gives them out itself. Leave it blank to use the member code.';
        $noDigit = 'The membership number needs at least one digit, so a placeholder such as N/A isn\'t taken for a number.';
        $zeros = 'The membership number can\'t be only zeros, so a placeholder such as 0 isn\'t taken for a number.';

        return [
            'another member\'s code' => ['BRW-000999', $code],
            'a member code look-alike' => ['brw000123', $code],
            'no digit' => ['N/A', $noDigit],
            'only zeros' => ['000', $zeros],
            'zeros and punctuation' => ['00-00', $zeros],
            'longer than the column' => [str_repeat('7', 31), 'The membership number can be at most 30 characters.'],
            'a character beyond the keyboard' => ['NUM-Ñ1', 'The membership number can hold only plain letters, numbers and punctuation.'],
        ];
    }

    #[DataProvider('refusedNumbers')]
    public function test_the_membership_number_follows_the_import_rules(string $number, string $words): void
    {
        $member = $this->member();

        $this->patchJson($this->url($member), ['membership_number' => $number])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['membership_number' => $words])
            ->assertJsonCount(1, 'errors.membership_number');

        $this->assertSame($member->borrower_code, $member->fresh()->membership_number);
    }

    public function test_a_number_is_stored_in_capitals_without_spaces_and_thirty_characters_fit(): void
    {
        $member = $this->member();

        $this->patchJson($this->url($member), ['membership_number' => ' bc 0007 '])
            ->assertOk()
            ->assertJsonPath('data.membership_number', 'BC0007');

        $this->patchJson($this->url($member), ['membership_number' => str_repeat('7', 30)])
            ->assertOk()
            ->assertJsonPath('data.membership_number', str_repeat('7', 30));
    }

    public function test_a_number_another_member_holds_is_refused_naming_the_number_only(): void
    {
        $this->member(['membership_number' => 'MEM-0042']);
        $member = $this->member();

        $this->patchJson($this->url($member), ['membership_number' => 'mem-0042'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['membership_number' => 'Membership number MEM-0042 already belongs to another member. Each member\'s number is their own.'])
            ->assertJsonCount(1, 'errors.membership_number');

        $this->assertSame($member->borrower_code, $member->fresh()->membership_number);
    }

    public function test_a_number_taken_at_the_same_moment_is_refused_in_the_same_words(): void
    {
        $member = $this->member();
        $this->member(['membership_number' => 'MEM-0042']);

        try {
            app(MembershipDetails::class)->change($member, ['membership_number' => 'MEM-0042'], $this->admin);
            $this->fail('A number another member holds was saved.');
        } catch (ValidationException $e) {
            $this->assertSame([MembershipDetails::numberTaken('MEM-0042')], $e->errors()['membership_number']);
        }

        $this->assertSame($member->borrower_code, $member->fresh()->membership_number);
    }

    public function test_the_member_keeps_their_own_number_and_blank_sets_it_back_to_their_code(): void
    {
        // A number from before these rules is theirs to keep, unchanged.
        $member = $this->member(['membership_number' => 'X']);

        $this->patchJson($this->url($member), ['membership_number' => 'x', 'member_type' => 'associate'])
            ->assertOk()
            ->assertJsonPath('data.membership_number', 'X')
            ->assertJsonPath('data.member_type', 'associate');

        $this->patchJson($this->url($member), ['membership_number' => ''])
            ->assertOk()
            ->assertJsonPath('data.membership_number', $member->borrower_code);

        // Their own code, typed, is theirs too.
        $this->patchJson($this->url($member), ['membership_number' => 'MEM-1'])->assertOk();
        $this->patchJson($this->url($member), ['membership_number' => $member->borrower_code])
            ->assertOk()
            ->assertJsonPath('data.membership_number', $member->borrower_code);
    }

    // The portal sign-in --------------------------------------------------------

    public function test_a_new_number_closes_the_open_portal_code_and_the_members_session_carries_on(): void
    {
        $this->enablePortal();
        $portalUser = $this->portalMember(['branch_id' => $this->branch->id]);
        $member = $portalUser->borrower;
        $token = $this->portalToken($portalUser);
        $code = $this->openCode($portalUser);

        // A change that keeps the sign-in number leaves the code open.
        $this->patchJson($this->url($member), ['member_type' => 'associate'])->assertOk();
        $this->assertSame(PortalCode::STATUS_OPEN, $code->fresh()->status);

        $before = (int) AuditLog::query()->max('id');

        $this->patchJson($this->url($member), ['membership_number' => 'MEM-0042'])->assertOk();

        // Its slip showed the old number, which may later go to another member.
        $closed = $code->fresh();
        $this->assertSame(PortalCode::STATUS_SUPERSEDED, $closed->status);
        $this->assertNull($closed->live_key);

        $row = AuditLog::query()->where('id', '>', $before)->sole();
        $this->assertSame('Membership details changed: membership number; open portal codes closed.', $row->description);

        // The member's session carries on, under the new number.
        $this->portalJson('GET', '/api/portal/membership', [], $token)
            ->assertOk()
            ->assertJsonPath('data.member_number', 'MEM-0042');
        $this->assertSame(PortalUser::STATUS_ACTIVE, $portalUser->fresh()->status);
    }

    public function test_a_new_number_with_no_open_portal_code_says_nothing_of_codes(): void
    {
        // No portal account.
        $member = $this->member();
        $before = (int) AuditLog::query()->max('id');

        $this->patchJson($this->url($member), ['membership_number' => 'MEM-7'])->assertOk();

        $this->assertSame('Membership details changed: membership number.', AuditLog::query()->where('id', '>', $before)->sole()->description);

        // A portal account whose code was already used.
        $this->enablePortal();
        $portalUser = $this->portalMember(['branch_id' => $this->branch->id]);
        $used = $this->openCode($portalUser, ['status' => PortalCode::STATUS_USED, 'live_key' => null, 'used_at' => now()]);
        $before = (int) AuditLog::query()->max('id');

        $this->patchJson($this->url($portalUser->borrower), ['membership_number' => 'MEM-8'])->assertOk();

        $this->assertSame('Membership details changed: membership number.', AuditLog::query()->where('id', '>', $before)->sole()->description);
        $this->assertSame(PortalCode::STATUS_USED, $used->fresh()->status);
    }

    // Who has membership details ---------------------------------------------

    public function test_a_registration_not_yet_a_member_has_no_membership_details(): void
    {
        foreach (['pending', 'rejected'] as $status) {
            $person = $this->member(['status' => $status, 'membership_date' => null]);

            $this->patchJson($this->url($person), ['member_type' => 'associate'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['status' => MembershipDetails::NOT_A_MEMBER]);

            $this->assertSame('regular', $person->fresh()->member_type, $status);
        }
    }

    /** @return array<string, array{string}> */
    public static function formerMembers(): array
    {
        return ['withdrawn' => ['withdrawn'], 'deceased' => ['deceased']];
    }

    #[DataProvider('formerMembers')]
    public function test_a_former_members_details_are_corrected_but_never_dated_after_the_end(string $status): void
    {
        $member = $this->member([
            'status' => $status,
            'membership_date' => null,
            'membership_ended_on' => '2026-10-01',
            'membership_end_reason' => 'Moved abroad',
        ]);

        $this->patchJson($this->url($member), ['membership_date' => '2026-10-02'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['membership_date' => 'The membership date can\'t be after the day the membership ended (Oct 1, 2026).']);
        $this->assertNull($member->fresh()->membership_date);

        $this->patchJson($this->url($member), ['membership_date' => '2026-10-01', 'member_type' => 'associate', 'membership_number' => 'OLD-77'])
            ->assertOk()
            ->assertJsonPath('data.status', $status)
            ->assertJsonPath('data.membership_date', '2026-10-01')
            ->assertJsonPath('data.member_type', 'associate')
            ->assertJsonPath('data.membership_number', 'OLD-77')
            ->assertJsonPath('data.membership_ended_on', '2026-10-01');
    }

    // Helpers ------------------------------------------------------------------

    /**
     * The same fields and values, in any order: MySQL keeps a JSON object's
     * keys sorted its own way (shorter first).
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>|null  $actual
     */
    private function assertSameValues(array $expected, ?array $actual): void
    {
        ksort($expected);
        $actual ??= [];
        ksort($actual);

        $this->assertSame($expected, $actual);
    }

    /**
     * An activation or reset code as CodeDesk issues one (open unless stated).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function openCode(PortalUser $portalUser, array $attributes = []): PortalCode
    {
        return PortalCode::query()->create([
            'portal_user_id' => $portalUser->getKey(),
            'kind' => PortalCode::KIND_RESET,
            'code_hash' => Hash::make('ABCD2345EFGH'),
            'expires_at' => now()->addDay(),
            'status' => PortalCode::STATUS_OPEN,
            'live_key' => $portalUser->getKey(),
            'identity_checked_as' => 'umid',
            'issued_by' => $this->admin->getKey(),
            ...$attributes,
        ]);
    }

    private function url(Borrower $member): string
    {
        return "/api/borrowers/{$member->id}/membership";
    }

    /** @param  array<string, mixed>  $attributes */
    private function member(array $attributes = []): Borrower
    {
        return AuditLogService::withoutModelAuditing(fn () => Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'membership_date' => '2019-03-01',
            ...$attributes,
        ]));
    }

    /**
     * @param  list<Branch>  $branches
     * @param  list<string>  $permissions
     */
    private function staffIn(array $branches, array $permissions): User
    {
        $user = User::factory()->inBranches(...$branches)->create();
        $user->givePermissionTo($permissions);

        return $user;
    }
}

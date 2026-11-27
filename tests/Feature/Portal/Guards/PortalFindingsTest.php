<?php

namespace Tests\Feature\Portal\Guards;

use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Collateral;
use App\Models\CoMaker;
use App\Models\Document;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\LoanProduct;
use App\Models\PortalUser;
use App\Models\Repayment;
use App\Models\ShareCapitalPledge;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\SignedFileLink;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The portal design's §0 findings (portal-v1-design.md): each is a way a
 * second authenticatable, the member's PortalUser, would have been taken for
 * a staff user by code that was written when User was the only one.
 */
class PortalFindingsTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private function portalUser(array $attributes = []): PortalUser
    {
        return PortalUser::factory()->create($attributes);
    }

    private function portalToken(PortalUser $portalUser): string
    {
        return $portalUser->createToken('portal', [PortalUser::ABILITY], now()->addHour())->plainTextToken;
    }

    /** Finding 1: Sanctum's default guard accepted a token of any model. */
    public function test_a_portal_token_is_refused_on_a_staff_route(): void
    {
        $token = $this->portalToken($this->portalUser());

        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
    }

    /**
     * Finding 2: the optional-auth middleware took a portal token as a staff
     * operator, who skips the registration limiter. It must be anonymous.
     */
    public function test_a_portal_token_on_public_registration_is_anonymous_and_throttled(): void
    {
        $token = $this->portalToken($this->portalUser());

        $statuses = [];
        for ($i = 0; $i < 9; $i++) {
            Auth::forgetGuards();
            $statuses[] = $this->withToken($token)->postJson('/api/borrowers', [])->status();
        }

        $this->assertSame(422, $statuses[0], 'An anonymous applicant with an empty form is answered 422.');
        $this->assertSame(429, $statuses[8], 'The ninth anonymous submission in ten minutes is throttled.');
    }

    /** Finding 3: Gate::before called hasRole() on whoever was asking. */
    public function test_a_gate_check_for_a_portal_user_answers_no_instead_of_failing(): void
    {
        $portalUser = $this->portalUser();

        $this->assertFalse(Gate::forUser($portalUser)->allows('loans:view'));
    }

    /** Finding 4: the `api` limiter keyed portal user 7 and staff user 7 alike. */
    public function test_the_api_limiter_keeps_portal_users_apart_from_staff_with_the_same_id(): void
    {
        $staff = User::factory()->create();
        $portalUser = (new PortalUser)->forceFill(['id' => $staff->id]);

        $staffKey = $this->apiLimiterKeyFor($staff);
        $portalKey = $this->apiLimiterKeyFor($portalUser);

        $this->assertNotSame($staffKey, $portalKey);
        $this->assertStringStartsWith('portal:', $portalKey);
    }

    private function apiLimiterKeyFor(Model $user): string
    {
        $request = Request::create('/api/loans', 'GET');
        $request->setUserResolver(fn () => $user);
        $limits = RateLimiter::limiter('api')($request);
        $limit = is_array($limits) ? $limits[0] : $limits;
        $this->assertInstanceOf(Limit::class, $limit);

        return (string) $limit->key;
    }

    /** Finding 5: an audit row written by a member named a staff user id. */
    public function test_an_audit_row_written_by_a_member_records_the_member_actor(): void
    {
        $portalUser = $this->portalUser();
        Auth::setUser($portalUser);

        $row = AuditLogService::log('portal_test', $portalUser->borrower, description: 'Member did something');

        $row = AuditLog::query()->findOrFail($row->id);
        $this->assertNull($row->user_id);
        $this->assertSame('member', $row->actor_type);
        $this->assertSame($portalUser->id, (int) $row->portal_user_id);
    }

    public function test_a_staff_audit_row_is_unchanged(): void
    {
        $this->seedAndLogin();

        $row = AuditLogService::log('portal_test', $this->admin, description: 'Staff did something');

        $row = AuditLog::query()->findOrFail($row->id);
        $this->assertSame($this->admin->id, (int) $row->user_id);
        $this->assertNull($row->actor_type);
        $this->assertNull($row->portal_user_id);
    }

    /** Finding 6: a signed link would have been bound to an unrelated staff id. */
    public function test_a_signed_file_link_is_never_minted_for_a_portal_caller(): void
    {
        $borrower = Borrower::factory()->create();
        Auth::setUser($this->portalUser());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Signed file links are minted for staff only');

        SignedFileLink::to('files.borrower-photo', ['borrower' => $borrower->id]);
    }

    /**
     * Finding 7: the branch-scoped route bindings were unscoped for any caller
     * that wasn't a staff User. Now only nobody-signed-in is unscoped (the
     * signed file links); any other authenticatable resolves nothing.
     */
    public function test_every_scoped_binding_resolves_nothing_for_a_caller_who_is_not_staff(): void
    {
        $fixtures = $this->recordOfEachScopedModel();
        $portalUser = $this->portalUser();

        foreach ($fixtures as $class => $id) {
            request()->setUserResolver(fn () => null);
            $this->assertNotNull((new $class)->resolveRouteBinding($id), "{$class} resolves for nobody signed in (signed links).");

            request()->setUserResolver(fn () => $portalUser);
            $this->assertNull((new $class)->resolveRouteBinding($id), "{$class} resolved for a portal user.");
        }
    }

    /** @return array<class-string<Model>, int> */
    private function recordOfEachScopedModel(): array
    {
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $branch = Branch::factory()->create();
        $borrower = Borrower::factory()->create(['branch_id' => $branch->id]);
        $loan = Loan::factory()->create([
            'branch_id' => $branch->id,
            'borrower_id' => $borrower->id,
            'loan_product_id' => LoanProduct::factory(),
            'created_by' => $this->admin->id,
        ]);

        return [
            Borrower::class => $borrower->id,
            Loan::class => $loan->id,
            Repayment::class => Repayment::factory()->create(['loan_id' => $loan->id, 'received_by' => $this->admin->id])->id,
            LoanAdjustment::class => LoanAdjustment::factory()->create(['loan_id' => $loan->id, 'adjusted_by' => $this->admin->id])->id,
            CoMaker::class => CoMaker::factory()->create(['borrower_id' => $borrower->id])->id,
            Collateral::class => Collateral::factory()->create(['borrower_id' => $borrower->id])->id,
            ShareCapitalPledge::class => ShareCapitalPledge::where('borrower_id', $borrower->id)->firstOrFail()->id,
            Document::class => Document::create([
                'documentable_type' => Borrower::class,
                'documentable_id' => $borrower->id,
                'type' => 'other',
                'file_path' => 'documents/other/borrower/x/a.pdf',
                'original_filename' => 'a.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 10,
            ])->id,
            AccountingExpense::class => AccountingExpense::factory()->create(['branch_id' => $branch->id])->id,
            AccountingJournal::class => $this->draftJournal([
                ['account_id' => $this->account('5030'), 'debit' => 10000, 'credit' => 0],
                ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 10000],
            ], ['branch_id' => $branch->id])->id,
            User::class => User::factory()->inBranches($branch)->create()->id,
        ];
    }

    /** Finding 9: purging a member with a portal account hit its foreign key. */
    public function test_deleting_a_member_with_a_portal_account_answers_422_in_words(): void
    {
        $this->seedAndLogin();
        $portalUser = $this->portalUser();

        $this->deleteJson("/api/borrowers/{$portalUser->borrower_id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This member has a member portal account, so the record can\'t be deleted. Turn off portal access instead.');

        $this->assertDatabaseHas('borrowers', ['id' => $portalUser->borrower_id]);
    }
}

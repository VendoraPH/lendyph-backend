<?php

namespace Tests\Feature;

use App\Models\AccountingExpense;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Collateral;
use App\Models\CoMaker;
use App\Models\Document;
use App\Models\GCashTransaction;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\Role;
use App\Models\ShareCapitalPledge;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Every API route bound to a branch-owned record answers 404 to a user from
 * another branch (decision 5b), whatever the method and whatever permissions
 * the caller holds.
 *
 * Built from the live route list, so a route added later with one of these
 * parameters is swept by default: it passes only if its binding is scoped.
 * Signed `/files/*` routes are left out on purpose; their signature is their
 * authorisation and they are reached without a signed-in user.
 */
class BranchAccessRouteSweepTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const BOUND_PARAMETERS = [
        'loan', 'borrower', 'repayment', 'loanAdjustment', 'coMaker', 'document',
        'collateral', 'pledge', 'journal', 'expense', 'user', 'transaction',
    ];

    public function test_every_route_bound_to_a_branch_owned_record_answers_404_to_another_branch(): void
    {
        $this->seedAndLogin();
        $this->seedChartOfAccounts();

        // The sweep makes ~100 requests as one user; rate limits are not what
        // it tests.
        $this->withoutMiddleware(ThrottleRequests::class);

        $fixtures = $this->farBranchFixtures();
        $caller = $this->mainBranchCallerWithEveryOtherPermission();

        $swept = [];
        $leaks = [];

        foreach ($this->routesToSweep() as $route) {
            $uri = '/'.preg_replace_callback(
                '/\{(\w+)\??\}/',
                fn (array $m) => (string) ($fixtures[$m[1]] ?? 999999),
                $route->uri(),
            );

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->actingAs($caller);
                $status = $this->json($method, $uri, [])->status();
                $swept[] = "{$method} {$uri}";

                if ($status !== 404) {
                    $leaks[] = "{$method} /{$route->uri()} answered {$status}";
                }
            }
        }

        $this->assertGreaterThan(60, count($swept), 'The sweep found fewer routes than expected; is it reading the route list?');
        $this->assertSame([], $leaks, "These routes reach another branch's record:\n  ".implode("\n  ", $leaks));
    }

    /**
     * @return list<Route>
     */
    private function routesToSweep(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $route) => str_starts_with($route->uri(), 'api/')
                && array_intersect($route->parameterNames(), self::BOUND_PARAMETERS) !== []
                && ! in_array('signed', $route->gatherMiddleware(), true),
        ));
    }

    /**
     * One record of each bound type, all in a branch the caller is not in.
     *
     * @return array<string, int>
     */
    private function farBranchFixtures(): array
    {
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $borrower = Borrower::factory()->create(['branch_id' => $far->id, 'status' => 'active']);
        $loan = Loan::factory()->create([
            'branch_id' => $far->id,
            'borrower_id' => $borrower->id,
            'loan_product_id' => LoanProduct::factory(),
            'created_by' => $this->admin->id,
        ]);
        $coMaker = CoMaker::factory()->create(['borrower_id' => $borrower->id]);
        $document = Document::create([
            'documentable_type' => Borrower::class,
            'documentable_id' => $borrower->id,
            'type' => 'valid_id',
            'file_path' => 'documents/valid_id/borrower/far/id.jpg',
            'original_filename' => 'id.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 11,
        ]);
        $farUser = User::factory()->inBranches($far)->create();
        $farUser->assignRole('viewer');

        return [
            'loan' => $loan->id,
            'borrower' => $borrower->id,
            'repayment' => Repayment::factory()->create(['loan_id' => $loan->id, 'received_by' => $this->admin->id])->id,
            'loanAdjustment' => LoanAdjustment::factory()->create(['loan_id' => $loan->id, 'adjusted_by' => $this->admin->id])->id,
            'coMaker' => $coMaker->id,
            'document' => $document->id,
            'collateral' => Collateral::factory()->create(['borrower_id' => $borrower->id])->id,
            'pledge' => ShareCapitalPledge::where('borrower_id', $borrower->id)->firstOrFail()->id,
            'journal' => $this->draftJournal([
                ['account_id' => $this->account('5030'), 'debit' => 10000, 'credit' => 0],
                ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 10000],
            ], ['branch_id' => $far->id])->id,
            'expense' => AccountingExpense::factory()->create(['branch_id' => $far->id])->id,
            'user' => $farUser->id,
            'transaction' => GCashTransaction::factory()->create([
                'borrower_id' => $borrower->id,
                'transactor_user_id' => $this->admin->id,
                'status' => 'pending',
            ])->id,
        ];
    }

    /**
     * Every permission except `branches:view_all`, so a 404 can only come from
     * branch scoping and never from a missing permission.
     */
    private function mainBranchCallerWithEveryOtherPermission(): User
    {
        $role = Role::create(['name' => 'branch_everything', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::where('guard_name', 'web')->where('name', '!=', BranchAccess::VIEW_ALL)->get());

        $caller = User::factory()->inBranches($this->branch)->create();
        $caller->assignRole($role);

        return $caller;
    }
}

<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\User;
use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Gate;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Branch assignment is DISPLAY-ONLY, and has to stay that way deliberately.
 *
 * `branch_user` looks exactly like the beginning of multi-tenancy, and it is
 * not one. Nothing anywhere reads the AUTHENTICATED user's branch to decide
 * what they may see: there are no global scopes, no policies, and the
 * `branch_id` filter on every list is `nullable` behind `when(filled(...))`, so
 * omitting it returns the whole organisation on purpose. A user assigned to one
 * branch can read every branch's loans, borrowers and staff today, exactly as
 * they could before a user could hold more than one branch.
 *
 * That is a decision, not an oversight, and the risk this guard exists for is
 * how cheap it looks to change. "Users have branches now" reads like an
 * invitation to add `whereIn('branch_id', $user->branches->pluck('id'))` to a
 * list, or a global scope, or a policy — a three-line change that silently
 * redefines what every existing endpoint returns, for every existing account,
 * with no API change to review and nothing in either repo failing.
 *
 * So this fails LOUDLY on the first such change rather than quietly accepting
 * it. It is not a vote against branch scoping; it is a requirement that
 * whoever builds it does so on purpose, decides what happens to a user with no
 * branches and to the reports that currently aggregate across all of them, and
 * deletes this file as part of the same review.
 *
 * Written in the spirit of CreditScoringNotSeededTest: an invariant that can
 * only be broken on purpose, with the reason it matters in the failure message.
 */
class BranchAssignmentIsDisplayOnlyTest extends TestCase
{
    use SetupLendyPH;

    /**
     * Ways to reach the CURRENT REQUEST's user and then its branch.
     *
     * Deliberately narrow: these are the idioms someone adding actor scoping
     * actually writes, and none of them can mean anything but "the branch of
     * whoever is calling". Reading the branch of a user that is the SUBJECT of
     * a request — `$user->branch` in UserController, `$target->branch_id` in a
     * form request — is ordinary and must not be flagged.
     *
     * A two-step version (`$actor = $request->user();` then `$actor->branch_id`
     * further down) would slip past this, which is why the behavioural specs
     * below exist and are the real guard. This catches the one-liner, in the
     * file, at the moment it is written.
     */
    private const ACTOR_BRANCH_PATTERNS = [
        '/auth\(\)\s*->\s*user\(\)\s*->\s*branch/',
        '/Auth::user\(\)\s*->\s*branch/',
        '/request\(\)\s*->\s*user\(\)\s*->\s*branch/',
        '/\$request\s*->\s*user\(\)\s*->\s*branch/',
        '/\$this\s*->\s*user\(\)\s*->\s*branch/',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // -----------------------------------------------------------------
    // Behavioural — what a caller in one branch can actually still read
    // -----------------------------------------------------------------

    /**
     * The loan book is organisation-wide unless the CLIENT asks otherwise.
     *
     * `?branch_id=` is nullable and gated on `filled()`; omitting it is a
     * request for everything. If anyone narrows this list to the caller's own
     * branches, every existing integration and every saved view silently starts
     * returning less, and `meta.stats` — the tab badges and KPI cards over it —
     * goes with it.
     */
    public function test_the_loans_list_is_not_scoped_to_the_callers_branch(): void
    {
        $elsewhere = $this->loanInAnotherBranch();

        $this->actingAs($this->callerInMainBranchOnly());

        $response = $this->getJson('/api/loans')->assertOk();

        $this->assertContains(
            $elsewhere->id,
            array_column($response->json('data'), 'id'),
            $this->explain('GET /api/loans no longer returns loans from branches the caller is not assigned to.'),
        );
    }

    /**
     * The same invariant one level up: the counts over the list agree with the
     * list. Scoping one without the other is the bug that puts a branch-sized
     * page under organisation-sized badges, or the reverse.
     */
    public function test_the_loan_list_stats_are_not_scoped_to_the_callers_branch(): void
    {
        $this->loanInAnotherBranch();

        $this->actingAs($this->callerInMainBranchOnly());

        $stats = $this->getJson('/api/loans')->assertOk()->json('meta.stats');

        $this->assertGreaterThan(
            0,
            (int) ($stats['draft'] ?? 0),
            $this->explain('`meta.stats` on GET /api/loans no longer counts loans outside the caller\'s branches.'),
        );
    }

    public function test_the_users_list_is_not_scoped_to_the_callers_branch(): void
    {
        $elsewhere = User::factory()->inBranches($this->otherBranch())->create();
        $elsewhere->assignRole('viewer');

        $this->actingAs($this->callerInMainBranchOnly());

        $response = $this->getJson('/api/users')->assertOk();

        $this->assertContains(
            $elsewhere->id,
            array_column($response->json('data'), 'id'),
            $this->explain('GET /api/users no longer lists staff from branches the caller is not assigned to.'),
        );
    }

    public function test_the_borrowers_list_is_not_scoped_to_the_callers_branch(): void
    {
        $elsewhere = Borrower::factory()->create(['branch_id' => $this->otherBranch()->id]);

        $this->actingAs($this->callerInMainBranchOnly());

        $response = $this->getJson('/api/borrowers')->assertOk();

        $this->assertContains(
            $elsewhere->id,
            array_column($response->json('data'), 'id'),
            $this->explain('GET /api/borrowers no longer lists members from branches the caller is not assigned to.'),
        );
    }

    /**
     * Reading ONE record from another branch is still allowed too.
     *
     * A list filter is the obvious place to add scoping; the show endpoint is
     * where it gets added second, and where it turns into a 403/404 on a URL
     * somebody has bookmarked.
     */
    public function test_a_single_loan_from_another_branch_is_still_readable(): void
    {
        $elsewhere = $this->loanInAnotherBranch();

        $this->actingAs($this->callerInMainBranchOnly());

        $this->getJson("/api/loans/{$elsewhere->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $elsewhere->id);
    }

    // -----------------------------------------------------------------
    // Structural — the three places scoping gets installed invisibly
    // -----------------------------------------------------------------

    /**
     * Nothing in app/ reaches for the calling user's branch.
     */
    public function test_no_application_code_reads_the_callers_branch(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(app_path()) as $relative => $source) {
            foreach (self::ACTOR_BRANCH_PATTERNS as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $offenders[] = $relative;
                    break;
                }
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders, $this->explain(sprintf(
            "These files read the branch of whoever is calling:\n\n  %s",
            implode("\n  ", $offenders),
        )));
    }

    /**
     * No model carries a global scope.
     *
     * A global scope is the most invisible way to install branch filtering:
     * every query in the application changes, including the ones inside
     * reports and exports, and no endpoint, resource or test signature moves.
     * `SoftDeletingScope` is allowed because it is the framework's own and has
     * nothing to do with branches.
     */
    public function test_no_model_declares_a_global_scope(): void
    {
        $offenders = [];

        foreach ($this->modelClasses() as $class) {
            $scopes = array_keys((new $class)->getGlobalScopes());
            $scopes = array_values(array_diff($scopes, [SoftDeletingScope::class]));

            if ($scopes !== []) {
                $offenders[$class] = $scopes;
            }
        }

        $this->assertSame([], $offenders, $this->explain(sprintf(
            "These models now filter every query they are used in:\n\n  %s",
            json_encode($offenders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        )));
    }

    /**
     * No authorization policies exist, so there is nowhere for a branch check
     * to hide behind `$this->authorize()`.
     *
     * Checked two ways because Laravel discovers policies by convention as well
     * as by registration: an `App\Policies\LoanPolicy` file would bind itself
     * without appearing in `Gate::policies()`.
     */
    public function test_no_authorization_policies_are_registered(): void
    {
        $this->assertSame([], Gate::policies(), $this->explain('A policy has been registered.'));

        $this->assertSame(
            [],
            is_dir(app_path('Policies')) ? array_keys($this->phpFilesIn(app_path('Policies'))) : [],
            $this->explain('app/Policies now contains classes, which Laravel auto-discovers without registration.'),
        );

        foreach ([Loan::class, Borrower::class, User::class, Repayment::class, Branch::class] as $model) {
            $this->assertNull(
                Gate::getPolicyFor($model),
                $this->explain("A policy is now bound to {$model}."),
            );
        }
    }

    /**
     * Proves the source detector rather than trusting it.
     *
     * Without this, a typo in one of the patterns would report an empty
     * offender list forever and the guard above would pass for the wrong
     * reason — the failure mode that makes a green suite worthless.
     */
    public function test_the_source_detector_actually_matches_an_actor_branch_read(): void
    {
        $samples = [
            '$ids = auth()->user()->branches->pluck("id");',
            '$q->where("branch_id", Auth::user()->branch_id);',
            '$q->where("branch_id", request()->user()->branch_id);',
            '$q->whereIn("branch_id", $request->user()->branches()->pluck("branches.id"));',
            'return $this->user()->branch_id === $target->branch_id;',
        ];

        foreach ($samples as $sample) {
            $matched = false;

            foreach (self::ACTOR_BRANCH_PATTERNS as $pattern) {
                $matched = $matched || preg_match($pattern, $sample) === 1;
            }

            $this->assertTrue($matched, "The detector missed: {$sample}");
        }

        // And does not fire on reading the branch of the user a request is
        // ABOUT, which is ordinary and happens in UserController today.
        foreach (['$user->load("branch");', '$target->branch_id', '$borrower->branch->name'] as $innocent) {
            foreach (self::ACTOR_BRANCH_PATTERNS as $pattern) {
                $this->assertSame(0, preg_match($pattern, $innocent), "False positive on: {$innocent}");
            }
        }
    }

    // -----------------------------------------------------------------

    /**
     * An operator holding every permission, assigned to exactly one branch.
     *
     * `admin` rather than the seeded `super_admin`: super_admin short-circuits
     * every gate through `Gate::before` in AppServiceProvider, so it would sail
     * past a policy-based branch restriction and report that nothing had
     * changed. The caller has to be someone the authorization layer actually
     * evaluates.
     */
    private function callerInMainBranchOnly(): User
    {
        $caller = User::factory()->inBranches($this->branch)->create();
        $caller->assignRole('admin');

        return $caller;
    }

    private function otherBranch(): Branch
    {
        return Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
    }

    private function loanInAnotherBranch(): Loan
    {
        $branch = $this->otherBranch();

        return Loan::factory()->create([
            'branch_id' => $branch->id,
            'borrower_id' => Borrower::factory()->create(['branch_id' => $branch->id]),
            'loan_product_id' => LoanProduct::factory(),
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * @return list<class-string<Model>>
     */
    private function modelClasses(): array
    {
        $classes = [];

        foreach (array_keys($this->phpFilesIn(app_path('Models'))) as $relative) {
            $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $relative);

            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * Relative path => source, for every PHP file under $directory.
     *
     * @return array<string, string>
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $files[str_replace($directory.'/', '', $file->getPathname())] = file_get_contents($file->getPathname());
        }

        ksort($files);

        return $files;
    }

    private function explain(string $what): string
    {
        return $what."\n\n".implode("\n", [
            'Branch assignment on a user is DISPLAY-ONLY in this release. `branch_user` records',
            'where staff work so the UI can show it; it has never decided what anybody may read,',
            'and every list is organisation-wide unless the CLIENT passes `?branch_id=`.',
            '',
            'Scoping by the caller\'s branches may well be the right feature. It is not a',
            'refactor: it changes what every existing endpoint returns for every existing',
            'account, with no API change for a reviewer to notice. It needs a decision about',
            'users with no branches, about the reports that deliberately aggregate across all',
            'of them, and about who is exempt — and then this whole file should be deleted in',
            'the same change.',
        ]);
    }
}

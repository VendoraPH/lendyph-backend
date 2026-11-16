<?php

namespace Tests\Feature\Linda;

use App\Models\Branch;
use App\Services\Linda\LindaScope;

/**
 * A branch named in a question is matched only among the caller's own
 * branches: exactly first, then by a unique start, ignoring case, spaces and
 * a trailing "branch".
 */
class LindaScopeTest extends LindaAnswersTestCase
{
    public function test_without_a_branch_the_scope_is_the_users_own(): void
    {
        $all = LindaScope::resolve($this->headOffice(), null);
        $this->assertTrue($all->ok());
        $this->assertSame(['branch_ids' => null], $all->filters());
        $this->assertSame('all branches', $all->label);

        $one = LindaScope::resolve($this->branchUser(), null);
        $this->assertSame(['branch_ids' => [$this->branch->id]], $one->filters());
        $this->assertSame('Main Branch', $one->label);

        $two = LindaScope::resolve($this->userWith(['reports:view'], $this->branch, $this->far), null);
        $this->assertSame('all your branches', $two->label);
        $this->assertEqualsCanonicalizing([$this->branch->id, $this->far->id], $two->filters()['branch_ids']);
        $this->assertArrayNotHasKey('branch_id', $two->filters());

        $none = LindaScope::resolve($this->userWith(['reports:view']), null);
        $this->assertFalse($none->ok());
        $this->assertSame('no_branch_assigned', $none->problem);
    }

    public function test_a_branch_name_matches_exactly_then_by_a_unique_start(): void
    {
        $user = $this->headOffice();

        foreach (['Far Branch', 'far', '  FAR   branch ', 'Far branch'] as $name) {
            $scope = LindaScope::resolve($user, $name);
            $this->assertTrue($scope->ok(), $name);
            $this->assertSame(['branch_ids' => null, 'branch_id' => $this->far->id], $scope->filters(), $name);
            $this->assertSame('Far Branch', $scope->label);
        }

        $this->assertSame($this->branch->id, LindaScope::resolve($user, 'mai')->filters()['branch_id']);

        // "San Jose" is an exact match even though "San Jose del Monte" starts with it too.
        $sanJose = Branch::factory()->create(['name' => 'San Jose', 'code' => 'SJ']);
        Branch::factory()->create(['name' => 'San Jose del Monte', 'code' => 'SJDM']);
        $this->assertSame($sanJose->id, LindaScope::resolve($user, 'san jose branch')->filters()['branch_id']);

        $ambiguous = LindaScope::resolve($user, 'San');
        $this->assertSame('ambiguous', $ambiguous->problem);
        $this->assertSame(['San Jose', 'San Jose del Monte'], $ambiguous->matches);

        $missing = LindaScope::resolve($user, 'Iloilo');
        $this->assertSame('not_found', $missing->problem);
        $this->assertSame([], $missing->matches);

        $this->assertSame('not_found', LindaScope::resolve($user, 'branch')->problem);
    }

    public function test_a_branch_the_user_cant_see_is_never_matched(): void
    {
        $user = $this->branchUser();

        $this->assertSame('not_found', LindaScope::resolve($user, 'Far Branch')->problem);
        $this->assertSame('not_found', LindaScope::resolve($user, 'f')->problem);
        $this->assertSame([], LindaScope::resolve($user, 'Far')->matches);

        // A start shared with a hidden branch is still unique among the user's own.
        Branch::factory()->create(['name' => 'Mainland Branch', 'code' => 'ML']);
        $this->assertSame(['branch_ids' => [$this->branch->id], 'branch_id' => $this->branch->id], LindaScope::resolve($user, 'Main')->filters());
    }
}

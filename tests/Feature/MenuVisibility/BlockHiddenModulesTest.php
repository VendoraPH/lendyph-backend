<?php

use App\Http\Middleware\BlockHiddenModules;
use App\Http\Middleware\Portal\RequirePortalTerms;
use App\Http\Middleware\PublicApply\EnsureOriginationEnabled;
use App\Http\Middleware\RequirePasswordChange;
use App\Models\Borrower;
use App\Services\MenuVisibility\MenuModules;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\MenuVisibility\MenuVisibilityTestHelpers;
use Tests\Feature\Phase6\LoanOrigination\PublicIntake\PublicApplyHelpers;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;

uses(TestCase::class, MenuVisibilityTestHelpers::class, PortalTestHelpers::class, PublicApplyHelpers::class);

/*
 * The middleware (finish run round 6): a hidden module's own API answers 404
 * {"message":"Not found."} on staff, member portal and public routes alike,
 * and answers as before once it is shown again. Routes another visible menu
 * needs stay open, and paths match by whole segment.
 */

function isTheHiddenAnswer(TestResponse $response): bool
{
    return $response->getStatusCode() === 404
        && $response->baseResponse instanceof JsonResponse
        && $response->json() === ['message' => 'Not found.'];
}

beforeEach(function () {
    $this->enablePortal();
    $this->memberToken = $this->portalToken($this->portalMember());
});

it('answers 404 for a hidden module\'s prefix and as before once it is shown', function (string $module, array $call) {
    $shown = $this->callAs($call);
    expect(isTheHiddenAnswer($shown))->toBeFalse("{$call[0]} {$call[1]} already answers like a hidden module.");

    $this->hideModules([$module]);
    $hidden = $this->callAs($call);
    expect(isTheHiddenAnswer($hidden))->toBeTrue("{$call[0]} {$call[1]} answered {$hidden->getStatusCode()} with {$module} hidden.");

    $this->hideModules([]);
    expect($this->callAs($call)->getStatusCode())->toBe($shown->getStatusCode());
})->with([
    'dashboard/stats' => ['dashboard', fn () => ['GET', '/api/dashboard/stats', 'staff']],
    'dashboard/collections-trend' => ['dashboard', fn () => ['GET', '/api/dashboard/collections-trend', 'staff']],
    'dashboard/daily-dues' => ['dashboard', fn () => ['GET', '/api/dashboard/daily-dues', 'staff']],
    'dashboard/recent-transactions' => ['dashboard', fn () => ['GET', '/api/dashboard/recent-transactions', 'staff']],
    'dashboard/executive' => ['executive_dashboard', fn () => ['GET', '/api/dashboard/executive/overview', 'staff']],
    'dashboard/history' => ['executive_dashboard', fn () => ['GET', '/api/dashboard/history', 'staff']],
    'dashboard/settings' => ['executive_dashboard', fn () => ['GET', '/api/dashboard/settings', 'staff']],
    'linda (settings)' => ['linda', fn () => ['GET', '/api/linda/settings', 'staff']],
    'linda (chat)' => ['linda', fn () => ['POST', '/api/linda/chat', 'staff', ['message' => 'How much did we collect today?']]],
    'loan-adjustments' => ['loans', fn () => ['GET', '/api/loan-adjustments/999999', 'staff']],
    'loans/amortization-calculator' => ['loans', fn () => ['POST', '/api/loans/amortization-calculator', 'staff']],
    'reminders' => ['loans.reminders', fn () => ['GET', '/api/reminders/settings', 'staff']],
    'loans/*/reminders' => ['loans.reminders', fn () => ['GET', '/api/loans/999999/reminders', 'staff']],
    'borrowers/*/notification-preferences' => ['loans.reminders', fn () => ['GET', '/api/borrowers/'.Borrower::factory()->create()->id.'/notification-preferences', 'staff']],
    'loan-origination' => ['loans.origination', fn () => ['GET', '/api/loan-origination/settings', 'staff']],
    'public-apply (public)' => ['loans.origination', fn () => ['GET', '/api/public-apply/'.$this->enableIntake()->token, 'none']],
    'auto-pay' => ['payments.auto_pay', fn () => ['GET', '/api/auto-pay/preview', 'staff']],
    'PATCH loans/*/auto-pay' => ['payments.auto_pay', fn () => ['PATCH', '/api/loans/999999/auto-pay', 'staff']],
    'share-capital/members' => ['share_capital', fn () => ['GET', '/api/share-capital/members', 'staff']],
    'share-capital/cash-reference' => ['share_capital', fn () => ['GET', '/api/share-capital/cash-reference/next', 'staff']],
    'POST share-capital/ledger' => ['share_capital', fn () => ['POST', '/api/share-capital/ledger', 'staff']],
    'pledges' => ['share_capital', fn () => ['GET', '/api/pledges', 'staff']],
    'auto-credit' => ['share_capital.auto_credit', fn () => ['GET', '/api/auto-credit/status', 'staff']],
    'PATCH pledges/*/auto-credit' => ['share_capital.auto_credit', fn () => ['PATCH', '/api/pledges/999999/auto-credit', 'staff']],
    'savings (settings)' => ['savings', fn () => ['GET', '/api/savings/settings', 'staff']],
    'savings (an account)' => ['savings', fn () => ['GET', '/api/savings/accounts/999999', 'staff']],
    'savings (opening an account)' => ['savings', fn () => ['POST', '/api/savings/accounts', 'staff']],
    'reports/savings' => ['savings', fn () => ['GET', '/api/reports/savings/summary', 'staff']],
    'portal/savings (member)' => ['savings', fn () => ['GET', '/api/portal/savings', 'member']],
    'portal/time-deposits (member)' => ['savings', fn () => ['GET', '/api/portal/time-deposits', 'member']],
    'collaterals/register' => ['collateral', fn () => ['GET', '/api/collaterals/register', 'staff']],
    'POST collaterals' => ['collateral', fn () => ['POST', '/api/collaterals', 'staff']],
    'PUT collaterals/*' => ['collateral', fn () => ['PUT', '/api/collaterals/999999', 'staff']],
    'DELETE collaterals/*' => ['collateral', fn () => ['DELETE', '/api/collaterals/999999', 'staff']],
    'gcash' => ['gcash', fn () => ['GET', '/api/gcash/tiers', 'staff']],
    'credit-scoring' => ['credit_scoring', fn () => ['GET', '/api/credit-scoring/settings', 'staff']],
    'cda' => ['cda', fn () => ['GET', '/api/cda/periods', 'staff']],
    'portal-admin' => ['member_portal', fn () => ['GET', '/api/portal-admin/settings', 'staff']],
    'portal (public)' => ['member_portal', fn () => ['GET', '/api/portal/config', 'none']],
    'portal (member)' => ['member_portal', fn () => ['GET', '/api/portal/dashboard', 'member']],
    'amla' => ['amla', fn () => ['GET', '/api/amla/status', 'staff']],
    'accounting' => ['accounting', fn () => ['GET', '/api/accounting/accounts', 'staff']],
    'interbranch-transfers' => ['accounting', fn () => ['GET', '/api/interbranch-transfers', 'staff']],
    'users' => ['users', fn () => ['GET', '/api/users', 'staff']],
    'reports/daily-collection' => ['reports', fn () => ['GET', '/api/reports/daily-collection', 'staff']],
    'reports/income' => ['reports', fn () => ['GET', '/api/reports/income/by-loan', 'staff']],
    'reports/profit-sharing' => ['reports', fn () => ['GET', '/api/reports/profit-sharing', 'staff']],
    'reports/provisioning' => ['reports', fn () => ['GET', '/api/reports/provisioning', 'staff']],
    'reports/releases' => ['reports', fn () => ['GET', '/api/reports/releases/export', 'staff']],
    'reports/repayments' => ['reports', fn () => ['GET', '/api/reports/repayments', 'staff']],
    'reports/due-past-due/export' => ['reports', fn () => ['GET', '/api/reports/due-past-due/export', 'staff']],
    'audit-logs' => ['audit_trail', fn () => ['GET', '/api/audit-logs', 'staff']],
]);

it('answers 200 again on the main screens once their module is shown', function (string $module, array $call) {
    $this->callAs($call)->assertOk();
    $this->hideModules([$module]);
    expect(isTheHiddenAnswer($this->callAs($call)))->toBeTrue();
    $this->hideModules([]);
    $this->callAs($call)->assertOk();
})->with([
    'Linda' => ['linda', fn () => ['GET', '/api/linda/settings', 'staff']],
    'Savings' => ['savings', fn () => ['GET', '/api/savings/settings', 'staff']],
    'the portal\'s savings' => ['savings', fn () => ['GET', '/api/portal/savings', 'member']],
    'CDA' => ['cda', fn () => ['GET', '/api/cda/periods', 'staff']],
    'AMLA' => ['amla', fn () => ['GET', '/api/amla/status', 'staff']],
    'GCash' => ['gcash', fn () => ['GET', '/api/gcash/tiers', 'staff']],
    'Accounting' => ['accounting', fn () => ['GET', '/api/accounting/accounts', 'staff']],
    'the Member Portal (staff)' => ['member_portal', fn () => ['GET', '/api/portal-admin/settings', 'staff']],
    'the Member Portal (public)' => ['member_portal', fn () => ['GET', '/api/portal/config', 'none']],
    'the Member Portal (member)' => ['member_portal', fn () => ['GET', '/api/portal/dashboard', 'member']],
    'Reminders' => ['loans.reminders', fn () => ['GET', '/api/reminders/settings', 'staff']],
    'Loan Origination (staff)' => ['loans.origination', fn () => ['GET', '/api/loan-origination/settings', 'staff']],
    'Loan Origination (public)' => ['loans.origination', fn () => ['GET', '/api/public-apply/'.$this->enableIntake()->token, 'none']],
    'Auto-Credit' => ['share_capital.auto_credit', fn () => ['GET', '/api/auto-credit/status', 'staff']],
    'the executive dashboard' => ['executive_dashboard', fn () => ['GET', '/api/dashboard/settings', 'staff']],
    'the Dashboard' => ['dashboard', fn () => ['GET', '/api/dashboard/stats', 'staff']],
]);

it('hides a menu\'s submenus\' API with it', function () {
    $call = ['GET', '/api/reminders/settings', 'staff'];

    $this->hideModules(['loans']);
    expect(isTheHiddenAnswer($this->callAs($call)))->toBeTrue();

    $this->hideModules(['loans.reminders']);
    expect(isTheHiddenAnswer($this->callAs($call)))->toBeTrue()
        ->and(isTheHiddenAnswer($this->callAs(['GET', '/api/loan-origination/settings', 'staff'])))->toBeFalse();
});

it('keeps open what another visible menu needs, with every module hidden', function (array $call, array $shown = []) {
    $this->hideModules(array_values(array_diff(MenuModules::keys(), $shown)));

    expect(isTheHiddenAnswer($this->callAs($call)))->toBeFalse("{$call[0]} {$call[1]} was blocked.");
})->with([
    'sign-in, me and health' => [['GET', '/api/auth/me', 'staff']],
    'health' => [['GET', '/api/health', 'none']],
    'notifications' => [['GET', '/api/notifications/unread-count', 'staff']],
    'Menu Visibility itself' => [['GET', '/api/menu-visibility/hidden', 'staff']],
    'AMLA orders list a member\'s savings accounts' => [['GET', '/api/savings/accounts', 'staff'], ['amla']],
    'the executive dashboard\'s financial section' => [['GET', '/api/accounting/dashboard', 'staff'], ['executive_dashboard']],
    'Settings → Branches\' transfer counts' => [['GET', '/api/branch-counts', 'staff']],
    'members' => [['GET', '/api/borrowers', 'staff']],
    'loans' => [['GET', '/api/loans', 'staff']],
    'payments' => [['GET', '/api/repayments', 'staff']],
    'a loan\'s Record Payment' => [['POST', '/api/loans/999999/repayments/preview', 'staff']],
    'share capital balances' => [['GET', '/api/share-capital/ledger', 'staff']],
    'the share capital report' => [['GET', '/api/reports/share-capital', 'staff']],
    'past due loans' => [['GET', '/api/reports/due-past-due', 'staff']],
    'a loan\'s collaterals' => [['GET', '/api/collaterals', 'staff']],
    'account officer pickers' => [['GET', '/api/staff', 'staff']],
    'roles' => [['GET', '/api/roles', 'staff']],
    'approval workflow settings' => [['GET', '/api/settings/approval-workflow', 'staff']],
    'public branches' => [['GET', '/api/branches/public', 'none']],
]);

it('matches whole segments, never a prefix of one', function () {
    $this->hideModules(['share_capital', 'dashboard', 'reports', 'savings']);

    // `reports/share-capital` and `reports/share-capital-statement` are not
    // `share-capital`; `dashboard/stats` is not `dashboard/executive`.
    expect(isTheHiddenAnswer($this->callAs(['GET', '/api/reports/share-capital', 'staff'])))->toBeFalse()
        ->and(isTheHiddenAnswer($this->callAs(['GET', '/api/dashboard/executive/overview', 'staff'])))->toBeFalse()
        ->and(isTheHiddenAnswer($this->callAs(['GET', '/api/portal/share-capital', 'member'])))->toBeFalse()
        ->and(isTheHiddenAnswer($this->callAs(['GET', '/api/share-capital/ledger', 'staff'])))->toBeFalse()
        ->and(isTheHiddenAnswer($this->callAs(['GET', '/api/share-capital/members', 'staff'])))->toBeTrue();
});

it('matches the route, never the raw path, so an encoded path is blocked too', function () {
    $this->hideModules(['gcash']);

    expect(isTheHiddenAnswer($this->callAs(['GET', '/api/%67cash/tiers', 'staff'])))->toBeTrue()
        ->and(isTheHiddenAnswer($this->callAs(['GET', '/api/gcash/tiers/', 'staff'])))->toBeTrue();
});

it('runs after every post-auth gate and ahead of route-model binding', function (string $method, string $uri, string $lastGate) {
    $route = collect(Route::getRoutes()->getRoutes())->first(
        fn (RoutingRoute $candidate) => $candidate->uri() === $uri && in_array($method, $candidate->methods(), true),
    );
    $stack = array_values(array_filter(app('router')->gatherRouteMiddleware($route), 'is_string'));

    $at = array_search(BlockHiddenModules::class, $stack, true);

    expect($at)->toBeInt()
        ->and($at)->toBeGreaterThan(array_search($lastGate, $stack, true))
        ->and($at)->toBeLessThan(array_search(SubstituteBindings::class, $stack, true));
})->with([
    'staff' => ['GET', 'api/savings/accounts/{account}', RequirePasswordChange::class],
    'member portal' => ['GET', 'api/portal/savings/{id}', RequirePortalTerms::class],
    'public' => ['GET', 'api/public-apply/{token}', EnsureOriginationEnabled::class],
]);

it('never revives an idle sign-in: the session still expires on a hidden module\'s route', function () {
    $this->hideModules(['gcash']);
    $token = $this->superAdmin()->createToken('auth-token', ['*'], now()->addDays(30));
    $token->accessToken->forceFill(['last_used_at' => now()->subMinutes(31)])->save();

    $this->asToken($token->plainTextToken, 'GET', '/api/gcash/tiers')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Session expired due to inactivity.');

    expect(PersonalAccessToken::query()->whereKey($token->accessToken->id)->exists())->toBeFalse();
});

it('answers 404 for the CDA review chain while CDA is hidden, and leaves the loan chains as they are', function () {
    $cdaChain = [['id' => 'manager', 'name' => 'Manager review', 'role' => 'manager']];
    $calls = [
        'read the CDA chain' => ['GET', '/api/settings/approval-workflow?type=cda', 'staff'],
        'save the CDA chain' => ['PUT', '/api/settings/approval-workflow', 'staff', ['type' => 'cda', 'steps' => $cdaChain]],
        'reset the CDA chain' => ['DELETE', '/api/settings/approval-workflow?type=cda', 'staff'],
    ];

    $this->hideModules(['cda']);

    foreach ($calls as $what => $call) {
        expect(isTheHiddenAnswer($this->callAs($call)))->toBeTrue("{$what} answered with CDA hidden.");
    }

    $this->callAs(['GET', '/api/settings/approval-workflow?type=normal', 'staff'])->assertOk()->assertJsonPath('data.type', 'normal');
    $this->callAs(['GET', '/api/settings/approval-workflow', 'staff'])->assertOk()->assertJsonPath('data.type', 'policy_exception');
    $this->callAs(['DELETE', '/api/settings/approval-workflow?type=normal', 'staff'])->assertOk();

    $this->hideModules([]);
    $this->callAs($calls['read the CDA chain'])->assertOk()->assertJsonPath('data.type', 'cda');
});

it('keeps a route open for another menu only while that menu is shown', function (array $hidden, array $call, bool $open) {
    $this->hideModules($hidden);

    expect(isTheHiddenAnswer($this->callAs($call)))->toBe(! $open);
})->with([
    'Savings hidden, AMLA shown' => [['savings'], ['GET', '/api/savings/accounts', 'staff'], true],
    'Savings and AMLA hidden' => [['savings', 'amla'], ['GET', '/api/savings/accounts', 'staff'], false],
    'Accounting hidden, the executive dashboard shown' => [['accounting'], ['GET', '/api/accounting/dashboard', 'staff'], true],
    'Accounting and the executive dashboard hidden' => [['accounting', 'executive_dashboard'], ['GET', '/api/accounting/dashboard', 'staff'], false],
]);

it('blocks the CDA chain when either the query or the body names it', function (string $method, string $uri, array $body) {
    $this->hideModules(['cda']);

    expect(isTheHiddenAnswer($this->callAs([$method, $uri, 'staff', $body])))->toBeTrue();
})->with([
    'read: query cda, body normal' => ['GET', '/api/settings/approval-workflow?type=cda', ['type' => 'normal']],
    'reset: query cda, body normal' => ['DELETE', '/api/settings/approval-workflow?type=cda', ['type' => 'normal']],
    'save: query cda, body normal' => ['PUT', '/api/settings/approval-workflow?type=cda', ['type' => 'normal', 'steps' => []]],
    'read: query normal, body cda' => ['GET', '/api/settings/approval-workflow?type=normal', ['type' => 'cda']],
    'reset: query normal, body cda' => ['DELETE', '/api/settings/approval-workflow?type=normal', ['type' => 'cda']],
    'save: query normal, body cda' => ['PUT', '/api/settings/approval-workflow?type=normal', ['type' => 'cda', 'steps' => []]],
]);

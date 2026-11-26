<?php

use App\Services\MenuVisibility\MenuModules;
use App\Services\MenuVisibility\MenuVisibility;
use Illuminate\Database\QueryException;

/**
 * Menu Visibility's registry (finish run round 6) on its own: a hidden menu
 * hides its submenus, and a route is matched by whole path segments against
 * the route's own URI, never by a loose substring.
 *
 * No database, no application boot.
 */
it('hides a menu\'s submenus with it, in the side menu\'s order', function (array $switchedOff, array $hidden) {
    expect(MenuModules::effective($switchedOff))->toBe($hidden);
})->with([
    'nothing' => [[], []],
    'a parent' => [['share_capital'], ['share_capital', 'share_capital.auto_credit']],
    'a submenu alone' => [['payments.auto_pay'], ['payments.auto_pay']],
    'out of order, twice' => [['savings', 'loans', 'savings'], ['loans', 'loans.reminders', 'loans.origination', 'savings']],
    'an unknown key' => [['payroll', 'gcash'], ['gcash']],
]);

it('matches whole segments, a star for any one segment', function (string $entry, string $method, string $uri, bool $matches) {
    expect(MenuModules::entryMatches($entry, $method, MenuModules::segmentsOf($uri)))->toBe($matches);
})->with([
    'the prefix itself' => ['savings', 'GET', 'api/savings', true],
    'below the prefix' => ['savings', 'POST', 'api/savings/accounts/{account}/deposits', true],
    'a longer first segment' => ['savings', 'GET', 'api/savingsx/accounts', false],
    'a shorter first segment' => ['savings', 'GET', 'api/saving/accounts', false],
    'a later segment' => ['savings', 'GET', 'api/reports/savings', false],
    'a two-segment prefix' => ['reports/savings', 'GET', 'api/reports/savings/summary', true],
    'its sibling' => ['reports/share-capital', 'GET', 'api/reports/share-capital-statement/{borrower}', false],
    'a star' => ['loans/*/reminders', 'GET', 'api/loans/{loan}/reminders', true],
    'a star on another path' => ['loans/*/reminders', 'GET', 'api/loans/{loan}/repayments', false],
    'a method that matches' => ['PATCH loans/*/auto-pay', 'PATCH', 'api/loans/{loan}/auto-pay', true],
    'a method that doesn\'t' => ['POST share-capital/ledger', 'GET', 'api/share-capital/ledger', false],
    'HEAD as GET' => ['GET savings/accounts', 'HEAD', 'api/savings/accounts', true],
    'a route outside the API' => ['savings', 'GET', 'savings/accounts', false],
]);

it('finds the hidden module that blocks a route, unless it keeps that route open', function () {
    expect(MenuModules::blockingModule('GET', 'api/gcash/tiers', ['gcash']))->toBe('gcash')
        ->and(MenuModules::blockingModule('GET', 'api/gcash/tiers', ['linda']))->toBeNull()
        ->and(MenuModules::blockingModule('GET', 'api/savings/accounts', ['savings']))->toBeNull()
        ->and(MenuModules::blockingModule('GET', 'api/savings/accounts', ['savings', 'amla']))->toBe('savings')
        ->and(MenuModules::blockingModule('POST', 'api/savings/accounts', ['savings']))->toBe('savings')
        ->and(MenuModules::blockingModule('GET', 'api/accounting/dashboard', ['accounting']))->toBeNull()
        ->and(MenuModules::blockingModule('GET', 'api/accounting/dashboard/extra', ['accounting']))->toBe('accounting')
        ->and(MenuModules::blockingModule('GET', 'api/reminders/settings', ['loans.reminders']))->toBe('loans.reminders');
});

it('needs the hidden list already widened to submenus', function () {
    expect(MenuModules::blockingModule('GET', 'api/reminders/settings', ['loans']))->toBeNull()
        ->and(MenuModules::blockingModule('GET', 'api/reminders/settings', MenuModules::effective(['loans'])))->toBe('loans.reminders');
});

it('reads a missing switches table as nothing hidden, and nothing else', function (string $sqlState, bool $missing) {
    $driverError = new PDOException("SQLSTATE[{$sqlState}]: driver error");
    $driverError->errorInfo = [$sqlState, 1146, 'driver error'];

    $exception = new QueryException('mysql', 'select `module_key` from `menu_visibility_switches`', [], $driverError);

    expect(MenuVisibility::isMissingTable($exception))->toBe($missing);
})->with([
    'no such table (a deploy before migrate)' => ['42S02', true],
    'no such column' => ['42S22', false],
    'a deadlock' => ['40001', false],
    'connection refused' => ['HY000', false],
]);

it('matches a query-qualified entry only on that value', function (array $input, bool $matches) {
    $segments = MenuModules::segmentsOf('api/settings/approval-workflow');

    expect(MenuModules::entryMatches('settings/approval-workflow?type=cda', 'GET', $segments, input: $input))->toBe($matches)
        ->and(MenuModules::entryMatches('settings/approval-workflow?type=cda', 'GET', $segments))->toBeTrue();
})->with([
    'the CDA chain' => [['type' => 'cda'], true],
    'a loan chain' => [['type' => 'normal'], false],
    'no type' => [[], false],
    'another case' => [['type' => 'CDA'], false],
    'not text' => [['type' => ['cda']], false],
]);

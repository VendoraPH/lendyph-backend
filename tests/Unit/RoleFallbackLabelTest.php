<?php

use App\Models\Role;

/*
 * A role with no saved display name reads by its key in words. Board keys
 * read in capitals ("BOD1", not "Bod1"), as does an "ao" key; every other key
 * keeps the headline style. Display only: no stored value changes.
 */

it('reads an acronym key in capitals', function (string $key, string $label) {
    expect(Role::fallbackLabel($key))->toBe($label);
})->with([
    'board member 1' => ['bod1', 'BOD1'],
    'board member 7' => ['bod7', 'BOD7'],
    'board, no number' => ['bod', 'BOD'],
    'board prefix' => ['bod_chair', 'BOD Chair'],
    'account officer' => ['ao', 'AO'],
    'account officer prefix' => ['ao_senior', 'AO Senior'],
]);

it('keeps the headline style for every other key', function (string $key, string $label) {
    expect(Role::fallbackLabel($key))->toBe($label);
})->with([
    ['loan_officer', 'Loan Officer'],
    ['super_admin', 'Super Admin'],
    ['general_bookkeeper', 'General Bookkeeper'],
    ['cashier', 'Cashier'],
    ['body_guard', 'Body Guard'],
    ['aol', 'Aol'],
    ['not_a_role', 'Not A Role'],
]);

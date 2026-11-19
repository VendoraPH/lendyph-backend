<?php

use App\Models\Branch;
use App\Models\Role;
use Tests\TestCase;

require_once __DIR__.'/StaffFixtures.php';

/*
 * LO-B3: `GET /staff?for=origination`, the assign picker. Names only, of
 * active staff holding `loan_origination:update` through an active role (or
 * directly), in the caller's branches. The caller needs
 * `loan_origination:update`; the loan rule without the parameter is unchanged.
 */
uses(TestCase::class);

beforeEach(function () {
    $this->main = Branch::query()->orderBy('id')->firstOrFail();
    $this->other = Branch::factory()->create(['name' => 'North Branch', 'code' => 'NB']);
});

it('lists only holders of loan_origination:update in the caller\'s branches, names only', function () {
    $caller = lo3Staff($this->main, 'loan_origination:update');
    $holder = lo3Staff($this->main, 'loan_origination:update');
    $plain = lo3Staff($this->main, 'loans:create');
    $elsewhere = lo3Staff($this->other, 'loan_origination:update');
    $inactive = lo3Staff($this->main, 'loan_origination:update');
    $inactive->forceFill(['status' => 'inactive'])->save();
    $offRole = lo3Staff($this->main, 'loan_origination:update');
    Role::query()->whereIn('id', $offRole->roles->pluck('id'))->update(['is_active' => false]);

    $response = $this->actingAs($caller)->getJson('/api/staff?for=origination&per_page=100')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($caller->id, $holder->id)
        ->not->toContain($plain->id, $elsewhere->id, $inactive->id, $offRole->id)
        ->and(array_keys($response->json('data.0')))->toEqualCanonicalizing(['id', 'full_name']);
});

it('needs loan_origination:update for the origination picker', function () {
    $this->actingAs(lo3Staff($this->main, 'loan_origination:view', 'loans:create'))
        ->getJson('/api/staff?for=origination')->assertForbidden();
});

it('keeps the loan and savings pickers as they were', function () {
    $this->actingAs(lo3Staff($this->main, 'loans:create'))->getJson('/api/staff')->assertOk();
    $this->actingAs(lo3Staff($this->main, 'savings:create'))->getJson('/api/staff?for=savings')->assertOk();
    $this->actingAs(lo3Staff($this->main, 'loan_origination:update'))->getJson('/api/staff')->assertForbidden();
    $this->actingAs(lo3Staff($this->main, 'loans:create'))->getJson('/api/staff?for=nothing')->assertUnprocessable();
});

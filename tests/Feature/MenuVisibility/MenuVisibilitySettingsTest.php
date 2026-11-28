<?php

use App\Models\AuditLog;
use App\Models\MenuVisibilitySwitch;
use App\Services\MenuVisibility\MenuModules;
use Tests\Feature\MenuVisibility\MenuVisibilityTestHelpers;
use Tests\TestCase;

uses(TestCase::class, MenuVisibilityTestHelpers::class);

/*
 * Settings → Menu Visibility's own API (finish run round 6): the menus with
 * their switches behind menu_visibility:manage and an open administrator
 * override, and a save that stores only what was switched, in one audit row.
 */

beforeEach(function () {
    config(['cache.default' => 'database']);
    $this->setUpOverrideHash();
    $this->admin = $this->staffWithRole('admin');
    $this->token = $this->tokenFor($this->admin);
});

it('lists every menu in the side menu\'s order with its parent and switch', function () {
    $this->openOverride($this->token)->assertOk();

    $modules = $this->asToken($this->token, 'GET', '/api/menu-visibility')->assertOk()->json('data.modules');

    expect(array_column($modules, 'key'))->toBe([
        'dashboard', 'executive_dashboard', 'linda', 'members', 'loans', 'loans.reminders', 'loans.origination',
        'payments', 'payments.auto_pay', 'share_capital', 'share_capital.auto_credit', 'savings', 'collateral',
        'gcash', 'credit_scoring', 'cic', 'cda', 'member_portal', 'amla', 'accounting', 'users', 'reports',
        'documents', 'audit_trail',
    ])->and($modules[5])->toBe(['key' => 'loans.reminders', 'label' => 'Reminders', 'parent' => 'loans', 'hidden' => false])
        ->and($modules[17])->toBe(['key' => 'member_portal', 'label' => 'Member Portal', 'parent' => null, 'hidden' => false])
        ->and(array_unique(array_column($modules, 'hidden')))->toBe([false]);
});

it('asks for the override before listing or saving', function (string $method) {
    $this->asToken($this->token, $method, '/api/menu-visibility', ['hidden' => ['savings']])
        ->assertForbidden()
        ->assertExactJson([
            'message' => 'Enter the administrator password to open Menu Visibility.',
            'code' => 'override_required',
        ]);

    expect(MenuVisibilitySwitch::query()->exists())->toBeFalse();
})->with(['GET', 'PUT']);

it('refuses a user without the permission before asking for the override', function (string $method) {
    $this->asToken($this->tokenFor($this->staffWithRole('viewer')), $method, '/api/menu-visibility', ['hidden' => []])
        ->assertForbidden()
        ->assertJsonMissingPath('code');
})->with(['GET', 'PUT']);

it('saves the switched menus and answers the same list', function () {
    $this->openOverride($this->token)->assertOk();

    $response = $this->asToken($this->token, 'PUT', '/api/menu-visibility', ['hidden' => ['savings', 'linda']])->assertOk();

    $hidden = collect($response->json('data.modules'))->where('hidden', true)->pluck('key')->all();

    expect($hidden)->toBe(['linda', 'savings'])
        ->and($response->json('data.override_expires_at'))->not->toBeNull()
        ->and(MenuVisibilitySwitch::query()->orderBy('module_key')->get(['module_key', 'hidden', 'updated_by'])->toArray())->toBe([
            ['module_key' => 'linda', 'hidden' => true, 'updated_by' => $this->admin->id],
            ['module_key' => 'savings', 'hidden' => true, 'updated_by' => $this->admin->id],
        ]);
});

it('writes one audit row with both lists, in words, never the password', function () {
    $this->hideModules(['linda']);
    $before = AuditLog::query()->max('id');
    $this->openOverride($this->token)->assertOk();

    $this->asToken($this->token, 'PUT', '/api/menu-visibility', ['hidden' => ['savings', 'share_capital.auto_credit']])->assertOk();

    $row = AuditLog::query()->where('id', '>', $before)->where('action', 'menu_visibility_changed')->sole();

    expect($row->user_id)->toBe($this->admin->id)
        ->and($row->old_values)->toBe(['hidden' => ['linda']])
        ->and($row->new_values)->toBe(['hidden' => ['share_capital.auto_credit', 'savings']])
        ->and($row->description)->toBe('Menu visibility changed: Auto-Credit (Share Capital) hidden, Savings hidden, Linda shown')
        ->and(json_encode($row->toArray(), JSON_THROW_ON_ERROR))->not->toContain(self::OVERRIDE_PASSWORD);

    $this->actingAs($this->superAdmin());
    $this->getJson("/api/audit-logs/{$row->id}")
        ->assertOk()
        ->assertJsonPath('data.changes', [['field' => 'hidden', 'old' => 'Linda', 'new' => 'Auto-Credit (Share Capital), Savings']]);
});

it('writes no audit row and touches nothing when nothing changed', function () {
    $this->hideModules(['savings']);
    $switches = MenuVisibilitySwitch::query()->get()->toArray();
    $before = AuditLog::query()->max('id');
    $this->openOverride($this->token)->assertOk();

    $this->asToken($this->token, 'PUT', '/api/menu-visibility', ['hidden' => ['savings']])->assertOk();

    expect(AuditLog::query()->where('id', '>', $before)->where('action', 'menu_visibility_changed')->exists())->toBeFalse()
        ->and(MenuVisibilitySwitch::query()->get()->toArray())->toBe($switches);
});

it('keeps a row for a menu switched back on, so only what was switched is stored', function () {
    $this->hideModules(['gcash']);
    $this->openOverride($this->token)->assertOk();

    $this->asToken($this->token, 'PUT', '/api/menu-visibility', ['hidden' => []])->assertOk();

    expect(MenuVisibilitySwitch::query()->get(['module_key', 'hidden'])->toArray())->toBe([['module_key' => 'gcash', 'hidden' => false]]);
});

it('refuses menus it doesn\'t know and a list that isn\'t one', function (mixed $hidden, string $field) {
    $this->openOverride($this->token)->assertOk();

    $this->asToken($this->token, 'PUT', '/api/menu-visibility', ['hidden' => $hidden])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);

    expect(MenuVisibilitySwitch::query()->exists())->toBeFalse();
})->with([
    'an unknown key' => [['savings', 'payroll'], 'hidden.1'],
    'settings, which is always on' => [['settings'], 'hidden.0'],
    'a key twice' => [['savings', 'savings'], 'hidden.0'],
    'not a list' => ['savings', 'hidden'],
    'missing' => [null, 'hidden'],
]);

it('names every module in words, a submenu with its menu', function () {
    expect(MenuModules::words('savings'))->toBe('Savings')
        ->and(MenuModules::words('loans.reminders'))->toBe('Reminders (Loans)')
        ->and(MenuModules::words('payments.auto_pay'))->toBe('Auto-Pay (Payments)');
});

<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DashboardSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogModules;
use App\Services\BranchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Phase 6 slice EB-C: the executive dashboard's alert thresholds, one
 * company-wide row. Reading needs `dashboard:analytics`; changing needs
 * `dashboard:settings` and `branches:view_all`. Each change is audited in
 * words, wording stays neutral, and percentages and days are checked in range.
 */

const EBC_DEFAULTS = [
    'par30_rise_points' => '1.00',
    'par30_compare_days' => 30,
    'collection_efficiency_below' => '90.00',
    'overdue_share_above' => '5.00',
    'approval_wait_days' => 3,
    'release_wait_days' => 2,
    'control_difference_above' => '0.00',
];

/** @param  list<string>  $permissions */
function ebcStaff(array $permissions): User
{
    $role = Role::create(['name' => 'ebc_'.Str::lower(Str::random(10)), 'guard_name' => 'web']);
    $role->givePermissionTo($permissions);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->inBranches(Branch::query()->orderBy('id')->firstOrFail())->create();
    $user->assignRole($role);

    return $user;
}

function ebcEditor(): User
{
    return ebcStaff(['dashboard:analytics', 'dashboard:settings', BranchAccess::VIEW_ALL]);
}

/** @return array<string, array{old: ?string, new: ?string}> */
function ebcAuditChanges(AuditLog $row): array
{
    return collect(test()->actingAs(User::where('username', 'super_admin')->firstOrFail())->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes'))
        ->keyBy('field')
        ->map(fn (array $change) => ['old' => $change['old'], 'new' => $change['new']])
        ->all();
}

it('starts with the approved defaults, in one row', function () {
    expect(DashboardSetting::query()->count())->toBe(1);

    $response = $this->actingAs(ebcStaff(['dashboard:analytics']))->getJson('/api/dashboard/settings')->assertOk();

    $response->assertJsonPath('success', true)
        ->assertJsonPath('data.can_update', false)
        ->assertJsonPath('data.updated_by', null);

    expect($response->json('data.thresholds'))->toBe(EBC_DEFAULTS)
        ->and($response->json('data.defaults'))->toBe(EBC_DEFAULTS)
        ->and(array_keys($response->json('data.labels')))->toBe(array_keys(EBC_DEFAULTS))
        ->and(array_column($response->json('data.rules'), 'text'))->toBe([
            'PAR 30 is up 1 point or more on 30 days ago.',
            'Collection Efficiency is below 90%.',
            'Overdue is above 5% of the outstanding balance.',
            'A loan has waited more than 3 days for approval.',
            'An approved loan has not been released after more than 2 days.',
            'A control check shows any difference.',
        ]);
});

it('words the rules with the saved values', function () {
    $response = $this->actingAs(ebcEditor())->putJson('/api/dashboard/settings', [
        'par30_rise_points' => '2.5',
        'par30_compare_days' => 1,
        'approval_wait_days' => 1,
        'control_difference_above' => '0.50',
    ])->assertOk();

    expect(collect($response->json('data.rules'))->pluck('text', 'key')->all())->toMatchArray([
        'par30_rise' => 'PAR 30 is up 2.5 points or more on 1 day ago.',
        'approval_wait' => 'A loan has waited more than 1 day for approval.',
        'control_difference' => 'A control check shows a difference above ₱0.50.',
    ]);
});

it('reads with dashboard analytics only, and says why in words when refused', function (array $permissions) {
    $response = $this->actingAs(ebcStaff($permissions))->getJson('/api/dashboard/settings')->assertForbidden();

    expect($response->json('message'))->toBe('The dashboard alert thresholds need the dashboard analytics permission.')
        ->not->toContain(':');
})->with([
    'nothing' => [[]],
    'dashboard view only' => [['dashboard:view']],
    'settings without all branches' => [['dashboard:settings']],
    'all branches without settings' => [['dashboard:view', BranchAccess::VIEW_ALL]],
]);

it('lets whoever can save the thresholds read them, without dashboard analytics', function () {
    $this->actingAs(ebcStaff(['dashboard:settings', BranchAccess::VIEW_ALL]))
        ->getJson('/api/dashboard/settings')
        ->assertOk()
        ->assertJsonPath('data.can_update', true)
        ->assertJsonPath('data.thresholds.collection_efficiency_below', '90.00');
});

it('lets a holder of dashboard analytics read them without saving rights', function () {
    $this->actingAs(ebcStaff(['dashboard:analytics']))
        ->getJson('/api/dashboard/settings')
        ->assertOk()
        ->assertJsonPath('data.can_update', false);
});

it('answers 401 without a sign-in', function () {
    $this->getJson('/api/dashboard/settings')->assertUnauthorized();
    $this->putJson('/api/dashboard/settings', ['approval_wait_days' => 5])->assertUnauthorized();
});

it('saves a partial change and reads it back', function () {
    $editor = ebcEditor();

    $this->actingAs($editor)->putJson('/api/dashboard/settings', [
        'collection_efficiency_below' => 85.5,
        'approval_wait_days' => 5,
        'control_difference_above' => '100',
    ])->assertOk()
        ->assertJsonPath('message', 'Dashboard alert thresholds saved.')
        ->assertJsonPath('data.thresholds.collection_efficiency_below', '85.50')
        ->assertJsonPath('data.thresholds.approval_wait_days', 5)
        ->assertJsonPath('data.thresholds.control_difference_above', '100.00')
        ->assertJsonPath('data.thresholds.par30_rise_points', '1.00')
        ->assertJsonPath('data.can_update', true)
        ->assertJsonPath('data.updated_by.id', $editor->id)
        ->assertJsonPath('data.updated_by.name', $editor->full_name);

    $this->actingAs(ebcStaff(['dashboard:analytics']))->getJson('/api/dashboard/settings')
        ->assertJsonPath('data.thresholds.collection_efficiency_below', '85.50')
        ->assertJsonPath('data.defaults.collection_efficiency_below', '90.00');

    expect(DashboardSetting::query()->count())->toBe(1);
});

it('refuses a change without dashboard settings and all branches, in words, and changes nothing', function (array $permissions) {
    $user = ebcStaff($permissions);
    $before = AuditLog::query()->max('id') ?? 0;

    $response = $this->actingAs($user)
        ->putJson('/api/dashboard/settings', ['approval_wait_days' => 9])
        ->assertForbidden();

    expect($response->json('message'))->toBe('Dashboard alert thresholds apply to every branch, so changing them needs the dashboard settings permission and access to all branches.')
        ->and(DashboardSetting::current()->approval_wait_days)->toBe(3)
        ->and(AuditLog::query()->where('id', '>', $before)->count())->toBe(0);
})->with([
    'analytics only' => [['dashboard:analytics']],
    'settings without all branches' => [['dashboard:analytics', 'dashboard:settings']],
    'all branches without settings' => [['dashboard:analytics', BranchAccess::VIEW_ALL]],
]);

it('checks percentages, points, days and pesos in range, in words', function (array $body, string $field) {
    $response = $this->actingAs(ebcEditor())->putJson('/api/dashboard/settings', $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect($response->json("errors.{$field}.0"))->not->toContain('_')
        ->and(DashboardSetting::current()->only(array_keys(EBC_DEFAULTS)))->toBe(EBC_DEFAULTS);
})->with([
    'percent below 0' => [['collection_efficiency_below' => -1], 'collection_efficiency_below'],
    'percent above 100' => [['overdue_share_above' => 100.01], 'overdue_share_above'],
    'percent with three decimals' => [['collection_efficiency_below' => '89.995'], 'collection_efficiency_below'],
    'percent not a number' => [['overdue_share_above' => 'five'], 'overdue_share_above'],
    'percent empty' => [['collection_efficiency_below' => null], 'collection_efficiency_below'],
    'points above 100' => [['par30_rise_points' => 101], 'par30_rise_points'],
    'points below 0' => [['par30_rise_points' => -0.5], 'par30_rise_points'],
    'compare days 0' => [['par30_compare_days' => 0], 'par30_compare_days'],
    'compare days above a year' => [['par30_compare_days' => 366], 'par30_compare_days'],
    'approval days 0' => [['approval_wait_days' => 0], 'approval_wait_days'],
    'approval days a fraction' => [['approval_wait_days' => 2.5], 'approval_wait_days'],
    'release days above a year' => [['release_wait_days' => 366], 'release_wait_days'],
    'release days not a number' => [['release_wait_days' => 'two'], 'release_wait_days'],
    'pesos below 0' => [['control_difference_above' => -1], 'control_difference_above'],
    'pesos with three decimals' => [['control_difference_above' => '0.001'], 'control_difference_above'],
    'pesos above the cap' => [['control_difference_above' => '1000000.01'], 'control_difference_above'],
    'a list' => [['approval_wait_days' => [3]], 'approval_wait_days'],
    'days true' => [['approval_wait_days' => true], 'approval_wait_days'],
    'compare days true' => [['par30_compare_days' => true], 'par30_compare_days'],
    'release days false' => [['release_wait_days' => false], 'release_wait_days'],
]);

it('stores each value in one form, whatever form it was sent in, and audits it that way', function () {
    $editor = ebcEditor();

    $this->actingAs($editor)->putJson('/api/dashboard/settings', [
        'collection_efficiency_below' => '085',
        'overdue_share_above' => '.5',
        'par30_rise_points' => 2,
        'control_difference_above' => '0007.1',
        'approval_wait_days' => '5',
    ])->assertOk()
        ->assertJsonPath('data.thresholds.collection_efficiency_below', '85.00')
        ->assertJsonPath('data.thresholds.overdue_share_above', '0.50')
        ->assertJsonPath('data.thresholds.par30_rise_points', '2.00')
        ->assertJsonPath('data.thresholds.control_difference_above', '7.10')
        ->assertJsonPath('data.thresholds.approval_wait_days', 5);

    $row = AuditLog::query()->where('auditable_type', DashboardSetting::class)->latest('id')->firstOrFail();

    expect($row->new_values)->toMatchArray([
        'collection_efficiency_below' => '85.00',
        'overdue_share_above' => '0.50',
        'par30_rise_points' => '2.00',
        'control_difference_above' => '7.10',
        'approval_wait_days' => 5,
    ]);

    $changes = ebcAuditChanges($row);

    expect($changes['collection_efficiency_below'])->toBe(['old' => '90%', 'new' => '85%'])
        ->and($changes['overdue_share_above'])->toBe(['old' => '5%', 'new' => '0.5%'])
        ->and($changes['control_difference_above'])->toBe(['old' => '₱0.00', 'new' => '₱7.10']);
});

it('stores a signed zero as plain zero, and audits it without a minus', function (mixed $zero) {
    $this->actingAs(ebcEditor())->putJson('/api/dashboard/settings', [
        'overdue_share_above' => $zero,
        'control_difference_above' => $zero,
        'par30_rise_points' => $zero,
    ])->assertOk()
        ->assertJsonPath('data.thresholds.overdue_share_above', '0.00')
        ->assertJsonPath('data.thresholds.control_difference_above', '0.00')
        ->assertJsonPath('data.thresholds.par30_rise_points', '0.00');

    $row = AuditLog::query()->where('auditable_type', DashboardSetting::class)->latest('id')->firstOrFail();

    expect($row->new_values['overdue_share_above'])->toBe('0.00')
        ->and(ebcAuditChanges($row)['overdue_share_above'])->toBe(['old' => '5%', 'new' => '0%'])
        ->and($row->description)->not->toContain('-');
})->with([
    'minus zero' => ['-0'],
    'minus point zero' => ['-.0'],
    'JSON minus zero' => [-0.0],
]);

it('refuses a caller whose only role is switched off, on read and save', function () {
    $user = ebcStaff(['dashboard:analytics', 'dashboard:settings', BranchAccess::VIEW_ALL]);
    DB::table('roles')->whereIn('id', $user->roles->pluck('id'))->update(['is_active' => false]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user = User::findOrFail($user->id);

    $this->actingAs($user)->getJson('/api/dashboard/settings')->assertForbidden();
    $this->actingAs($user)->putJson('/api/dashboard/settings', ['approval_wait_days' => 9])->assertForbidden();

    expect(DashboardSetting::current()->approval_wait_days)->toBe(3);
});

it('lets super_admin read and save', function () {
    $superAdmin = User::where('username', 'super_admin')->firstOrFail();

    $this->actingAs($superAdmin)->getJson('/api/dashboard/settings')
        ->assertOk()
        ->assertJsonPath('data.can_update', true);

    $this->actingAs($superAdmin)->putJson('/api/dashboard/settings', ['approval_wait_days' => 4])
        ->assertOk()
        ->assertJsonPath('data.thresholds.approval_wait_days', 4)
        ->assertJsonPath('data.updated_by.id', $superAdmin->id);
});

it('ignores the id, who saved it and the timestamps in the body', function () {
    $editor = ebcEditor();
    $someoneElse = ebcStaff([]);

    $this->actingAs($editor)->putJson('/api/dashboard/settings', [
        'id' => 99,
        'updated_by' => $someoneElse->id,
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
        'approval_wait_days' => 6,
    ])->assertOk()
        ->assertJsonPath('data.updated_by.id', $editor->id);

    $row = DashboardSetting::current();

    expect(DashboardSetting::query()->pluck('id')->all())->toBe([DashboardSetting::ROW_ID])
        ->and($row->updated_by)->toBe($editor->id)
        ->and($row->approval_wait_days)->toBe(6)
        ->and($row->created_at->year)->not->toBe(2020)
        ->and($row->updated_at->year)->not->toBe(2020);
});

it('answers 403, not 422, to a caller without permission who sends bad data', function () {
    $this->actingAs(ebcStaff(['dashboard:analytics']))
        ->putJson('/api/dashboard/settings', ['collection_efficiency_below' => 'lots', 'approval_wait_days' => -4])
        ->assertForbidden()
        ->assertJsonMissingPath('errors');
});

it('only takes the thresholds from mass assignment', function () {
    expect((new DashboardSetting)->getFillable())->toBe(array_keys(EBC_DEFAULTS));
});

it('accepts the edges of each range', function () {
    $this->actingAs(ebcEditor())->putJson('/api/dashboard/settings', [
        'par30_rise_points' => 0,
        'par30_compare_days' => 365,
        'collection_efficiency_below' => 100,
        'overdue_share_above' => '0.01',
        'approval_wait_days' => 1,
        'release_wait_days' => 365,
        'control_difference_above' => '1000000.00',
    ])->assertOk()
        ->assertJsonPath('data.thresholds.par30_rise_points', '0.00')
        ->assertJsonPath('data.thresholds.collection_efficiency_below', '100.00')
        ->assertJsonPath('data.thresholds.overdue_share_above', '0.01')
        ->assertJsonPath('data.thresholds.control_difference_above', '1000000.00');
});

it('writes one audit row per change, in words, and none for a save that changes nothing', function () {
    $editor = ebcEditor();
    $before = AuditLog::query()->max('id') ?? 0;

    $this->actingAs($editor)->putJson('/api/dashboard/settings', [
        'par30_rise_points' => '1.5',
        'collection_efficiency_below' => 85,
        'overdue_share_above' => '5.00',
        'approval_wait_days' => 5,
        'control_difference_above' => 250,
    ])->assertOk();

    $this->actingAs($editor)->putJson('/api/dashboard/settings', [
        'par30_rise_points' => '1.50',
        'collection_efficiency_below' => '85.00',
        'approval_wait_days' => 5,
    ])->assertOk();

    $this->actingAs($editor)->putJson('/api/dashboard/settings', [])->assertOk();

    $rows = AuditLog::query()->where('id', '>', $before)->get();
    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect($row->action)->toBe('updated')
        ->and($row->auditable_type)->toBe(DashboardSetting::class)
        ->and($row->user_id)->toBe($editor->id)
        ->and($row->description)->toBe(
            'Dashboard alert thresholds changed: PAR 30 rise from 1 point to 1.5 points; '
            .'Collection Efficiency threshold from 90% to 85%; '
            .'Approval waiting time from 3 days to 5 days; '
            .'Control difference tolerance from ₱0.00 to ₱250.00'
        )
        ->and(AuditLogModules::for($row->action, $row->auditable_type))->toBe(AuditLogModules::UNMAPPED);

    $changes = ebcAuditChanges($row);

    expect($changes['par30_rise_points'])->toBe(['old' => '1 point', 'new' => '1.5 points'])
        ->and($changes['collection_efficiency_below'])->toBe(['old' => '90%', 'new' => '85%'])
        ->and($changes['approval_wait_days'])->toBe(['old' => '3 days', 'new' => '5 days'])
        ->and($changes['control_difference_above'])->toBe(['old' => '₱0.00', 'new' => '₱250.00'])
        ->and($changes)->not->toHaveKey('overdue_share_above');

    $target = $this->actingAs(User::where('username', 'super_admin')->firstOrFail())->getJson("/api/audit-logs/{$row->id}")->json('data.target.label');
    expect($target)->toBe('Dashboard alert thresholds #'.$row->auditable_id);
});

it('reads a single day and a single point in the singular', function () {
    $this->actingAs(ebcEditor())->putJson('/api/dashboard/settings', ['release_wait_days' => 1, 'par30_rise_points' => 2])->assertOk();

    $row = AuditLog::query()->latest('id')->firstOrFail();

    expect($row->description)->toBe('Dashboard alert thresholds changed: PAR 30 rise from 1 point to 2 points; Release waiting time from 2 days to 1 day');
});

it('keeps its wording neutral', function () {
    $editor = ebcEditor();
    $this->actingAs($editor)->putJson('/api/dashboard/settings', EBC_DEFAULTS + ['approval_wait_days' => 4])->assertOk();

    $text = Str::lower(json_encode([
        $this->actingAs($editor)->getJson('/api/dashboard/settings')->json(),
        AuditLog::query()->latest('id')->value('description'),
        $this->actingAs(ebcStaff([]))->getJson('/api/dashboard/settings')->json('message'),
        $this->actingAs(ebcStaff(['dashboard:analytics']))->putJson('/api/dashboard/settings', [])->json('message'),
    ], JSON_UNESCAPED_UNICODE));

    foreach (['fraud', 'suspicious', 'theft', 'stolen', 'violation'] as $word) {
        expect($text)->not->toContain($word);
    }
});

it('brings the defaults back if a database lost the row', function () {
    DashboardSetting::query()->delete();

    expect(DashboardSetting::current()->only(array_keys(EBC_DEFAULTS)))->toBe(EBC_DEFAULTS)
        ->and(DashboardSetting::current()->id)->toBe(DashboardSetting::ROW_ID)
        ->and(DashboardSetting::query()->count())->toBe(1);

    $this->actingAs(ebcEditor())->putJson('/api/dashboard/settings', ['approval_wait_days' => 7])->assertOk();

    expect(DashboardSetting::query()->count())->toBe(1)
        ->and(DashboardSetting::current()->approval_wait_days)->toBe(7);
});

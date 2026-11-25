<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\LoanProduct;
use App\Models\OriginationApplication;
use App\Models\OriginationLink;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/*
 * LO-B1: public application links (origination decisions 16 and 17). A link
 * has a random token, a branch, its products, an expiry and an on/off switch,
 * and counts visits, starts and submissions. Branch staff manage only their
 * own branches' links; a link with no branch needs `branches:view_all`.
 * Another branch's link answers 404. The address is built from
 * LOAN_ORIGINATION_PUBLIC_URL, only when that is one of CORS_ALLOWED_ORIGINS.
 */
uses(TestCase::class);

beforeEach(function () {
    $this->main = Branch::query()->firstOrFail();
    $this->other = Branch::factory()->create(['name' => 'North Branch', 'code' => 'NB']);
    $this->product = LoanProduct::factory()->create();
    config([
        'loan_origination.public_url' => 'https://apply.example.test/',
        'cors.allowed_origins' => ['https://app.example.test', 'https://apply.example.test'],
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function loLinkUser(?Branch $branch, string ...$permissions): User
{
    $role = Role::query()->create(['name' => 'spec-lo-l:'.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    return tap(User::factory()->create(['branch_id' => $branch?->id]), fn (User $u) => $u->assignRole($role));
}

function loLinkInput(array $overrides = []): array
{
    return [
        'name' => 'Facebook page', 'active' => true, 'branch_id' => null, 'loan_product_ids' => [],
        'expires_at' => null, 'display_name' => 'Binhs Coop', 'contact_phone' => '09171234567', 'contact_email' => 'loans@example.test',
        ...$overrides,
    ];
}

it('creates a link with a random token and its public address, audited', function () {
    $user = loLinkUser($this->main, 'loan_origination:settings');
    $before = AuditLog::query()->max('id') ?? 0;

    $response = $this->actingAs($user)->postJson('/api/loan-origination/links', loLinkInput([
        'branch_id' => $this->main->id, 'loan_product_ids' => [$this->product->id], 'expires_at' => now()->addMonth()->toIso8601String(),
    ]))->assertCreated()
        ->assertJsonPath('data.name', 'Facebook page')
        ->assertJsonPath('data.branch_id', $this->main->id)
        ->assertJsonPath('data.branch_name', $this->main->name)
        ->assertJsonPath('data.loan_product_ids', [$this->product->id])
        ->assertJsonPath('data.loan_product_names', [$this->product->name])
        ->assertJsonPath('data.visits', 0)
        ->assertJsonPath('data.started', 0)
        ->assertJsonPath('data.submitted', 0)
        ->assertJsonPath('data.display_logo_url', null)
        ->assertJsonPath('data.is_test', false)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.status_label', 'Active');

    $token = $response->json('data.token');
    expect($token)->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($response->json('data.url'))->toBe("https://apply.example.test/apply/{$token}");

    expect(AuditLog::query()->where('id', '>', $before)->where('auditable_type', OriginationLink::class)->value('action'))->toBe('created');
});

it('gives a link no address while the public address isn\'t one of the allowed origins', function () {
    config(['loan_origination.public_url' => 'https://evil.example.test']);
    $user = loLinkUser($this->main, 'loan_origination:settings', 'branches:view_all');

    $this->actingAs($user)->postJson('/api/loan-origination/links', loLinkInput())
        ->assertCreated()
        ->assertJsonPath('data.url', null);
});

it('lets a branch user manage only their own branches\' links, never one with no branch', function () {
    $user = loLinkUser($this->main, 'loan_origination:settings');

    $this->actingAs($user)->postJson('/api/loan-origination/links', loLinkInput(['branch_id' => null]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.branch_id.0', 'Choose one of your branches. A link for every branch needs access to all branches.');

    $this->actingAs($user)->postJson('/api/loan-origination/links', loLinkInput(['branch_id' => $this->other->id]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.branch_id.0', 'Choose one of your branches. A link for every branch needs access to all branches.');

    $theirs = OriginationLink::query()->create(['name' => 'North', 'token' => str_repeat('a', 32), 'branch_id' => $this->other->id]);
    $everyone = OriginationLink::query()->create(['name' => 'All', 'token' => str_repeat('b', 32)]);
    $mine = OriginationLink::query()->create(['name' => 'Main', 'token' => str_repeat('c', 32), 'branch_id' => $this->main->id]);

    $this->actingAs($user)->getJson('/api/loan-origination/links')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);

    foreach ([$theirs, $everyone] as $hidden) {
        $this->actingAs($user)->getJson("/api/loan-origination/links/{$hidden->id}")->assertNotFound();
        $this->actingAs($user)->patchJson("/api/loan-origination/links/{$hidden->id}", ['active' => false])->assertNotFound();
    }

    $this->actingAs($user)->patchJson("/api/loan-origination/links/{$mine->id}", ['branch_id' => $this->other->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('branch_id');
});

it('lets head office make a link for every branch and see every link', function () {
    $user = loLinkUser($this->main, 'loan_origination:settings', 'branches:view_all');

    $this->actingAs($user)->postJson('/api/loan-origination/links', loLinkInput(['branch_id' => null]))->assertCreated()->assertJsonPath('data.branch_id', null);
    OriginationLink::query()->create(['name' => 'North', 'token' => str_repeat('a', 32), 'branch_id' => $this->other->id]);

    $this->actingAs($user)->getJson('/api/loan-origination/links')->assertOk()->assertJsonCount(2, 'data');
});

it('updates a link\'s products, switch and expiry, and never its token or counters', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-19 09:00', 'Asia/Manila'));
    $admin = User::query()->where('username', 'super_admin')->firstOrFail();
    $link = OriginationLink::query()->create(['name' => 'Fair', 'token' => str_repeat('d', 32), 'branch_id' => $this->main->id, 'visits' => 7]);
    $second = LoanProduct::factory()->create();

    $this->actingAs($admin)->patchJson("/api/loan-origination/links/{$link->id}", [
        'active' => false, 'loan_product_ids' => [$second->id], 'token' => 'mine', 'visits' => 0, 'is_test' => true,
    ])->assertOk()
        ->assertJsonPath('data.active', false)
        ->assertJsonPath('data.status', 'disabled')
        ->assertJsonPath('data.status_label', 'Turned off')
        ->assertJsonPath('data.is_test', true)
        ->assertJsonPath('data.loan_product_ids', [$second->id])
        ->assertJsonPath('data.token', str_repeat('d', 32))
        ->assertJsonPath('data.visits', 7);

    $link->forceFill(['active' => true, 'expires_at' => Carbon::parse('2026-11-18 09:00', 'Asia/Manila')])->save();

    $this->actingAs($admin)->getJson("/api/loan-origination/links/{$link->id}")
        ->assertJsonPath('data.status', 'expired')
        ->assertJsonPath('data.status_label', 'Expired');
});

/*
 * Finish run round 5: once a link has applications, its Test setting stays.
 * Each application keeps the kind of link it was made on (its own `is_test`),
 * which the dashboard and Track go by. The link's other settings still change.
 */
it('keeps a link\'s Test setting once it has applications, and takes its other edits', function (bool $isTest) {
    $admin = User::query()->where('username', 'super_admin')->firstOrFail();
    $link = OriginationLink::query()->create(['name' => 'Fair', 'token' => str_repeat('e', 32), 'branch_id' => $this->main->id, 'is_test' => $isTest]);
    OriginationApplication::query()->create(['link_id' => $link->id, 'is_test' => $isTest, 'mobile_hash' => OriginationApplication::mobileHash('09171234567')]);

    $this->actingAs($admin)->getJson("/api/loan-origination/links/{$link->id}")->assertOk()->assertJsonPath('data.can_change_test', false);
    $this->actingAs($admin)->getJson('/api/loan-origination/links')->assertOk()
        ->assertJsonPath('data.0.id', $link->id)
        ->assertJsonPath('data.0.can_change_test', false);

    $this->actingAs($admin)->patchJson("/api/loan-origination/links/{$link->id}", ['is_test' => ! $isTest, 'name' => 'Renamed'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.is_test', ['This link already has applications, so it can\'t be changed to or from a test link. Make a new link instead.']);

    expect($link->fresh())->is_test->toBe($isTest)->name->toBe('Fair');

    // The form sends the whole link back: its own Test setting saves with the rest.
    $this->actingAs($admin)->patchJson("/api/loan-origination/links/{$link->id}", ['is_test' => $isTest, 'name' => 'Renamed', 'active' => false])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.active', false)
        ->assertJsonPath('data.is_test', $isTest)
        ->assertJsonPath('data.can_change_test', false);
})->with(['a real link' => false, 'a test link' => true]);

it('lets a link change its Test setting while it has no applications of its own', function () {
    $admin = User::query()->where('username', 'super_admin')->firstOrFail();
    $created = $this->actingAs($admin)->postJson('/api/loan-origination/links', loLinkInput(['branch_id' => $this->main->id]))
        ->assertCreated()
        ->assertJsonPath('data.can_change_test', true);
    $other = OriginationLink::query()->create(['name' => 'Other', 'token' => str_repeat('f', 32), 'branch_id' => $this->main->id]);
    OriginationApplication::query()->create(['link_id' => $other->id, 'mobile_hash' => OriginationApplication::mobileHash('09171234567')]);
    $id = $created->json('data.id');

    $this->actingAs($admin)->patchJson("/api/loan-origination/links/{$id}", ['is_test' => true])
        ->assertOk()
        ->assertJsonPath('data.is_test', true)
        ->assertJsonPath('data.can_change_test', true);

    $this->actingAs($admin)->getJson('/api/loan-origination/links')->assertOk()
        ->assertJsonPath('data.0.id', $other->id)
        ->assertJsonPath('data.0.can_change_test', false)
        ->assertJsonPath('data.1.id', $id)
        ->assertJsonPath('data.1.can_change_test', true);
});

it('refuses a link with a past expiry, an unknown product or a bad contact, in words', function () {
    $admin = User::query()->where('username', 'super_admin')->firstOrFail();

    $this->actingAs($admin)->postJson('/api/loan-origination/links', loLinkInput([
        'expires_at' => now()->subDay()->toIso8601String(), 'loan_product_ids' => [999999], 'contact_email' => 'not-an-email', 'name' => '',
    ]))->assertUnprocessable()
        ->assertJsonPath('errors.expires_at.0', 'The expiry must be in the future.')
        ->assertJsonValidationErrors(['loan_product_ids.0' => 'Choose loan products that exist.'])
        ->assertJsonPath('errors.contact_email.0', 'Enter a valid contact email.')
        ->assertJsonPath('errors.name.0', 'Give the link a name.');
});

it('needs the settings permission to manage links and a view permission to list them', function () {
    $this->actingAs(loLinkUser($this->main, 'loan_origination:view'))
        ->postJson('/api/loan-origination/links', loLinkInput(['branch_id' => $this->main->id]))
        ->assertForbidden()
        ->assertJsonPath('message', 'Managing application links needs the online applications settings permission.');

    $this->actingAs(loLinkUser($this->main, 'loans:view'))->getJson('/api/loan-origination/links')
        ->assertForbidden()
        ->assertJsonPath('message', 'You don\'t have access to online application settings.');

    $this->actingAs(loLinkUser($this->main, 'loan_origination:view'))->getJson('/api/loan-origination/links')->assertOk();
});

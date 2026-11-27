<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\OriginationNotice;
use App\Models\Role;
use App\Models\User;
use App\Services\LoanOrigination\OriginationNotices;
use Tests\TestCase;

/*
 * LO-B1: the versioned notices an applicant accepts (origination decision 9):
 * the privacy notice, terms, declaration and credit authorization. Each
 * version is published once and never edited; the latest of each type is the
 * current one. `final` is true only for the owner's approved wording.
 */
uses(TestCase::class);

function loNoticeUser(string ...$permissions): User
{
    $role = Role::query()->create(['name' => 'spec-lo-n:'.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    return tap(User::factory()->create(['branch_id' => Branch::query()->firstOrFail()->id]), fn (User $u) => $u->assignRole($role));
}

it('publishes a version, audited, and reads it as the current one with its text hash', function () {
    $user = loNoticeUser('loan_origination:settings', 'branches:view_all');
    $before = AuditLog::query()->max('id') ?? 0;

    $this->actingAs($user)->postJson('/api/loan-origination/notices', [
        'type' => 'privacy_notice', 'version' => '2026.1', 'title' => 'Privacy Notice', 'body' => 'We keep your data safe.', 'final' => false,
    ])->assertCreated()
        ->assertJsonPath('data.type', 'privacy_notice')
        ->assertJsonPath('data.type_label', 'Privacy notice')
        ->assertJsonPath('data.version', '2026.1')
        ->assertJsonPath('data.final', false)
        ->assertJsonPath('data.body_hash', hash('sha256', 'We keep your data safe.'))
        ->assertJsonPath('data.published_by.id', $user->id);

    $this->actingAs($user)->getJson('/api/loan-origination/notices')
        ->assertOk()
        ->assertJsonPath('data.current.privacy_notice.version', '2026.1')
        ->assertJsonPath('data.current.terms', null)
        ->assertJsonPath('data.missing', ['Terms and conditions', 'Declaration'])
        ->assertJsonCount(1, 'data.versions');

    expect(AuditLog::query()->where('id', '>', $before)->where('auditable_type', OriginationNotice::class)->value('action'))->toBe('created');
});

it('makes the newest version of a type the current one, and keeps every version', function () {
    $notices = app(OriginationNotices::class);
    $user = User::query()->where('username', 'super_admin')->firstOrFail();

    $notices->publish('terms', '1', 'Old terms', null, false, $user);
    $notices->publish('terms', '2', 'New terms', null, true, $user);

    expect($notices->current('terms')->version)->toBe('2')
        ->and($notices->current('terms')->is_final)->toBeTrue()
        ->and(OriginationNotice::query()->where('type', 'terms')->count())->toBe(2);
});

it('refuses a version a type already has, and an unknown type, in words', function () {
    $user = loNoticeUser('loan_origination:settings', 'branches:view_all');
    $this->actingAs($user)->postJson('/api/loan-origination/notices', ['type' => 'terms', 'version' => '1', 'body' => 'A', 'final' => false])->assertCreated();

    $this->actingAs($user)->postJson('/api/loan-origination/notices', ['type' => 'terms', 'version' => '1', 'body' => 'B', 'final' => false])
        ->assertUnprocessable()
        ->assertJsonPath('errors.version.0', 'Terms and conditions already has a version 1. Give the new text its own version.');

    $this->actingAs($user)->postJson('/api/loan-origination/notices', ['type' => 'cookie_policy', 'version' => '1', 'body' => 'B', 'final' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

it('needs the settings permission and all-branch access to publish, and a view permission to read', function () {
    $this->actingAs(loNoticeUser('loan_origination:settings'))
        ->postJson('/api/loan-origination/notices', ['type' => 'terms', 'version' => '1', 'body' => 'A', 'final' => false])
        ->assertForbidden()
        ->assertJsonPath('message', 'Online application notices apply to every branch, so publishing one needs the online applications settings permission and access to all branches.');

    $this->actingAs(loNoticeUser('loans:view'))->getJson('/api/loan-origination/notices')
        ->assertForbidden()
        ->assertJsonPath('message', 'You don\'t have access to online application settings.');

    $this->actingAs(loNoticeUser('loan_origination:view'))->getJson('/api/loan-origination/notices')->assertOk();
});

it('has no route that edits or removes a published notice', function () {
    $notice = app(OriginationNotices::class)->publish('declaration', '1', 'Text', null, false, User::query()->where('username', 'super_admin')->firstOrFail());
    $admin = User::query()->where('username', 'super_admin')->firstOrFail();

    $this->actingAs($admin)->putJson("/api/loan-origination/notices/{$notice->id}", ['body' => 'Changed'])->assertStatus(405);
    $this->actingAs($admin)->deleteJson("/api/loan-origination/notices/{$notice->id}")->assertStatus(405);
});

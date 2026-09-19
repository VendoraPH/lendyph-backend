<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A refused user-management request has to leave a trace.
 *
 * UserRouteEnumerationTest pins the other half of this: the five `{user}`
 * routes answer a caller without the endpoint's permission with the SAME 404 a
 * missing id produces, so a single probe learns nothing. That closed the
 * oracle and opened a blind spot — the refusal is thrown inside the
 * FormRequest, upstream of every AuditLogService call site in the controllers
 * and upstream of the Auditable trait, whose hooks are all MODEL events. A
 * refusal mutates no model, so twenty-five refused probes wrote zero
 * `audit_logs` rows and an id sweep looked exactly like no traffic at all.
 *
 * These specs are about the row: that it exists, that it names the actor and
 * the id they tried, that it does NOT name the target, and that failing to
 * write it cannot change the answer the caller gets.
 */
class UserRouteRefusalAuditTest extends TestCase
{
    use SetupLendyPH;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        // A collector: real, active, fully authenticated staff holding none of
        // users:view / users:create / users:update / users:delete /
        // users:reset_password.
        $this->outsider = User::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);
        $this->outsider->syncRoles([Role::findByName('collector')]);
    }

    /**
     * Every route that binds `{user}`, with a payload its authorised caller
     * would accept. Mirrors UserRouteEnumerationTest's dataset on purpose: a
     * sixth `{user}` route is one new line in each file, and the two then
     * cross-check that masking and recording cover the same set.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function userRoutes(): array
    {
        return [
            'users.show' => ['getJson', '/api/users/{id}', []],
            'users.update' => ['putJson', '/api/users/{id}', ['first_name' => 'Renamed']],
            'users.deactivate' => ['patchJson', '/api/users/{id}/deactivate', []],
            'users.reactivate' => ['patchJson', '/api/users/{id}/reactivate', []],
            'users.reset-password' => ['postJson', '/api/users/{id}/reset-password', [
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ]],
        ];
    }

    private function target(string $status = 'active'): User
    {
        return User::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => $status,
        ]);
    }

    /**
     * @return Collection<int, AuditLog>
     */
    private function refusalRows()
    {
        return AuditLog::where('action', 'user_access_denied')->orderBy('id')->get();
    }

    public function test_every_refused_user_route_writes_exactly_one_audit_row(): void
    {
        foreach ($this->userRoutes() as $name => [$verb, $template, $payload]) {
            AuditLog::where('action', 'user_access_denied')->delete();

            // reactivate is the one route a live target makes a no-op for, so
            // it gets an inactive one — the refusal must be recorded for the
            // right reason, not because the request was pointless.
            $target = $this->target($name === 'users.reactivate' ? 'inactive' : 'active');

            $this->actingAs($this->outsider);

            $this->{$verb}(str_replace('{id}', (string) $target->id, $template), $payload)
                ->assertNotFound();

            $rows = $this->refusalRows();

            $this->assertCount(1, $rows, "{$name} did not write exactly one refusal row.");

            $row = $rows->first();

            $this->assertSame($this->outsider->id, $row->user_id, "{$name} did not name the actor.");
            $this->assertSame($target->id, $row->new_values['attempted_user_id'] ?? null, "{$name} did not record the attempted id.");
            $this->assertSame($name, $row->new_values['route'] ?? null, "{$name} recorded the wrong route name.");
            $this->assertNotNull($row->created_at);
        }
    }

    /**
     * The row is what makes a sweep visible: one actor, a run of ids, one
     * window. Nothing else in the application records these attempts, so if
     * the rows do not accumulate per probe there is no sweep to detect.
     */
    public function test_a_sweep_of_ids_accumulates_one_row_per_probe_under_one_actor(): void
    {
        $targets = User::factory()->count(5)->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->outsider);

        foreach ($targets as $target) {
            $this->getJson("/api/users/{$target->id}")->assertNotFound();
        }

        $rows = $this->refusalRows();

        $this->assertCount(5, $rows);
        $this->assertSame([$this->outsider->id], $rows->pluck('user_id')->unique()->all());
        $this->assertSame(
            $targets->pluck('id')->all(),
            $rows->map(fn (AuditLog $row) => $row->new_values['attempted_user_id'])->all(),
        );
    }

    /**
     * The row must not rebuild the oracle it records the closing of.
     *
     * `$this->route('user')` is already a bound User inside failedAuthorization(),
     * so passing it as `auditable` would cost nothing — and AuditLogController
     * loads the trail `with('auditable')` while AuditLogResource renders the
     * related model's `full_name`. Naming the target would print each refusal
     * as "User #7 — <that person's name>" to anyone holding `audit_logs:view`,
     * which is the same oracle upgraded from an id to a name.
     */
    public function test_the_refusal_row_does_not_name_the_target(): void
    {
        $target = $this->target();

        $this->actingAs($this->outsider);
        $this->getJson("/api/users/{$target->id}")->assertNotFound();

        $row = $this->refusalRows()->first();

        $this->assertNotNull($row);
        $this->assertNull($row->auditable_type);
        $this->assertNull($row->auditable_id);

        // Stated at the boundary as well as in the column, because the column
        // is only half of it: AuditLogController::index() loads the trail
        // `with('auditable')` and the resource resolves a label off it, so this
        // is what a reader of the audit trail would actually be served.
        $this->actingAs($this->admin);

        $entry = collect($this->getJson('/api/audit-logs?action=user_access_denied')->assertOk()->json('data'))
            ->firstWhere('id', $row->id);

        $this->assertNotNull($entry, 'The refusal row is not reachable through the audit endpoint at all.');
        $this->assertNull($entry['target'], 'The refusal row resolves a target label, which names the account that was probed.');
        $this->assertStringNotContainsString($target->first_name, json_encode($entry));
        $this->assertStringNotContainsString($target->last_name, json_encode($entry));
    }

    /**
     * A refusal row for every request would be noise, and noise is how a sweep
     * hides. Only refusals.
     */
    public function test_an_authorised_request_writes_no_refusal_row(): void
    {
        foreach ($this->userRoutes() as $name => [$verb, $template, $payload]) {
            $target = $this->target($name === 'users.reactivate' ? 'inactive' : 'active');

            $this->actingAs($this->admin);

            $this->{$verb}(str_replace('{id}', (string) $target->id, $template), $payload)
                ->assertOk();
        }

        $this->assertCount(0, $this->refusalRows());
    }

    /**
     * Characterisation, not a guarantee — read this before changing it.
     *
     * Route-model binding runs BEFORE authorisation, so a probe for an id that
     * does not exist 404s inside SubstituteBindings and never reaches a
     * FormRequest. No row is written, which means the PRESENCE of a row is
     * itself a statement that the id exists. That is a property of the
     * middleware order rather than of the log; it is only readable by someone
     * already holding `audit_logs:view`, and telling a defender which ids were
     * probed is the entire point of the row.
     *
     * If this ever fails, the binding order has moved and the trade-off above
     * has changed with it.
     */
    public function test_a_probe_for_an_id_that_does_not_exist_writes_nothing(): void
    {
        $this->actingAs($this->outsider);

        $this->getJson('/api/users/99999')->assertNotFound();

        $this->assertCount(0, $this->refusalRows());
    }

    /**
     * The record of the control is not allowed to become a way to break it.
     *
     * A locked table, a full disk or a schema mid-migration must still produce
     * the byte-identical 404 — anything else is a NEW oracle, and a worse one:
     * 500 for exactly the ids that exist, 404 for the ones that do not.
     */
    public function test_a_failing_audit_write_does_not_change_the_refusal(): void
    {
        config(['app.debug' => false]);

        $target = $this->target();

        $this->actingAs($this->outsider);

        $healthy = $this->getJson("/api/users/{$target->id}");

        AuditLog::creating(function (): void {
            throw new RuntimeException('audit_logs is unavailable');
        });

        $broken = $this->getJson("/api/users/{$target->id}");

        $this->assertSame(404, $broken->status());
        $this->assertSame($healthy->getContent(), $broken->getContent());

        // And still indistinguishable from a missing id while the log is down,
        // which is the assertion the status code alone would not make.
        $missing = $this->getJson('/api/users/99999');

        $this->assertSame(
            str_replace((string) $target->id, '{id}', $broken->getContent()),
            str_replace('99999', '{id}', $missing->getContent()),
        );
    }

    /**
     * A target refusal and a permission miss are different events, and the log
     * has to say which happened.
     *
     * They answer the SAME 404 on the wire on purpose — that is the control.
     * The distinction lives only in the audit trail, and it is the more useful
     * half: an ordinary permission miss is someone with the wrong role clicking
     * a button they can see, while a run of target refusals is somebody working
     * their way toward the platform account.
     *
     * This nearly did not exist. `denyAsMissingUser()` was added as a separate
     * throw site in the same release that added the recording, so the four
     * requests that call it recorded NOTHING — the higher-signal event was the
     * one with no trace.
     */
    public function test_a_target_refusal_is_recorded_under_its_own_action(): void
    {
        AuditLog::query()->delete();

        $superAdmin = User::where('username', 'super_admin')->firstOrFail();

        $admin = $this->target('active');
        $admin->syncRoles(['admin']);
        $this->actingAs($admin);

        // Holds users:update; may not use it against the platform account.
        $this->putJson("/api/users/{$superAdmin->id}", ['first_name' => 'Nope'])
            ->assertNotFound();

        // Holds nothing at all.
        $ordinary = $this->target('active');
        $this->actingAs($this->outsider);
        $this->putJson("/api/users/{$ordinary->id}", ['first_name' => 'Nope'])
            ->assertNotFound();

        $targetRefusals = AuditLog::where('action', 'user_target_refused')->get();
        $accessDenials = AuditLog::where('action', 'user_access_denied')->get();

        $this->assertCount(1, $targetRefusals, 'The super_admin refusal should record user_target_refused.');
        $this->assertCount(1, $accessDenials, 'The permission miss should record user_access_denied.');

        $this->assertSame($admin->id, $targetRefusals->first()->user_id);
        $this->assertSame($this->outsider->id, $accessDenials->first()->user_id);

        // Neither names the target — that would rebuild the oracle inside the
        // log, and upgrade it from an id to a name.
        $this->assertNull($targetRefusals->first()->auditable_type);
        $this->assertNull($accessDenials->first()->auditable_type);
    }
}

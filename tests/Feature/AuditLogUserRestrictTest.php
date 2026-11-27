<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Audit rows name the user who made each change. `audit_logs.user_id` was
 * `nullOnDelete`, so deleting a user would have blanked the author on every
 * row they wrote: the trail would keep the change but lose who made it, and
 * audit rows are never edited. The key now restricts the delete instead. No
 * delete path for users exists in the app; this keeps one from being added
 * without deciding what happens to the trail first.
 */
class AuditLogUserRestrictTest extends TestCase
{
    public function test_a_user_who_wrote_audit_rows_cannot_be_deleted_and_the_rows_keep_their_author(): void
    {
        $author = User::factory()->create();

        $this->actingAs($author);
        $other = User::factory()->create();
        $other->update(['first_name' => 'Renamed']);

        $rows = AuditLog::query()->where('user_id', $author->id)->pluck('id')->sort()->values()->all();
        $this->assertNotEmpty($rows, 'the author should have written audit rows');

        // Someone else deletes, so the delete's own audit row isn't the author's.
        $this->actingAs(User::where('username', 'super_admin')->firstOrFail());

        try {
            DB::transaction(fn () => $author->delete());
            $this->fail('deleting a user with audit rows should be refused');
        } catch (QueryException $e) {
            // 1451: a row in audit_logs still refers to this user.
            $this->assertSame(1451, $e->errorInfo[1] ?? null);
        }

        $this->assertDatabaseHas('users', ['id' => $author->id]);
        $this->assertSame(
            $rows,
            AuditLog::query()->where('user_id', $author->id)->pluck('id')->sort()->values()->all(),
        );
    }

    public function test_a_user_with_no_audit_rows_can_still_be_deleted(): void
    {
        $user = User::factory()->create();
        $this->assertSame(0, AuditLog::query()->where('user_id', $user->id)->count());

        $this->actingAs(User::where('username', 'super_admin')->firstOrFail());
        $user->delete();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}

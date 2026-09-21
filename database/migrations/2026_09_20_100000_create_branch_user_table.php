<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A user may be assigned to SEVERAL branches.
 *
 * `users.branch_id` stays exactly where it is this release and keeps carrying a
 * value — see UserResource and App\Http\Requests\Concerns\ResolvesBranchAssignment
 * for why the API emits and accepts both shapes for one release. Dropping the
 * column is a separate migration, once no signed-in session can still be
 * rehydrating the old `{branch: {...}}` payload out of localStorage.
 *
 * ## No timestamps, deliberately
 *
 * `co_maker_loan` — the only other pivot here — carries `timestamps()`, and this
 * one does not. Three reasons:
 *
 * 1. The backfill below cannot know WHEN an existing user was put in their
 *    branch. That fact is not recorded anywhere. A `created_at` would therefore
 *    read `now()` on every backfilled row across all five live databases, which
 *    is not a missing date, it is a wrong one — it would claim the whole
 *    organisation was assigned on migration day and any later "assigned since"
 *    display would be a fabrication.
 * 2. Nothing reads them. Branch assignment is display-only by design
 *    (tests/Feature/BranchAssignmentIsDisplayOnlyTest.php keeps it that way);
 *    no query orders, filters or reports on when an assignment was made. And
 *    `sync()` detaches and re-attaches rather than updating, so any timestamp
 *    here would be reset by edits that did not touch that particular row's
 *    branch.
 * 3. The history that IS wanted — who changed an assignment, from what, to what
 *    — belongs in `audit_logs`, where it has an actor and a before/after.
 *    UserController writes `branches_assigned` and `branches_changed` rows for
 *    exactly that, the same way it already does for the `role` pivot.
 *
 * The practical consequence, and the reason this is called out here rather than
 * in a commit message: a table with no DATETIME/TIMESTAMP column contributes no
 * rows to `information_schema`, so it needs no entry in
 * App\Services\TimezoneShift::COLUMNS and cannot trip
 * TimezoneShiftTest::test_the_column_map_matches_the_live_schema. If anyone
 * later adds `timestamps()` here, they MUST add
 * `'branch_user' => ['created_at', 'updated_at']` to that class's
 * EXCLUDED_COLUMNS (this table is created long after the 2026-08-06 UTC → Manila
 * cutover, so it can hold no pre-cutover row), or the ENTIRE suite fails.
 *
 * ## Why cascadeOnDelete on branch_id and not nullOnDelete
 *
 * `users.branch_id` is `nullOnDelete`, and the OBSERVABLE behaviour that buys is
 * "deleting a branch does not delete its staff, it leaves them without that
 * branch". `cascadeOnDelete` is what reproduces that behaviour on a pivot:
 * the assignment row disappears and the user survives with one fewer branch.
 * `nullOnDelete` here would reproduce the clause rather than the behaviour — it
 * would leave a row with a NULL `branch_id`, which is an assignment to nothing:
 * invisible to the `branches()` relation (the join drops it), uncleanable,
 * accumulating one per deleted branch per user, and outside the reach of the
 * unique index, since MySQL permits unlimited NULLs in a UNIQUE column. The
 * user-facing outcome is identical either way; only the leftovers differ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            // Both the correctness guarantee (a user cannot be assigned to the
            // same branch twice, so `sync()` and the backfill cannot double up)
            // and the index `branches()` reads through.
            $table->unique(['user_id', 'branch_id']);
        });

        $this->backfill();
    }

    /**
     * Give every user who has a `branch_id` the matching pivot row.
     *
     * Public, and separate from up(), so it can be exercised directly:
     * UserBranchAssignmentTest re-requires this file and calls this method
     * against rows it inserted by hand. up() cannot be re-run for that — the
     * table already exists — and a backfill nobody can run twice is a backfill
     * nobody can prove is idempotent.
     *
     * Idempotent two ways over, because five live databases will run this and
     * an operator re-running it must not be a failure mode:
     *
     *  - the anti-join means a second pass SELECTS nothing at all, and
     *  - `insertOrIgnore` means even a row that appeared between the select and
     *    the insert is skipped on the unique index rather than raising.
     *
     * `insertOrIgnore` does downgrade other write errors to warnings, which is
     * normally worth avoiding. It is safe here specifically because
     * `users.branch_id` is itself FK-constrained to `branches`, so the only
     * other error this insert could raise — a foreign key violation — cannot
     * occur by construction.
     *
     * Keyset chunking rather than offset chunking: this loop mutates the table
     * its own WHERE clause reads, so an OFFSET-based walk would skip rows as
     * earlier ones stopped matching. `chunkById` advances on the primary key
     * instead, which cannot drift.
     *
     * @return int pivot rows created
     */
    public function backfill(): int
    {
        $inserted = 0;

        DB::table('users')
            ->select('users.id', 'users.branch_id')
            ->whereNotNull('users.branch_id')
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('branch_user')
                ->whereColumn('branch_user.user_id', 'users.id')
                ->whereColumn('branch_user.branch_id', 'users.branch_id'))
            ->chunkById(1000, function ($users) use (&$inserted) {
                $inserted += DB::table('branch_user')->insertOrIgnore(
                    $users->map(fn ($user) => [
                        'user_id' => $user->id,
                        'branch_id' => $user->branch_id,
                    ])->all()
                );
            }, 'users.id', 'id');

        return $inserted;
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');
    }
};

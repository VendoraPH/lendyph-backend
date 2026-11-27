<?php

namespace App\Services\Branches;

use App\Models\Branch;
use App\Models\BranchClosure;
use App\Rules\BranchTakesNew;
use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PDOException;

/**
 * The branch row lock (finish run round 2, decision 4): a write that puts
 * something new in a branch reads the branch again inside its own
 * transaction, under a lock that a closure holds too, so a closure can never
 * slip in between the request's check and the write.
 *
 * It covers saving or assigning a user's branches and primary branch, manual
 * journals (create, edit, post, reverse), expenses (record and pay), fund
 * transfers and reconciliation actions. A closed branch, or one waiting to
 * close (a pending BranchClosure), takes none of them: 422 in words. A
 * suspended branch keeps its existing rules, which the FormRequests apply.
 *
 * ## The lock order
 *
 * 1. The branch rows, FOR SHARE, in ONE id-ordered statement: the write's
 *    first statement, before any other lock and any plain read, so the
 *    transaction's snapshot is taken after the wait.
 * 2. The branches' pending closure rows, FOR SHARE (a current read).
 * 3. Whatever the write locks next: the allowance account, the journal, the
 *    expense, the periods (JournalPoster, ExpenseRecorder).
 *
 * BranchStatus takes the branch row FOR UPDATE as its first statement on
 * every closure step (request, approve, reject, cancel), then the closure
 * row. So a closure waits for the writes holding the branch, and a write
 * waits for a closure in flight and then reads its result. FOR SHARE, not
 * FOR UPDATE, because inserting a journal line, an expense or an allowance
 * posting already takes a shared lock on the branch row through its foreign
 * key: an exclusive lock here would make two writes in one branch queue on
 * each other, and would deadlock a manual journal on 1200 (branch, then the
 * allowance account) with an allowance run (the allowance account, then the
 * branch through the foreign key). Shared locks only conflict with the
 * closure's exclusive one. InterbranchTransfers takes the same shared locks.
 *
 * A deadlock or lock wait timeout is still possible against writers outside
 * this order; transaction() answers it with a 409 and nothing saved.
 */
final class BranchRowLock
{
    use DetectsConcurrencyErrors;

    public const CONFLICT = 'Another change in this branch was saved at the same time. Reload and try again.';

    /** `%1$s` the branch's audit name, `%2$s` the consequence. */
    public const CLOSING = 'Branch %1$s is waiting to close, so %2$s.';

    /**
     * Run `$write` in a transaction that first takes the branch locks.
     *
     * @template T
     *
     * @param  array<string, int|list<int|null>|null>  $fields  the error field => the branch id or ids it places the write in
     * @param  array<string, string>  $consequences  the refusal's consequence by field, when not "nothing new can be added there"
     * @param  Closure(): T  $write
     * @return T
     */
    public static function write(array $fields, Closure $write, array $consequences = []): mixed
    {
        return self::transaction(function () use ($fields, $write, $consequences): mixed {
            self::lock($fields, $consequences);

            return $write();
        });
    }

    /**
     * A transaction whose deadlock or lock wait timeout answers 409 in words
     * with nothing saved. Meant as the outermost transaction of the request.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws HttpResponseException 409 on a concurrency error
     */
    public static function transaction(Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (PDOException $e) {
            if (! (new self)->causedByConcurrencyError($e)) {
                throw $e;
            }

            throw new HttpResponseException(response()->json(['message' => self::CONFLICT], 409));
        }
    }

    /**
     * Take the locks, inside the caller's transaction, and refuse a closed or
     * closing branch. A branch that doesn't exist is left to the request's
     * `exists` rule and the foreign key.
     *
     * @param  array<string, int|list<int|null>|null>  $fields
     * @param  array<string, string>  $consequences
     *
     * @throws ValidationException
     */
    public static function lock(array $fields, array $consequences = []): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The branch row lock is held until a transaction ends; take it inside one.');
        }

        $byField = array_map(
            fn (int|array|null $ids): array => array_values(array_unique(array_map('intval', array_filter((array) $ids, fn (mixed $id): bool => $id !== null)))),
            $fields,
        );
        $ids = array_values(array_unique(array_merge(...array_values($byField) ?: [[]])));
        sort($ids);

        if ($ids === []) {
            return;
        }

        $branches = Branch::query()->whereIn('id', $ids)->orderBy('id')->sharedLock()->get(['id', 'name', 'code', 'status'])->keyBy('id');
        $closing = BranchClosure::query()->whereIn('branch_id', $ids)->where('status', BranchClosure::STATUS_PENDING)
            ->orderBy('branch_id')->sharedLock()->pluck('branch_id')->map(fn (mixed $id): int => (int) $id)->all();

        foreach ($byField as $field => $fieldIds) {
            $consequence = $consequences[$field] ?? BranchTakesNew::NOTHING_NEW;

            foreach ($fieldIds as $id) {
                $branch = $branches->get($id);

                if ($branch === null) {
                    continue;
                }

                if ($branch->status === Branch::STATUS_CLOSED) {
                    throw ValidationException::withMessages([$field => [BranchWriteGuard::refusal($branch, $consequence)]]);
                }

                if (in_array($id, $closing, true)) {
                    throw ValidationException::withMessages([$field => [self::closingRefusal($branch, $consequence)]]);
                }
            }
        }
    }

    /**
     * `Branch "Far Branch" (FAR) is waiting to close, so nothing new can be
     * added there.` The closed form is BranchWriteGuard::refusal().
     */
    public static function closingRefusal(Branch $branch, string $consequence = BranchTakesNew::NOTHING_NEW): string
    {
        return sprintf(self::CLOSING, $branch->auditName(), $consequence);
    }
}

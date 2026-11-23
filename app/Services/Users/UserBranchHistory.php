<?php

namespace App\Services\Users;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A user's branch history (Phase 6 MB-BE6): their branch assignments and
 * primary-branch changes, read from the audit rows already written about
 * them, newest first and in words. Nothing is stored for it.
 *
 * Rows read:
 *   - `created` and `updated` rows that carry `branch_id` (the primary
 *     branch; null reads "Organisation-wide");
 *   - `branches_assigned` and `branches_changed` (the assignment, including
 *     the system correction that put branchless staff in the main branch).
 *
 * Branches read by their current name; a branch that no longer exists reads
 * "Branch #id". A caller without `branches:view_all` reads only their own
 * branches by name; any other branch reads "Another branch" (permission
 * review), so the history never names a branch they can't see.
 */
final class UserBranchHistory
{
    private const PRIMARY_ACTIONS = ['created', 'updated'];

    private const ASSIGNMENT_ACTIONS = ['branches_assigned', 'branches_changed'];

    public const HIDDEN_BRANCH = 'Another branch';

    public function for(User $user, User $caller, int $perPage): LengthAwarePaginator
    {
        $page = AuditLog::query()
            ->with('user:id,first_name,last_name')
            ->where('auditable_type', $user->getMorphClass())
            ->where('auditable_id', $user->getKey())
            ->where(fn (Builder $rows) => $rows
                ->whereIn('action', self::ASSIGNMENT_ACTIONS)
                ->orWhere(fn (Builder $primary) => $primary
                    ->whereIn('action', self::PRIMARY_ACTIONS)
                    ->whereRaw("JSON_CONTAINS_PATH(new_values, 'one', '$.branch_id')")))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(max($perPage, 1), 100));

        $names = $this->branchNames($page->getCollection(), app(BranchAccess::class)->for($caller));

        $page->setCollection($page->getCollection()->map(fn (AuditLog $row): array => $this->entry($row, $names)));

        return $page;
    }

    /**
     * @param  Collection<int, string>  $names
     * @return array<string, mixed>
     */
    private function entry(AuditLog $row, Collection $names): array
    {
        $old = $row->old_values ?? [];
        $new = $row->new_values ?? [];

        if (in_array($row->action, self::PRIMARY_ACTIONS, true)) {
            $to = $this->primaryWords($new['branch_id'] ?? null, $names);
            $from = $row->action === 'created' ? null : $this->primaryWords($old['branch_id'] ?? null, $names);

            return $this->shape($row, 'primary', $from, $to, $from === null
                ? "Primary branch set to {$to}."
                : "Primary branch changed from {$from} to {$to}.");
        }

        $to = $this->listWords($new['branch_ids'] ?? [], $names);
        $from = $this->listWords($old['branch_ids'] ?? [], $names);

        return $this->shape($row, 'branches', $from, $to, $row->action === 'branches_assigned'
            ? 'Assigned to '.$this->joined($to).'.'
            : 'Branches changed from '.$this->joined($from).' to '.$this->joined($to).'.');
    }

    /**
     * @param  string|list<string>|null  $from
     * @param  string|list<string>  $to
     * @return array<string, mixed>
     */
    private function shape(AuditLog $row, string $kind, string|array|null $from, string|array $to, string $description): array
    {
        return [
            'id' => $row->id,
            'kind' => $kind,
            'from' => $from,
            'to' => $to,
            'description' => $description,
            'by' => $row->user?->full_name ?? ($row->user_id === null ? 'System' : "User #{$row->user_id}"),
            'at' => $row->created_at,
        ];
    }

    /**
     * @param  Collection<int, string>  $names
     */
    private function primaryWords(mixed $branchId, Collection $names): string
    {
        return is_numeric($branchId)
            ? $names->get((int) $branchId, 'Branch #'.(int) $branchId)
            : 'Organisation-wide';
    }

    /**
     * @param  Collection<int, string>  $names
     * @return list<string>
     */
    private function listWords(mixed $ids, Collection $names): array
    {
        $ids = is_array($ids) ? array_values(array_filter($ids, 'is_numeric')) : [];
        sort($ids);

        return array_map(fn (mixed $id): string => $names->get((int) $id, 'Branch #'.(int) $id), $ids);
    }

    /**
     * @param  list<string>  $names
     */
    private function joined(array $names): string
    {
        return $names === [] ? 'none' : implode(', ', $names);
    }

    /**
     * The names the caller may read, keyed by id: every branch for a caller
     * who sees all, else only their own; the rest read "Another branch".
     *
     * @param  Collection<int, AuditLog>  $rows
     * @param  list<int>|null  $visible  the caller's branches, null for all
     * @return Collection<int, string>
     */
    private function branchNames(Collection $rows, ?array $visible): Collection
    {
        $ids = $rows->flatMap(fn (AuditLog $row): array => [
            $row->old_values['branch_id'] ?? null,
            $row->new_values['branch_id'] ?? null,
            ...(is_array($row->old_values['branch_ids'] ?? null) ? $row->old_values['branch_ids'] : []),
            ...(is_array($row->new_values['branch_ids'] ?? null) ? $row->new_values['branch_ids'] : []),
        ])->filter(fn (mixed $id): bool => is_numeric($id))->map(fn (mixed $id): int => (int) $id)->unique()->values();

        $names = $ids->isEmpty()
            ? collect()
            : Branch::query()->whereIn('id', $ids)->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(int) $id => (string) $name]);

        return $visible === null
            ? $names
            : $ids->mapWithKeys(fn (int $id): array => [$id => in_array($id, $visible, true) ? $names->get($id, 'Branch #'.$id) : self::HIDDEN_BRANCH]);
    }
}

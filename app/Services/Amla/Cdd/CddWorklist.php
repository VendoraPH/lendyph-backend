<?php

namespace App\Services\Amla\Cdd;

use App\Http\Resources\Amla\AmlaCddPresenter;
use App\Models\AmlaProfile;
use App\Models\Borrower;
use App\Models\GCashNonMember;
use App\Models\User;
use App\Services\LikePattern;
use Illuminate\Support\Collection;

/**
 * The CDD worklist (task §10, report 5's source): every member in the
 * caller's branches (current and former members, never pending or rejected
 * registrations) and, for head-office users only, every walk-in, each with
 * its CDD status, risk level, next review and how much is missing. Ordered by
 * name. The status is worked out on read (CddState), so a status filter reads
 * every subject in scope before it pages; a page without one reads only its
 * own rows.
 */
final class CddWorklist
{
    private const CHUNK = 200;

    public function __construct(
        private readonly CddSubjects $subjects,
        private readonly CddState $state,
    ) {}

    /**
     * @param  array{type?: string, status?: string, search?: string, branch_id?: int, page?: int, per_page?: int}  $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(User $user, array $filters): array
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $keys = $this->keys($user, $filters);

        if (isset($filters['status'])) {
            $rows = [];

            foreach ($keys->chunk(self::CHUNK) as $chunk) {
                foreach ($this->rowsFor($chunk) as $row) {
                    if ($row['cdd_status'] === $filters['status']) {
                        $rows[] = $row;
                    }
                }
            }

            return ['rows' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'total' => count($rows), 'page' => $page, 'per_page' => $perPage];
        }

        return [
            'rows' => $this->rowsFor($keys->slice(($page - 1) * $perPage, $perPage)),
            'total' => $keys->count(),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * The subjects in scope as [type, id], ordered by name.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{0: string, 1: int, 2: string}>
     */
    private function keys(User $user, array $filters): Collection
    {
        $type = $filters['type'] ?? null;
        $search = filled($filters['search'] ?? null) ? (string) $filters['search'] : null;
        $keys = collect();

        if ($type !== AmlaProfile::WALK_IN) {
            Borrower::query()->members()->visibleTo($user)
                ->when(isset($filters['branch_id']), fn ($query) => $query->forBranch((int) $filters['branch_id']))
                ->when($search !== null, fn ($query) => $query->search($search, false))
                ->select(['id', 'first_name', 'middle_name', 'last_name', 'suffix'])
                ->get()
                ->each(fn (Borrower $member) => $keys->push([AmlaProfile::MEMBER, (int) $member->id, mb_strtolower(trim($member->last_name.' '.$member->first_name))]));
        }

        if ($type !== AmlaProfile::MEMBER && ! isset($filters['branch_id']) && $this->subjects->seesWalkIns($user)) {
            GCashNonMember::query()
                ->when($search !== null, fn ($query) => $query->where('full_name', 'like', LikePattern::contains($search)))
                ->select(['id', 'full_name'])
                ->get()
                ->each(fn (GCashNonMember $walkIn) => $keys->push([AmlaProfile::WALK_IN, (int) $walkIn->id, mb_strtolower((string) $walkIn->full_name)]));
        }

        return $keys->sortBy(fn (array $key) => $key[2].'#'.$key[0].'#'.str_pad((string) $key[1], 20, '0', STR_PAD_LEFT))->values();
    }

    /**
     * @param  Collection<int, array{0: string, 1: int, 2: string}>  $keys
     * @return list<array<string, mixed>>
     */
    private function rowsFor(Collection $keys): array
    {
        $memberIds = $keys->where(0, AmlaProfile::MEMBER)->pluck(1)->all();
        $walkInIds = $keys->where(0, AmlaProfile::WALK_IN)->pluck(1)->all();

        $members = Borrower::query()->with('branch')
            ->withCount(['documents as valid_id_documents_count' => fn ($query) => $query->where('type', 'valid_id')->whereNotNull('id_number')])
            ->whereIn('id', $memberIds)->get()->keyBy('id');
        $walkIns = GCashNonMember::query()->whereIn('id', $walkInIds)->get()->keyBy('id');
        $profiles = AmlaProfile::query()
            ->where(fn ($query) => $query->whereIn('borrower_id', $memberIds)->orWhereIn('gcash_non_member_id', $walkInIds))
            ->get();
        $memberProfiles = $profiles->whereNotNull('borrower_id')->keyBy('borrower_id');
        $walkInProfiles = $profiles->whereNotNull('gcash_non_member_id')->keyBy('gcash_non_member_id');

        $rows = [];

        foreach ($keys as [$type, $id]) {
            $subject = $type === AmlaProfile::MEMBER ? $members->get($id) : $walkIns->get($id);

            if ($subject === null) {
                continue;
            }

            $profile = $type === AmlaProfile::MEMBER ? $memberProfiles->get($id) : $walkInProfiles->get($id);
            $rows[] = AmlaCddPresenter::row($subject, $profile, $this->state->of($subject, $profile));
        }

        return $rows;
    }
}

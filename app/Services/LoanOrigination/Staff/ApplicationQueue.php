<?php

namespace App\Services\LoanOrigination\Staff;

use App\Models\OriginationApplication;
use App\Models\User;
use App\Services\LikePattern;
use App\Support\PhilippineMobile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The staff applications list (LO-B3): the caller's branches only, filtered,
 * sorted and paged in SQL, with `meta.stats` (per-status counts and the
 * total, over every filter except the status) worked out in one grouped
 * query.
 *
 * Search matches the reference and the applicant's name. A typed mobile
 * number also matches, through the keyed mobile hash (never a LIKE on the
 * encrypted column), and only for callers who may see contacts
 * (`borrowers:view`), so the search can't be used to test whether a number
 * applied.
 */
final class ApplicationQueue
{
    public const SORTS = ['submitted_at', 'started_at', 'updated_at', 'requested_amount', 'applicant_name', 'reference_number'];

    /**
     * @param  array<string, mixed>  $filters  validated
     */
    public function page(User $viewer, array $filters, int $perPage): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? null, self::SORTS, true) ? $filters['sort'] : 'updated_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $this->filtered($viewer, $filters)
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->where('status', $filters['status']))
            ->with(['branch:id,name', 'loanProduct:id,name', 'assignedOfficer:id,first_name,last_name', 'latestAssessment'])
            ->orderBy($sort, $direction)
            ->orderBy('id', $direction)
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function stats(User $viewer, array $filters): array
    {
        $counts = $this->filtered($viewer, $filters)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $stats = [];

        foreach (OriginationApplication::STATUSES as $status) {
            $stats[$status] = (int) ($counts[$status] ?? 0);
        }

        $stats['total'] = array_sum($stats);

        return $stats;
    }

    /**
     * Every filter except the status.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<OriginationApplication>
     */
    private function filtered(User $viewer, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $mobileHash = $search !== '' && StaffAccess::canSeeMembers($viewer) && PhilippineMobile::normalise($search) !== null
            ? OriginationApplication::mobileHash($search)
            : null;

        return OriginationApplication::query()
            ->visibleTo($viewer)
            ->when(filled($filters['branch_id'] ?? null), fn (Builder $q) => $q->where('branch_id', (int) $filters['branch_id']))
            ->when(filled($filters['loan_product_id'] ?? null), fn (Builder $q) => $q->where('loan_product_id', (int) $filters['loan_product_id']))
            ->when(filled($filters['officer_id'] ?? null), fn (Builder $q) => $q->where('assigned_officer_id', (int) $filters['officer_id']))
            ->when(($filters['assigned'] ?? null) === 'me', fn (Builder $q) => $q->where('assigned_officer_id', $viewer->id))
            ->when(($filters['assigned'] ?? null) === 'none', fn (Builder $q) => $q->whereNull('assigned_officer_id'))
            ->when(array_key_exists('is_test', $filters) && $filters['is_test'] !== null, fn (Builder $q) => $q->where('is_test', (bool) $filters['is_test']))
            ->when(filled($filters['date_from'] ?? null), fn (Builder $q) => $q->where('started_at', '>=', Carbon::parse($filters['date_from'])->startOfDay()))
            ->when(filled($filters['date_to'] ?? null), fn (Builder $q) => $q->where('started_at', '<', Carbon::parse($filters['date_to'])->addDay()->startOfDay()))
            ->when($search !== '', function (Builder $q) use ($search, $mobileHash) {
                $like = LikePattern::contains($search);

                $q->where(fn (Builder $match) => $match
                    ->where('reference_number', 'like', $like)
                    ->orWhere('applicant_name', 'like', $like)
                    ->when($mobileHash !== null, fn (Builder $m) => $m->orWhere('mobile_hash', $mobileHash)));
            });
    }
}

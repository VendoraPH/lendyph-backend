<?php

namespace App\Http\Resources;

use App\Services\BranchAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A branch: its profile, status (active, suspended or closed; `is_active`
 * is true only when active), main-office flag and, when asked for, its
 * figures (BranchFigures; null without `loans:view`). The CDA authority
 * fields are "to verify" until counsel confirms what a branch must keep.
 * `closure_pending` goes to status managers only (see toArray()).
 */
class BranchResource extends JsonResource
{
    private bool $includesFigures = false;

    /** @var array<string, int|float>|null */
    private ?array $figures = null;

    /**
     * @param  array<string, int|float>|null  $figures  null when the caller may not see them
     */
    public function withFigures(?array $figures): static
    {
        $this->includesFigures = true;
        $this->figures = $figures;

        return $this;
    }

    private function managesStatuses(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && $user->can('branches:manage') && $user->can(BranchAccess::VIEW_ALL);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'contact_number' => $this->contact_number,
            'email' => $this->email,
            'manager' => $this->whenLoaded('manager', fn () => $this->manager === null ? null : [
                'id' => $this->manager->id,
                'full_name' => $this->manager->full_name,
            ]),
            'opened_on' => $this->opened_on?->toDateString(),
            'closed_on' => $this->closed_on?->toDateString(),
            'status' => $this->status,
            'is_active' => $this->is_active,
            'is_main_office' => $this->is_main_office,
            'cda_authority_number' => $this->cda_authority_number,
            'cda_authority_issued_on' => $this->cda_authority_issued_on?->toDateString(),
            'cda_authority_to_verify' => true,
            'users_count' => $this->whenCounted('users'),
            // A closure request is waiting: for callers who may act on it
            // (`branches:manage` plus `branches:view_all`), when the query
            // loaded it (Branch::withClosurePending()); left out otherwise.
            'closure_pending' => $this->when(
                $this->resource->hasAttribute('closure_pending') && $this->managesStatuses($request),
                fn (): bool => (bool) $this->resource->getAttribute('closure_pending'),
            ),
            'figures' => $this->when($this->includesFigures, fn () => $this->figures),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

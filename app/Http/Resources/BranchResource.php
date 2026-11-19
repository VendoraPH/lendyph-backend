<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A branch: its profile, status (active, suspended or closed; `is_active`
 * is true only when active), main-office flag and, when asked for, its
 * figures (BranchFigures; null without `loans:view`). The CDA authority
 * fields are "to verify" until counsel confirms what a branch must keep.
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
            'figures' => $this->when($this->includesFigures, fn () => $this->figures),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

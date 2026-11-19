<?php

namespace App\Http\Resources\LoanOrigination;

use App\Models\LoanProduct;
use App\Models\OriginationLink;
use App\Services\LoanOrigination\OriginationSwitch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A public application link, as the frontend's ApplicationLink. `url` is
 * built by the server from LOAN_ORIGINATION_PUBLIC_URL, or null while that
 * isn't usable. Per-link logos are later (v2), so `display_logo_url` is
 * always null.
 *
 * @mixin OriginationLink
 */
class OriginationLinkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OriginationLink $link */
        $link = $this->resource;
        $products = $link->relationLoaded('loanProducts') ? $link->loanProducts : $link->loanProducts()->get();
        $status = $link->status();

        return [
            'id' => $link->id,
            'name' => $link->name,
            'token' => $link->token,
            'url' => app(OriginationSwitch::class)->linkUrl($link->token),
            'active' => $link->active,
            'status' => $status,
            'status_label' => OriginationLink::STATUS_LABELS[$status],
            'is_test' => $link->is_test,
            'branch_id' => $link->branch_id,
            'branch_name' => $link->branch?->name,
            'loan_product_ids' => $products->map(fn (LoanProduct $p) => $p->id)->values()->all(),
            'loan_product_names' => $products->map(fn (LoanProduct $p) => $p->name)->values()->all(),
            'expires_at' => $link->expires_at,
            'display_name' => $link->display_name,
            'display_logo_url' => null,
            'contact_phone' => $link->contact_phone,
            'contact_email' => $link->contact_email,
            'visits' => $link->visits,
            'started' => $link->started,
            'submitted' => $link->submitted,
            'created_at' => $link->created_at,
            'updated_at' => $link->updated_at,
        ];
    }
}

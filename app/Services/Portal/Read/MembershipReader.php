<?php

namespace App\Services\Portal\Read;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\PortalUser;
use App\Models\SavingsAccount;
use App\Services\Portal\Auth\MemberNumber;
use App\Services\Portal\PortalSettings;

/**
 * The membership card (portal design §3, §13.2): who the member is to the
 * cooperative and factual statuses only. There is no overall "good standing"
 * label and no requirements checklist until the owner defines them.
 *
 * `standing` lists, for each part of the portal that is switched on:
 * - loans: `defaulted`, `past_due`, `current` (released or ongoing, nothing
 *   late) or `none`;
 * - savings: `active`, `dormant` or `none`;
 * - share capital: `paid_up` with its amount, or `none`.
 */
final class MembershipReader
{
    public function __construct(
        private readonly PortalSettings $settings,
        private readonly ShareCapitalReader $shareCapital,
    ) {}

    /** @return array<string, mixed> */
    public function membership(PortalUser $portalUser): array
    {
        /** @var Borrower $member */
        $member = $portalUser->borrower()->with('branch:id,name,address,contact_number')->firstOrFail();
        $standing = [];

        if ($this->settings->featureOn('loans')) {
            $status = $this->loanStanding($member);
            $standing[] = ['key' => 'loans', 'label' => 'Loans', 'status' => $status, 'amount' => null];
        }

        if ($this->settings->featureOn('savings')) {
            $status = $this->savingsStanding($member);
            $standing[] = ['key' => 'savings', 'label' => 'Savings', 'status' => $status, 'amount' => null];
        }

        if ($this->settings->featureOn('share_capital')) {
            $paidUp = $this->shareCapital->paidUp();
            $standing[] = [
                'key' => 'share_capital',
                'label' => 'Share capital',
                'status' => Pesos::centavos($paidUp) > 0 ? 'paid_up' : 'none',
                'amount' => $paidUp,
            ];
        }

        return [
            'member_number' => MemberNumber::of($member),
            'member_type' => $member->member_type,
            'status' => $member->status,
            'membership_date' => $member->membership_date?->toDateString(),
            'branch' => [
                'name' => $member->branch?->name,
                'address' => $member->branch?->address,
                'contact_number' => $member->branch?->contact_number,
            ],
            'standing' => $standing,
            'support_contact' => $this->settings->current()->support_contact,
        ];
    }

    private function loanStanding(Borrower $member): string
    {
        $loans = Loan::query()
            ->where('borrower_id', $member->getKey())
            ->whereIn('status', Loan::COLLECTIBLE_STATUSES)
            ->with('amortizationSchedules')
            ->get();

        $statuses = $loans->map(fn (Loan $loan) => $loan->displayStatus());

        return match (true) {
            $statuses->contains('defaulted') => 'defaulted',
            $statuses->contains(Loan::VIRTUAL_STATUS_PAST_DUE) => 'past_due',
            $statuses->isNotEmpty() => 'current',
            default => 'none',
        };
    }

    private function savingsStanding(Borrower $member): string
    {
        $statuses = SavingsAccount::query()->where('borrower_id', $member->getKey())->pluck('status');

        return match (true) {
            $statuses->contains(SavingsAccount::STATUS_ACTIVE) => 'active',
            $statuses->contains(SavingsAccount::STATUS_DORMANT) => 'dormant',
            default => 'none',
        };
    }
}

<?php

namespace App\Services\Portal\Read;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\PortalAnnouncement;
use App\Models\PortalRequest;
use App\Models\PortalSetting;
use App\Models\PortalUser;
use App\Models\Repayment;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\ShareCapitalLedger;
use App\Services\BorrowerLoanSummaryService;
use App\Services\Portal\Auth\MemberNumber;
use App\Services\Portal\PortalSettings;
use App\Services\RepaymentService;
use App\Services\Savings\TimeDeposits\Settlement;
use Illuminate\Support\Collection;

/**
 * The portal dashboard (portal design §3), one call. Each block is null when
 * its feature is switched off or the cooperative left its card out
 * (`dashboard_cards`). Every figure comes from the readers the detail pages
 * use, and the few totals here (savings, time deposits, the next payment)
 * are added in whole centavos on the server.
 *
 * The query count depends on the member's number of loans, accounts and
 * products, never on how many transactions they hold (TASK 88).
 */
final class DashboardReader
{
    public const RECENT_ACTIVITY = 5;

    public const ANNOUNCEMENTS = 3;

    public function __construct(
        private readonly PortalSettings $settings,
        private readonly BorrowerLoanSummaryService $loanSummaries,
        private readonly RepaymentService $repayments,
        private readonly ShareCapitalReader $shareCapital,
        private readonly SavingsReader $savings,
        private readonly DocumentReader $documents,
        private readonly NotificationReader $notifications,
        private readonly AnnouncementAudience $announcements,
    ) {}

    /** @return array<string, mixed> */
    public function dashboard(PortalUser $portalUser): array
    {
        /** @var Borrower $member */
        $member = $portalUser->borrower()->with('branch:id,name')->firstOrFail();
        $unread = $this->notifications->unreadCount($portalUser);

        return [
            'member' => [
                'first_name' => $member->first_name,
                'member_number' => MemberNumber::of($member),
                'member_since' => $member->membership_date?->toDateString(),
                'status' => $member->status,
                'branch_name' => $member->branch?->name,
            ],
            'share_capital' => $this->card('share_capital', 'share_capital') ? ['paid_up' => $this->shareCapital->paidUp()] : null,
            'savings' => $this->card('savings', 'savings') ? $this->savingsCard() : null,
            'time_deposits' => $this->card('time_deposits', 'time_deposits') ? $this->timeDepositsCard() : null,
            'loans' => $this->card('loans', 'loans') ? $this->loansCard($member) : null,
            'next_payment' => $this->card('next_payment', 'loans') ? $this->nextPayment($member) : null,
            'pending_requests' => PortalRequest::query()
                ->where('borrower_id', $member->getKey())
                ->whereIn('status', PortalRequest::OPEN_STATUSES)
                ->count(),
            'unread' => [
                'notifications' => $unread['notifications'],
                'announcements' => $this->settings->featureOn('announcements') ? $unread['announcements'] : 0,
            ],
            'documents' => $this->settings->featureOn('documents') ? $this->documents->count() : null,
            'announcements' => $this->card('announcements', 'announcements') ? $this->latestAnnouncements($portalUser) : null,
            'recent_activity' => $this->cardOn('recent_activity') ? $this->recentActivity($member) : null,
        ];
    }

    private function card(string $card, string $feature): bool
    {
        return $this->cardOn($card) && $this->settings->featureOn($feature);
    }

    private function cardOn(string $card): bool
    {
        $cards = $this->settings->current()->dashboard_cards ?? PortalSetting::DASHBOARD_CARDS;

        return in_array($card, $cards, true);
    }

    /** @return array{total: string, available: string, accounts: int} the open accounts */
    private function savingsCard(): array
    {
        $open = $this->savings->accounts()->where('status', '!=', SavingsAccount::STATUS_CLOSED)->get();
        $rows = $this->savings->present($open);

        return [
            'total' => Pesos::fromCentavos(array_sum(array_map(fn (array $r) => Pesos::centavos($r['ledger_balance']), $rows))),
            'available' => Pesos::fromCentavos(array_sum(array_map(fn (array $r) => Pesos::centavos($r['available_balance']), $rows))),
            'accounts' => count($rows),
        ];
    }

    /** @return array{total: string, count: int} the placements not yet settled */
    private function timeDepositsCard(): array
    {
        $principals = $this->savings->timeDepositQuery()
            ->whereIn('status', Settlement::OPEN_STATUSES)
            ->pluck('principal');

        return [
            'total' => Pesos::fromCentavos($principals->sum(fn ($p) => Pesos::centavos((string) $p))),
            'count' => $principals->count(),
        ];
    }

    /** @return array{total_outstanding: string, active_count: int, overdue_amount: string} */
    private function loansCard(Borrower $member): array
    {
        $summary = $this->loanSummaries->forMember($member);

        return [
            'total_outstanding' => Pesos::of($summary['total_outstanding']),
            'active_count' => $summary['current_loans'],
            'overdue_amount' => Pesos::of($summary['overdue_amount']),
        ];
    }

    /**
     * The earliest next due date over the member's collectible loans, what
     * falls due on it (each loan's summary `next_due_amount`, added in
     * centavos) and on how many loans; null when nothing is due.
     *
     * @return array{due_date: string, amount: string, loans_count: int}|null
     */
    private function nextPayment(Borrower $member): ?array
    {
        $due = Loan::query()
            ->where('borrower_id', $member->getKey())
            ->whereIn('status', Loan::COLLECTIBLE_STATUSES)
            ->get()
            ->map(fn (Loan $loan) => $this->repayments->getLoanSummary($loan))
            ->filter(fn (array $summary) => $summary['next_due_date'] !== null);

        if ($due->isEmpty()) {
            return null;
        }

        $date = $due->min('next_due_date');
        $onDate = $due->where('next_due_date', $date);

        return [
            'due_date' => $date,
            'amount' => Pesos::fromCentavos($onDate->sum(fn (array $s) => Pesos::centavos($s['next_due_amount']))),
            'loans_count' => $onDate->count(),
        ];
    }

    /** @return list<array{id: int, title: string, publish_at: mixed, pinned: bool}> pinned first, then newest */
    private function latestAnnouncements(PortalUser $portalUser): array
    {
        return $this->announcements->visibleTo($portalUser)
            ->orderByDesc('pinned')
            ->orderByDesc('publish_at')
            ->orderByDesc('id')
            ->limit(self::ANNOUNCEMENTS)
            ->get(['id', 'title', 'publish_at', 'pinned'])
            ->map(fn (PortalAnnouncement $a): array => [
                'id' => $a->id,
                'title' => $a->title,
                'publish_at' => $a->publish_at,
                'pinned' => (bool) $a->pinned,
            ])
            ->values()
            ->all();
    }

    /**
     * The member's latest money movements: loan payments, savings rows and
     * share capital entries, newest first. Money in is positive, money out of
     * savings or share capital negative.
     *
     * @return list<array{date: string, kind: string, description: string, amount: string}>
     */
    private function recentActivity(Borrower $member): array
    {
        $items = new Collection;

        if ($this->settings->featureOn('loans')) {
            Repayment::query()
                ->whereIn('loan_id', Loan::query()->where('borrower_id', $member->getKey())->whereIn('status', Loan::EVER_RELEASED_STATUSES)->select('id'))
                ->where('status', 'posted')
                ->with('loan:id,loan_account_number')
                ->orderByDesc('payment_date')->orderByDesc('id')
                ->limit(self::RECENT_ACTIVITY)
                ->get()
                ->each(fn (Repayment $r) => $items->push([
                    'sort' => $r->payment_date->toDateString().'#1#'.str_pad((string) $r->id, 12, '0', STR_PAD_LEFT),
                    'date' => $r->payment_date->toDateString(),
                    'kind' => 'loan_payment',
                    'description' => 'Loan payment, '.$r->loan?->loan_account_number,
                    'amount' => Pesos::of((string) $r->getRawOriginal('amount_paid')),
                ]));
        }

        if ($this->settings->featureOn('savings')) {
            SavingsTransaction::query()
                ->whereIn('account_id', $this->savings->accounts()->select('id'))
                ->with('account:id,account_number')
                ->orderByDesc('date')->orderByDesc('id')
                ->limit(self::RECENT_ACTIVITY)
                ->get()
                ->each(fn (SavingsTransaction $t) => $items->push([
                    'sort' => $t->date->toDateString().'#2#'.str_pad((string) $t->id, 12, '0', STR_PAD_LEFT),
                    'date' => $t->date->toDateString(),
                    'kind' => 'savings',
                    'description' => MemberWords::of(MemberWords::SAVINGS_TYPE, $t->type).', '.$t->account?->account_number,
                    'amount' => $this->signed((string) $t->getRawOriginal('credit'), (string) $t->getRawOriginal('debit')),
                ]));
        }

        if ($this->settings->featureOn('share_capital')) {
            ShareCapitalLedger::query()
                ->where('borrower_id', $member->getKey())
                ->orderByDesc('date')->orderByDesc('id')
                ->limit(self::RECENT_ACTIVITY)
                ->get()
                ->each(fn (ShareCapitalLedger $e) => $items->push([
                    'sort' => $e->date->toDateString().'#0#'.str_pad((string) $e->id, 12, '0', STR_PAD_LEFT),
                    'date' => $e->date->toDateString(),
                    'kind' => 'share_capital',
                    'description' => 'Share capital',
                    'amount' => $this->signed((string) $e->getRawOriginal('credit'), (string) $e->getRawOriginal('debit')),
                ]));
        }

        return $items->sortByDesc('sort')
            ->take(self::RECENT_ACTIVITY)
            ->map(fn (array $item) => ['date' => $item['date'], 'kind' => $item['kind'], 'description' => $item['description'], 'amount' => $item['amount']])
            ->values()
            ->all();
    }

    private function signed(string $credit, string $debit): string
    {
        return Pesos::fromCentavos(Pesos::centavos($credit) - Pesos::centavos($debit));
    }
}

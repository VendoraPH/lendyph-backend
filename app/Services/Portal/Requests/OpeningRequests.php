<?php

namespace App\Services\Portal\Requests;

use App\Http\Requests\Savings\SavingsRules;
use App\Models\Borrower;
use App\Models\PortalRequest;
use App\Models\PortalRequestEvent;
use App\Models\PortalUser;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Savings\Api\AccountOpener;
use App\Services\Savings\Api\Refuse;
use App\Services\Savings\SavingsMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * A member's request for a new savings account (portal design §6): on an
 * active regular product open for portal requests, with an opening deposit
 * of at least the product's minimum, when the member holds no open account
 * on it. One open request per member and product (`o:{member}:{product}`).
 * Staff approval opens it through the AccountOpener with the deposit received
 * at the counter, so the opening deposit posts its journal as usual.
 */
final class OpeningRequests
{
    public function __construct(
        private readonly AccountOpener $opener,
        private readonly RequestTimeline $timeline,
        private readonly RequestNumbers $numbers,
    ) {}

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'details.product_id' => ['required', 'integer'],
            'details.initial_deposit' => ['required', ...SavingsRules::money('opening deposit')],
            'details.terms_accepted' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...SavingsRules::messages(['details.initial_deposit' => 'opening deposit']),
            'details.product_id.required' => 'Choose the savings product.',
            'details.product_id.integer' => 'Choose one of the savings products offered in the portal.',
            'details.initial_deposit.required' => 'Enter the opening deposit you will bring to the branch.',
            'details.terms_accepted.accepted' => 'Accept the product\'s terms to send the request.',
        ];
    }

    /** Products a member may ask for in the portal. */
    public function openProducts(): Builder
    {
        return SavingsProduct::query()
            ->where('kind', SavingsProduct::KIND_REGULAR)
            ->where('is_active', true)
            ->where('portal_account_requests', true);
    }

    /**
     * @param  array<string, mixed>  $details  validated
     *
     * @throws ValidationException
     */
    public function submit(PortalUser $portalUser, array $details): PortalRequest
    {
        $member = $portalUser->borrower;
        $product = $this->openProducts()->whereKey((int) $details['product_id'])->first();

        if ($product === null) {
            Refuse::field('details.product_id', 'That savings product isn\'t offered in the portal. Choose another, or visit your branch.');
        }

        if (SavingsAccount::query()->where('open_key', SavingsAccount::openKey($member->id, $product->id))->exists()) {
            Refuse::field('details.product_id', "You already have an open {$product->name} account.");
        }

        $deposit = SavingsMath::centavos($details['initial_deposit']);
        $minimum = SavingsMath::centavos($product->minimum_opening_balance);

        if ($deposit < $minimum) {
            Refuse::field('details.initial_deposit', "{$product->name} needs an opening deposit of at least ".Refuse::peso($minimum).'.');
        }

        return RequestDesk::insertOpen([
            'reference' => $this->numbers->next(),
            'portal_user_id' => $portalUser->id,
            'borrower_id' => $member->id,
            'branch_id' => $member->branch_id,
            'type' => PortalRequest::TYPE_SAVINGS_OPENING,
            'status' => PortalRequest::STATUS_SUBMITTED,
            'details' => ['terms_accepted' => true],
            'amount' => SavingsMath::pesos($deposit),
            'savings_product_id' => $product->id,
            'expires_on' => RequestExpiry::otherRequests(),
            'live_key' => "o:{$member->id}:{$product->id}",
        ], "You already have an open request for a {$product->name} account.");
    }

    /**
     * Open the account as `$staff`, inside the savings IdempotentWrite's
     * transaction: the member row first (the opener's lock step), then the
     * request, then the opener with the deposit received at the counter.
     *
     * @param  array{date: string, method?: string|null, reference?: string|null}  $deposit
     *
     * @throws ValidationException
     */
    public function approve(PortalRequest $request, array $deposit, User $staff, ?string $message = null): PortalRequest
    {
        Borrower::query()->whereKey($request->borrower_id)->lockForUpdate()->firstOrFail();
        $locked = RequestDesk::lockOpen($request->id, [PortalRequest::STATUS_SUBMITTED, PortalRequest::STATUS_UNDER_REVIEW, PortalRequest::STATUS_NEED_INFO]);

        $account = $this->opener->open([
            'borrower_id' => $locked->borrower_id,
            'product_id' => $locked->savings_product_id,
            'opened_at' => $deposit['date'],
            'initial_deposit' => (string) $locked->amount,
            'payment_method' => $deposit['method'] ?? null,
            'reference' => $deposit['reference'] ?? null,
            'remarks' => "Opened on member portal request {$locked->reference}",
        ], $staff);

        return $this->timeline->move($locked, PortalRequest::STATUS_COMPLETED, PortalRequestEvent::ACTOR_STAFF, $staff->id, $message, [
            'opened_account_id' => $account->id,
            'decided_by' => $staff->id,
            'decided_at' => now(),
        ]);
    }
}

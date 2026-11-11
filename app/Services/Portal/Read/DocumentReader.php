<?php

namespace App\Services\Portal\Read;

use App\Models\Borrower;
use App\Models\Document;
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Models\TimeDeposit;
use App\Services\Portal\MemberScope;
use App\Services\Portal\PortalSettings;
use App\Services\ValidIdService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The documents a member may see (portal design §1):
 *
 * - `files`: stored files staff shared ("Share with member",
 *   `documents.portal_shared_at`) that belong to the member or to one of the
 *   member's own ever-released loans. Never a co-maker's file and never a
 *   valid ID, even if one were marked shared. Streamed by the signed-in
 *   download route, never by a signed or public link (Document::url is never
 *   read here).
 * - `generated`: the PDFs the server makes for the member, by kind, with the
 *   record they are for. The screen picks the download route by `kind`.
 */
final class DocumentReader
{
    public function __construct(
        private readonly MemberScope $scope,
        private readonly PortalSettings $settings,
        private readonly LoanReader $loans,
        private readonly SavingsReader $savings,
    ) {}

    /** @return Builder<Document> the shared files the signed-in member may download */
    public function sharedFiles(): Builder
    {
        $memberId = $this->scope->borrowerId();

        return self::shareable(Document::query())
            ->whereNotNull('portal_shared_at')
            ->where(fn (Builder $owner) => $owner
                ->where(fn (Builder $q) => $q->where('documentable_type', (new Borrower)->getMorphClass())->where('documentable_id', $memberId))
                ->orWhere(fn (Builder $q) => $q->where('documentable_type', (new Loan)->getMorphClass())
                    ->whereIn('documentable_id', $this->loans->query()->select('loans.id'))));
    }

    /**
     * The files that may ever be shared with a member: a member's or a loan's
     * own files, never a valid ID (and so never a co-maker's file either).
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public static function shareable(Builder $query): Builder
    {
        return $query
            ->whereIn('documentable_type', [(new Borrower)->getMorphClass(), (new Loan)->getMorphClass()])
            ->where('type', '!=', ValidIdService::DOCUMENT_TYPE);
    }

    public function find(int $id): Document
    {
        return $this->sharedFiles()->findOrFail($id);
    }

    /** @return array{files: list<array<string, mixed>>, generated: list<array{kind: string, id: int|null, title: string, reference: string|null}>} */
    public function list(): array
    {
        $files = $this->sharedFiles()
            ->orderByDesc('portal_shared_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Document $d): array => [
                'id' => $d->id,
                'title' => $d->label ?: ($d->custom_type_name ?: MemberWords::documentType($d->type)),
                'category' => $d->type,
                'shared_on' => $d->portal_shared_at === null ? null : Carbon::parse($d->portal_shared_at)->toDateString(),
                'file_name' => $d->original_filename,
                'size' => $d->file_size === null ? null : (int) $d->file_size,
            ])
            ->values()
            ->all();

        return ['files' => $files, 'generated' => $this->generated()];
    }

    /** How many shared files the member has (the dashboard). */
    public function count(): int
    {
        return $this->sharedFiles()->count();
    }

    /** @return list<array{kind: string, id: int|null, title: string, reference: string|null}> */
    private function generated(): array
    {
        if (! $this->settings->featureOn('statements')) {
            return [];
        }

        $links = [];

        if ($this->settings->featureOn('loans')) {
            foreach ($this->loans->query()->orderByDesc('released_at')->orderByDesc('id')->get(['id', 'loan_account_number']) as $loan) {
                $links[] = ['kind' => 'loan_statement', 'id' => $loan->id, 'title' => 'Statement of Account', 'reference' => $loan->loan_account_number];
            }
        }

        if ($this->settings->featureOn('share_capital')) {
            $links[] = ['kind' => 'share_capital_statement', 'id' => null, 'title' => 'Share Capital Statement', 'reference' => null];
            $links[] = ['kind' => 'share_capital_certificate', 'id' => null, 'title' => 'Share Capital Certificate', 'reference' => null];
        }

        if ($this->settings->featureOn('savings')) {
            foreach ($this->savings->accounts()->orderBy('account_number')->get(['id', 'account_number']) as $account) {
                /** @var SavingsAccount $account */
                $links[] = ['kind' => 'savings_statement', 'id' => $account->id, 'title' => 'Savings Statement', 'reference' => $account->account_number];
            }
        }

        if ($this->settings->featureOn('time_deposits')) {
            foreach ($this->savings->timeDepositQuery()->orderByDesc('placement_date')->orderByDesc('id')->get(['id', 'certificate_number']) as $deposit) {
                /** @var TimeDeposit $deposit */
                $links[] = ['kind' => 'time_deposit_certificate', 'id' => $deposit->id, 'title' => 'Time Deposit Certificate', 'reference' => $deposit->certificate_number];
            }
        }

        return $links;
    }
}

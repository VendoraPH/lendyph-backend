<?php

namespace App\Console\Commands;

use App\Models\Borrower;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('registrations:backfill-approvals {--dry-run : Show what would be stamped without writing}')]
#[Description('Stamp approved_at on members admitted before /approve-registration recorded it')]
class BackfillRegistrationApprovals extends Command
{
    /**
     * Arms the `whereNull('approved_at')` guard in `registrations:prune`.
     *
     * Approvals have always been performed through `/reactivate`, which flips
     * `status` and stamps nothing. Every member on binhs-coop production —
     * all 43 of them — therefore carries `approved_at = NULL`, which makes that
     * guard inert: `status != 'pending'` is the only thing standing between an
     * approved member and the 03:30 prune. The frontend now points Approve at
     * `/approve-registration`, but that only stamps approvals made from here
     * on; the existing rows need filling in once.
     *
     * One-shot and run per deployment by hand — deliberately NOT scheduled.
     * Idempotent because it only ever fills a blank, so a second run finds
     * nothing and a partially-completed run can simply be repeated.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $candidates = Borrower::query()
            ->members()
            ->whereNull('approved_at')
            ->orderBy('id')
            ->get(['id', 'borrower_code', 'status', 'created_at']);

        if ($candidates->isEmpty()) {
            $this->info('Every member already has an approved_at timestamp.');

            return self::SUCCESS;
        }

        $approvals = $this->approvalEventsFor($candidates->pluck('id')->all());

        $fromAudit = 0;
        $fromCreation = 0;
        $withApprover = 0;

        foreach ($candidates as $borrower) {
            $event = $approvals[$borrower->id] ?? null;

            $approvedAt = $event['approved_at'] ?? $borrower->created_at;
            $approvedBy = $event['approved_by'] ?? null;

            if ($event) {
                $fromAudit++;
            } else {
                $fromCreation++;
            }

            if ($approvedBy !== null) {
                $withApprover++;
            }

            $this->line(sprintf(
                '  %s %s (%s) -> %s%s',
                $dryRun ? 'would stamp' : 'stamping',
                $borrower->borrower_code,
                $borrower->status,
                $approvedAt,
                $event ? ' [audit trail]' : ' [created as a member]',
            ));

            if ($dryRun) {
                continue;
            }

            /*
             * Written through the query builder rather than Eloquent, for the
             * same two reasons as users:backfill-last-login. This is a
             * historical correction, not activity, so it must not bump
             * `updated_at` — `registrations:prune` treats that column as
             * "last applicant activity" and moving it would reset the
             * abandonment clock on every row this touches. And Borrower is
             * Auditable: a model save would write an audit row per borrower
             * carrying the full original attributes — name, birthdate,
             * address, contact number, income — into audit_logs.old_values,
             * permanently.
             */
            DB::table('borrowers')
                ->where('id', $borrower->id)
                ->whereNull('approved_at')
                ->update([
                    'approved_at' => $approvedAt,
                    'approved_by' => $approvedBy,
                ]);
        }

        $verb = $dryRun ? 'Would stamp' : 'Stamped';

        $this->info("{$verb} approved_at on {$candidates->count()} member(s): "
            ."{$fromAudit} dated from the audit trail, {$fromCreation} from their creation date.");

        // approved_by is left null wherever the audit trail does not name an
        // approver. A borrower created straight into a member status was never
        // "approved" by anyone, and inventing a plausible operator would put a
        // false name on an accountability record — worse than a blank one.
        $this->line("approved_by resolved for {$withApprover} of them; the rest stay null.");

        return self::SUCCESS;
    }

    /**
     * The moment each borrower first crossed from non-member into membership.
     *
     * Reconstructed from the audit trail, which is the only record of it: the
     * Auditable trait writes an `updated` row per change, carrying the previous
     * attributes in `old_values` and just the changed ones in `new_values`. A
     * row whose `new_values.status` is a member status and whose
     * `old_values.status` is `pending` or `rejected` IS the approval, whichever
     * endpoint performed it, and `user_id` on that row is the operator who did
     * it. Ordered ascending so the FIRST such transition wins — a later
     * deactivate/reactivate pair must not be mistaken for the approval.
     *
     * @param  list<int>  $borrowerIds
     * @return array<int, array{approved_at: string, approved_by: int|null}>
     */
    private function approvalEventsFor(array $borrowerIds): array
    {
        $rows = DB::table('audit_logs')
            ->where('auditable_type', Borrower::class)
            ->whereIn('auditable_id', $borrowerIds)
            ->where('action', 'updated')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['auditable_id', 'user_id', 'created_at', 'old_values', 'new_values']);

        $approvers = $this->existingUserIds($rows->pluck('user_id'));

        $events = [];

        foreach ($rows as $row) {
            if (isset($events[$row->auditable_id])) {
                continue;
            }

            $before = $this->statusIn($row->old_values);
            $after = $this->statusIn($row->new_values);

            $isApproval = $after !== null
                && in_array($before, Borrower::NON_MEMBER_STATUSES, true)
                && ! in_array($after, Borrower::NON_MEMBER_STATUSES, true);

            if (! $isApproval) {
                continue;
            }

            $userId = $row->user_id !== null ? (int) $row->user_id : null;

            $events[$row->auditable_id] = [
                'approved_at' => $row->created_at,
                // `borrowers.approved_by` is a constrained foreign key. An audit
                // row can name a user who has since been deleted, and writing
                // that id back would fail the whole command on a row it was
                // meant to repair.
                'approved_by' => $userId !== null && in_array($userId, $approvers, true) ? $userId : null,
            ];
        }

        return $events;
    }

    /**
     * @param  Collection<int, mixed>  $userIds
     * @return list<int>
     */
    private function existingUserIds(Collection $userIds): array
    {
        $ids = $userIds->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return User::whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Reads `status` out of an audit row's JSON column, if it is recorded there.
     */
    private function statusIn(?string $json): ?string
    {
        $values = json_decode((string) $json, true);

        return is_array($values) && is_string($values['status'] ?? null)
            ? $values['status']
            : null;
    }
}

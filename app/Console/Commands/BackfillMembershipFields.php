<?php

namespace App\Console\Commands;

use App\Models\Borrower;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The CDA member-fields data fix (design §1; C1 review, H3), run by hand after
 * its dry-run. It writes only what a replay of the records proves.
 *
 * For members only (Borrower::scopeMembers(): every status but `pending` and
 * `rejected`, former members included), and only where a field is empty:
 * - `membership_date`: the Manila date of the first audit row that proves
 *   when they became a member:
 *   - their approval, a staff user moving them out of `pending` or
 *     `rejected` (through approve-registration, or the reactivate route used
 *     for approvals before it); or
 *   - their creation by a staff user directly as a member (status `active`
 *     or another member status on the `created` row).
 *   The user on that row is named in the audit description.
 * - Skipped and listed, with the reason, when there is no such row, or
 *   always for an import:
 *   - imported from a CSV migration (`approved_at` is the extract's date),
 *     even though staff ran the import;
 *   - `approved_at` stamped by `registrations:backfill-approvals` (no
 *     approver, or the creation time);
 *   - no approval on record at all.
 *   Staff enter those dates by hand (CAPR shows them as Critical).
 * - `membership_number`: the member code, for every member, since that is
 *   exact; skipped and listed when another member already holds it.
 *
 * A filled field is never changed. Each member changed gets one system audit
 * row (no user) with the old and new values, under the member's row lock, so
 * running it again changes nothing.
 */
#[Signature('members:backfill-membership-fields {--dry-run : List what would be filled and what is skipped, writing nothing}')]
#[Description('Fill empty membership dates from real approvals or staff creation in the audit trail, and membership numbers from the member code, with one audit row per member')]
class BackfillMembershipFields extends Command
{
    public const SKIP_IMPORTED = 'imported from a CSV migration (its approval date is the extract\'s date, not when they joined)';

    public const SKIP_STAMPED = 'the approval date was stamped by registrations:backfill-approvals, not recorded at an approval';

    public const SKIP_NONE = 'no approval on record, and no staff user created them as a member';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $skippedDates = 0;
        $taken = [];

        if ($dryRun) {
            $this->info('Dry run: nothing is written.');
        }

        Borrower::query()->members()
            ->where(fn ($query) => $query->whereNull('membership_date')->orWhereNull('membership_number'))
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(500, function ($chunk) use ($dryRun, &$updated, &$skippedDates, &$taken): void {
                $approvals = $this->approvalsFor($chunk->pluck('id')->map(fn ($id) => (int) $id)->all());

                foreach ($chunk as $row) {
                    $id = (int) $row->id;
                    $result = $dryRun
                        ? $this->plan($id, $approvals[$id] ?? null)
                        : DB::transaction(fn () => $this->apply($id, $approvals[$id] ?? null));

                    if ($result === null) {
                        continue;
                    }

                    ['member' => $member, 'changes' => $changes, 'skip' => $skip, 'number_taken' => $numberTaken, 'approval' => $approval] = $result;

                    if ($changes !== []) {
                        $updated++;
                        $this->line(sprintf('  %s: %s %s%s', $member->borrower_code, $dryRun ? 'would set' : 'set', self::describe($changes), $this->approvalNote($changes, $approval)));
                    }

                    if ($skip !== null) {
                        $skippedDates++;
                        $this->warn("  {$member->borrower_code}: membership date skipped: {$skip}.");
                    }

                    if ($numberTaken) {
                        $taken[] = $member->borrower_code;
                    }
                }
            });

        foreach ($taken as $code) {
            $this->warn("  {$code}: membership number not set; {$code} is already used by another member.");
        }

        $this->info(sprintf(
            '%d members %s; %d membership date(s) skipped for staff to enter.',
            $updated,
            $dryRun ? 'would be updated' : 'updated',
            $skippedDates,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array{at: string, user_id: int, how: string}|null  $approval
     * @return array{member: Borrower, changes: array<string, string>, skip: string|null, number_taken: bool, approval: array{at: string, user_id: int, how: string}|null}|null
     */
    private function plan(int $id, ?array $approval): ?array
    {
        $member = Borrower::query()->members()->find($id);

        return $member === null ? null : ['member' => $member, ...$this->changesFor($member, $approval), 'approval' => $approval];
    }

    /**
     * @param  array{at: string, user_id: int, how: string}|null  $approval
     * @return array{member: Borrower, changes: array<string, string>, skip: string|null, number_taken: bool, approval: array{at: string, user_id: int, how: string}|null}|null
     */
    private function apply(int $id, ?array $approval): ?array
    {
        /** @var Borrower|null $member */
        $member = Borrower::query()->members()->whereKey($id)->lockForUpdate()->first();

        if ($member === null) {
            return null;
        }

        $result = $this->changesFor($member, $approval);
        $changes = $result['changes'];

        if ($changes !== []) {
            $old = [];

            foreach (array_keys($changes) as $field) {
                $old[$field] = $member->getRawOriginal($field);
            }

            AuditLogService::withoutModelAuditing(function () use ($member, $changes): void {
                $member->timestamps = false;
                $member->forceFill($changes)->save();
            });

            AuditLogService::log(
                'updated',
                $member,
                $old,
                $changes,
                'System correction by members:backfill-membership-fields: '.self::describe($changes).$this->approvalNote($changes, $approval).'.',
                null,
            );
        }

        return ['member' => $member, ...$result, 'approval' => $approval];
    }

    /**
     * @param  array{at: string, user_id: int, how: string}|null  $approval
     * @return array{changes: array<string, string>, skip: string|null, number_taken: bool}
     */
    private function changesFor(Borrower $member, ?array $approval): array
    {
        $changes = [];
        $skip = null;
        $numberTaken = false;

        if ($member->membership_date === null) {
            if ($approval !== null && blank($member->external_account_no)) {
                $changes['membership_date'] = substr($approval['at'], 0, 10);
            } else {
                $skip = match (true) {
                    filled($member->external_account_no) => self::SKIP_IMPORTED,
                    $member->approved_at !== null && ($member->approved_by === null || $member->approved_at->equalTo($member->created_at)) => self::SKIP_STAMPED,
                    default => self::SKIP_NONE,
                };
            }
        }

        if (blank($member->membership_number) && filled($member->borrower_code)) {
            $numberTaken = Borrower::query()->where('membership_number', $member->borrower_code)->whereKeyNot($member->id)->exists();

            if (! $numberTaken) {
                $changes['membership_number'] = (string) $member->borrower_code;
            }
        }

        return ['changes' => $changes, 'skip' => $skip, 'number_taken' => $numberTaken];
    }

    /**
     * Each member's first proof of membership in the audit trail, by a staff
     * user (rows with no user prove nothing):
     * - `approved`: an `updated` row whose old status is `pending` or
     *   `rejected` and whose new one is a member status, read the same way
     *   registrations:backfill-approvals reads it;
     * - `created`: a `created` row whose status is a member status (a row
     *   with no status took the column's default, `active`).
     *
     * @param  list<int>  $memberIds
     * @return array<int, array{at: string, user_id: int, how: string}>
     */
    private function approvalsFor(array $memberIds): array
    {
        $rows = DB::table('audit_logs')
            ->where('auditable_type', Borrower::class)
            ->whereIn('auditable_id', $memberIds)
            ->whereIn('action', ['created', 'updated'])
            ->whereNotNull('user_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['auditable_id', 'action', 'user_id', 'created_at', 'old_values', 'new_values']);

        $approvals = [];

        foreach ($rows as $row) {
            if (isset($approvals[(int) $row->auditable_id])) {
                continue;
            }

            $new = json_decode((string) $row->new_values, true);
            $after = is_array($new) ? ($new['status'] ?? null) : null;

            if ($row->action === 'created') {
                $status = is_array($new) ? ($after ?? 'active') : null;

                if (is_string($status) && ! in_array($status, Borrower::NON_MEMBER_STATUSES, true)) {
                    $approvals[(int) $row->auditable_id] = ['at' => (string) $row->created_at, 'user_id' => (int) $row->user_id, 'how' => 'created'];
                }

                continue;
            }

            $before = json_decode((string) $row->old_values, true)['status'] ?? null;

            if (in_array($before, Borrower::NON_MEMBER_STATUSES, true) && is_string($after) && ! in_array($after, Borrower::NON_MEMBER_STATUSES, true)) {
                $approvals[(int) $row->auditable_id] = ['at' => (string) $row->created_at, 'user_id' => (int) $row->user_id, 'how' => 'approved'];
            }
        }

        return $approvals;
    }

    /**
     * @param  array<string, string>  $changes
     * @param  array{at: string, user_id: int, how: string}|null  $approval
     */
    private function approvalNote(array $changes, ?array $approval): string
    {
        if (! isset($changes['membership_date']) || $approval === null) {
            return '';
        }

        $approver = User::query()->find($approval['user_id']);
        $name = $approver === null ? "user #{$approval['user_id']}" : (trim((string) $approver->full_name) ?: (string) $approver->username);

        $how = $approval['how'] === 'created' ? 'created as a member' : 'approved';

        return " ({$how} by {$name} on {$approval['at']}, per the audit trail)";
    }

    /** @param  array<string, string>  $changes */
    private static function describe(array $changes): string
    {
        return collect($changes)->map(fn (string $value, string $field) => str_replace('_', ' ', $field)." {$value}")->implode(', ');
    }
}

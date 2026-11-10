<?php

namespace App\Console\Commands;

use App\Models\Borrower;
use App\Services\AuditLogService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The CDA member-fields data fix (design §1), run by hand after its dry-run.
 *
 * For members only (Borrower::scopeMembers(): every status but `pending` and
 * `rejected`, former members included), and only where a field is empty:
 * - `membership_date` = the Manila date of `approved_at`;
 * - `membership_number` = the member code, unless another member already
 *   holds that number (skipped and listed).
 *
 * A member with no `approved_at` keeps no date: it isn't known, so they are
 * listed for staff to enter (CAPR shows them as Critical). A filled field is
 * never changed. Each member changed gets one system audit row (no user) with
 * the old and new values, under the member's row lock, so running it again
 * changes nothing.
 */
#[Signature('members:backfill-membership-fields {--dry-run : List what would be filled, writing nothing}')]
#[Description('Fill empty membership dates (from the approval date) and membership numbers (the member code), with one audit row per member')]
class BackfillMembershipFields extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $noDate = [];
        $taken = [];

        if ($dryRun) {
            $this->info('Dry run: nothing is written.');
        }

        Borrower::query()->members()
            ->where(fn ($query) => $query->whereNull('membership_date')->orWhereNull('membership_number'))
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(500, function ($chunk) use ($dryRun, &$updated, &$noDate, &$taken): void {
                foreach ($chunk as $row) {
                    $result = $dryRun ? $this->plan((int) $row->id) : DB::transaction(fn () => $this->apply((int) $row->id));

                    if ($result === null) {
                        continue;
                    }

                    [$borrower, $changes, $numberTaken] = $result;

                    if ($borrower->membership_date === null && ! array_key_exists('membership_date', $changes)) {
                        $noDate[] = $borrower->borrower_code;
                    }

                    if ($numberTaken) {
                        $taken[] = $borrower->borrower_code;
                    }

                    if ($changes !== []) {
                        $updated++;
                        $this->line(sprintf('  %s: %s %s', $borrower->borrower_code, $dryRun ? 'would set' : 'set', $this->describe($changes)));
                    }
                }
            });

        foreach ($taken as $code) {
            $this->warn("  {$code}: membership number not set; {$code} is already used by another member.");
        }

        if ($noDate !== []) {
            $this->warn(sprintf('%d member(s) have no approval date, so no membership date was set. Enter it on each: %s', count($noDate), implode(', ', $noDate)));
        }

        $this->info(sprintf('%d members %s.', $updated, $dryRun ? 'would be updated' : 'updated'));

        return self::SUCCESS;
    }

    /**
     * What would change for this member, read without a lock.
     *
     * @return array{0: Borrower, 1: array<string, string>, 2: bool}|null
     */
    private function plan(int $id): ?array
    {
        $borrower = Borrower::query()->find($id);

        return $borrower === null ? null : [$borrower, ...$this->changesFor($borrower)];
    }

    /**
     * @return array{0: Borrower, 1: array<string, string>, 2: bool}|null
     */
    private function apply(int $id): ?array
    {
        /** @var Borrower|null $borrower */
        $borrower = Borrower::query()->members()->whereKey($id)->lockForUpdate()->first();

        if ($borrower === null) {
            return null;
        }

        [$changes, $numberTaken] = $this->changesFor($borrower);

        if ($changes === []) {
            return [$borrower, $changes, $numberTaken];
        }

        $old = array_map(fn (string $field) => $borrower->getRawOriginal($field), array_combine(array_keys($changes), array_keys($changes)));

        AuditLogService::withoutModelAuditing(function () use ($borrower, $changes): void {
            $borrower->timestamps = false;
            $borrower->forceFill($changes)->save();
        });

        AuditLogService::log(
            'updated',
            $borrower,
            $old,
            $changes,
            'System correction by members:backfill-membership-fields: '.$this->describe($changes).'.',
            null,
        );

        return [$borrower, $changes, $numberTaken];
    }

    /**
     * @return array{0: array<string, string>, 1: bool}
     */
    private function changesFor(Borrower $borrower): array
    {
        $changes = [];
        $numberTaken = false;

        if ($borrower->membership_date === null && $borrower->approved_at !== null) {
            $changes['membership_date'] = Carbon::parse($borrower->approved_at)->timezone('Asia/Manila')->toDateString();
        }

        if (blank($borrower->membership_number) && filled($borrower->borrower_code)) {
            $numberTaken = Borrower::query()->where('membership_number', $borrower->borrower_code)->whereKeyNot($borrower->id)->exists();

            if (! $numberTaken) {
                $changes['membership_number'] = (string) $borrower->borrower_code;
            }
        }

        return [$changes, $numberTaken];
    }

    /** @param  array<string, string>  $changes */
    private function describe(array $changes): string
    {
        return collect($changes)->map(fn (string $value, string $field) => str_replace('_', ' ', $field)." {$value}")->implode(', ');
    }
}

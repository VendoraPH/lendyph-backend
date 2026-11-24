<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The reference numbers the server gives cash entries (finish run round 2,
 * decision 5): `CSH-[id]-[year]-[0001]` on a cash share capital entry and
 * `TD-[id]-[year]-[0001]` on a cash time deposit placement.
 *
 * - `[id]`: the deployment's primary super admin, the lowest-id active
 *   super_admin, padded to at least 4 digits (user 12 reads 0012).
 * - `[year]`: the year of the entry's date in Manila.
 * - The sequence: from 0001 for each prefix, id and year, padded to at least
 *   4 digits and wider past 9999. Each series keeps its last number in one
 *   `cash_reference_sequences` row.
 *
 * ## No number twice, none skipped
 *
 * assign() runs inside the saving write's own transaction and locks the
 * series row FOR UPDATE, so two saves at the same moment queue on it and the
 * second reads what the first committed. A save that rolls back takes its
 * number back with it. The first number of a series inserts the row: two
 * first saves at once collide on its unique index or deadlock on the gap, so
 * the loser is tried again (GeneratedNumberWrite, which retries on
 * SERIES_INDEX) or, for a write that is never tried again, answered with 409
 * and nothing saved.
 *
 * Read owner() before the write's transaction opens and pass it in: a plain
 * read inside it before the series lock would fix the transaction's snapshot
 * before the wait, and its later plain reads (the ledger's own SC- number)
 * would miss what the save it waited for committed.
 *
 * next() is the screens' preview: the number the next save would get, read
 * plainly, reserving nothing.
 */
final class CashReferences
{
    /** Cash share capital entries. */
    public const SHARE_CAPITAL = 'CSH';

    /** Cash time deposit placements. */
    public const TIME_DEPOSIT = 'TD';

    public const SERIES_INDEX = GeneratedNumberWrite::CASH_REFERENCE_SERIES_INDEX;

    public const PAD_LENGTH = 4;

    public const NO_OWNER = 'Cash entries take their reference number from the primary super admin\'s user ID, and no super admin account is active. Reactivate one before recording cash.';

    private const TABLE = 'cash_reference_sequences';

    private const TIMEZONE = 'Asia/Manila';

    /** The primary super admin: the lowest-id active super_admin, or null when none is active. */
    public function owner(): ?int
    {
        $id = User::query()
            ->active()
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'))
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The number the next save in this series would get today, or null when
     * no super admin is active. Writes and locks nothing.
     */
    public function next(string $prefix): ?string
    {
        $owner = $this->owner();

        if ($owner === null) {
            return null;
        }

        $year = (int) now()->setTimezone(self::TIMEZONE)->year;
        $last = DB::table(self::TABLE)->where('prefix', $prefix)->where('owner_id', $owner)->where('year', $year)->value('last_number');

        return self::format($prefix, $owner, $year, (int) $last + 1);
    }

    /**
     * The numbers for entries dated `$dates`, in the same order, each the next
     * one in its year's series. Inside the caller's transaction, under the
     * series rows' locks (one statement, in year order).
     *
     * @param  int|null  $owner  owner(), read before the transaction opened
     * @param  list<string>  $dates  each entry's calendar date (Y-m-d)
     * @param  string  $field  the request field a refusal is reported on
     * @return list<string>
     *
     * @throws ValidationException when no super admin is active
     */
    public function assign(string $prefix, ?int $owner, array $dates, string $field): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A cash reference number is given inside its save\'s transaction, so a rollback takes it back.');
        }

        if ($owner === null) {
            throw ValidationException::withMessages([$field => [self::NO_OWNER]]);
        }

        if ($dates === []) {
            return [];
        }

        $years = array_map(self::year(...), array_values($dates));
        $counts = array_count_values($years);
        ksort($counts);

        $series = DB::table(self::TABLE)
            ->where('prefix', $prefix)
            ->where('owner_id', $owner)
            ->whereIn('year', array_keys($counts))
            ->orderBy('year')
            ->lockForUpdate()
            ->get(['id', 'year', 'last_number'])
            ->keyBy('year');

        $next = [];

        foreach ($counts as $year => $count) {
            $row = $series->get($year);

            if ($row === null) {
                DB::table(self::TABLE)->insert(['prefix' => $prefix, 'owner_id' => $owner, 'year' => $year, 'last_number' => $count]);
                $next[$year] = 1;

                continue;
            }

            DB::table(self::TABLE)->where('id', $row->id)->update(['last_number' => (int) $row->last_number + $count]);
            $next[$year] = (int) $row->last_number + 1;
        }

        $numbers = [];

        foreach ($years as $year) {
            $numbers[] = self::format($prefix, $owner, $year, $next[$year]++);
        }

        return $numbers;
    }

    /** `CSH-0012-2026-0001`: the id and the sequence padded to at least four digits. */
    public static function format(string $prefix, int $owner, int $year, int $sequence): string
    {
        return sprintf(
            '%s-%s-%d-%s',
            $prefix,
            str_pad((string) $owner, self::PAD_LENGTH, '0', STR_PAD_LEFT),
            $year,
            str_pad((string) $sequence, self::PAD_LENGTH, '0', STR_PAD_LEFT),
        );
    }

    /** The calendar year of `$date` in Manila. */
    private static function year(string $date): int
    {
        return CarbonImmutable::parse($date, self::TIMEZONE)->setTimezone(self::TIMEZONE)->year;
    }
}

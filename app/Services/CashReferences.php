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
 * next() is the screens' preview: the number the next save dated `$date`
 * (today when null) would get, read plainly, reserving nothing.
 *
 * advance() moves a series past a number the app didn't give: an entry the
 * share capital import brought over whose reference is already in this
 * deployment's own format (finish run round 2, decision 13).
 */
final class CashReferences
{
    /** Cash share capital entries. */
    public const SHARE_CAPITAL = 'CSH';

    /** Cash time deposit placements. */
    public const TIME_DEPOSIT = 'TD';

    public const SERIES_INDEX = GeneratedNumberWrite::CASH_REFERENCE_SERIES_INDEX;

    public const PAD_LENGTH = 4;

    /**
     * The highest number a series is moved to (advance()). Nine digits, far
     * past any real year of cash entries and well inside the counter's
     * column, so the series can always give the next one.
     */
    public const MAX_SEQUENCE = 999_999_999;

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
     * The number the next save in this series dated `$date` (a calendar date,
     * Y-m-d; today in Manila when null) would get, or null when no super admin
     * is active. A placement dated Dec 31 and entered on Jan 1 saves in the
     * year before's series, so the preview follows the date the form carries.
     * Writes and locks nothing.
     */
    public function next(string $prefix, ?string $date = null): ?string
    {
        $owner = $this->owner();

        if ($owner === null) {
            return null;
        }

        $year = $date === null ? (int) now()->setTimezone(self::TIMEZONE)->year : self::year($date);
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

    /**
     * Moves a series on so its next number is above `$sequence`: its last
     * number becomes at least `$sequence`, and a series with no row yet
     * starts there. Never moves one back. Inside the caller's transaction,
     * under the series row's lock, as assign() takes it, so a save waiting on
     * the lock reads the moved number.
     */
    public function advance(string $prefix, int $owner, int $year, int $sequence): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A series is moved on inside its save\'s transaction, so a rollback takes it back.');
        }

        if ($sequence < 1 || $sequence > self::MAX_SEQUENCE) {
            throw new LogicException("A series can't be moved to {$sequence}.");
        }

        $row = DB::table(self::TABLE)
            ->where('prefix', $prefix)
            ->where('owner_id', $owner)
            ->where('year', $year)
            ->lockForUpdate()
            ->first(['id', 'last_number']);

        if ($row === null) {
            DB::table(self::TABLE)->insert(['prefix' => $prefix, 'owner_id' => $owner, 'year' => $year, 'last_number' => $sequence]);

            return;
        }

        if ((int) $row->last_number < $sequence) {
            DB::table(self::TABLE)->where('id', $row->id)->update(['last_number' => $sequence]);
        }
    }

    /**
     * A reference read as one of these numbers: `CSH-0012-2026-0007` is
     * prefix CSH, owner 12, year 2026 and number 7. In any case, and with or
     * without the padding, since the same number written another way is
     * still the same number to anyone reading the ledger. Null when the
     * reference isn't one.
     *
     * @return array{prefix: string, owner: int, year: int, sequence: int}|null
     */
    public static function parse(string $reference): ?array
    {
        $prefixes = implode('|', [self::SHARE_CAPITAL, self::TIME_DEPOSIT]);

        if (preg_match('/^('.$prefixes.')-(\d{1,9})-(\d{4})-(\d{1,18})$/i', trim($reference), $parts) !== 1) {
            return null;
        }

        return [
            'prefix' => strtoupper($parts[1]),
            'owner' => (int) $parts[2],
            'year' => (int) $parts[3],
            'sequence' => (int) $parts[4],
        ];
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

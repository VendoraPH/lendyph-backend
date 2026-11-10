<?php

namespace App\Services\Cda\Sources;

use App\Models\Borrower;
use App\Models\Loan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Member counts for CDA reports (design §2), from the Members module's own
 * rule (Borrower::members(): everyone but pending and rejected registrations)
 * read as of a date:
 *
 * - a member counts on a day when their `membership_date` is on or before it
 *   and their membership hasn't ended by then (`membership_ended_on` empty or
 *   after it). A member with no membership date isn't counted; they are
 *   `members.missing {field: membership_date}` and a Critical issue on CAPR.
 * - `gender` unspecified is a member with no sex recorded; ages are whole
 *   years on the as-of date, from the birthdate.
 * - `activity` active follows the Member Report: a member with a loan in a
 *   collectible status released on or before the day (the loan's current
 *   status, as that report reads it); inactive is everyone else counted.
 * - `members.new` counts membership dates in the range; `members.ended`
 *   counts membership end dates in the range, by how it ended.
 *
 * Counts only, never a member's details. A count for a day that hasn't
 * ended yet is provisional.
 */
final class MembersSource implements CdaSource
{
    public function resolve(string $key, array $params, SourceContext $context): SourceResult
    {
        return match ($key) {
            'members.count' => $this->count($params, $context),
            'members.new' => $this->new($params, $context),
            'members.ended' => $this->ended($params, $context),
            'members.missing' => $this->missing($params, $context),
            default => throw SourceUnavailable::unknownKey($key, 'members'),
        };
    }

    private function count(array $params, SourceContext $context): SourceResult
    {
        $asOf = $context->date($params['as_of']);
        $query = $this->countedOn($asOf);
        $this->filterByPerson($query, $params, $asOf);

        if (isset($params['activity'])) {
            $active = DB::table('loans')
                ->whereIn('status', Loan::COLLECTIBLE_STATUSES)
                ->where('released_at', '<', CarbonImmutable::parse($asOf)->addDay()->toDateString())
                ->select('borrower_id');

            $params['activity'] === 'active'
                ? $query->whereIn('borrowers.id', $active)
                : $query->whereNotIn('borrowers.id', $active);
        }

        return new SourceResult($query->count(), SourceResult::labelFor($this->what('Members', $params), $asOf), 'count', $asOf, self::notYetEnded($asOf));
    }

    private function new(array $params, SourceContext $context): SourceResult
    {
        $from = $context->date($params['from']);
        $to = $context->date($params['to']);
        $query = Borrower::query()->members()->whereBetween('membership_date', [$from, $to]);
        $this->filterByPerson($query, $params, $to);

        return new SourceResult($query->count(), SourceResult::labelFor($this->what('New members', $params), $to), 'count', $to, self::notYetEnded($to));
    }

    private function ended(array $params, SourceContext $context): SourceResult
    {
        $from = $context->date($params['from']);
        $to = $context->date($params['to']);
        $query = Borrower::query()
            ->whereIn('status', Borrower::FORMER_MEMBER_STATUSES)
            ->whereBetween('membership_ended_on', [$from, $to])
            ->when($params['reason'] ?? null, fn (Builder $q, string $reason) => $q->where('status', $reason));
        $what = match ($params['reason'] ?? null) {
            'withdrawn' => 'Members who withdrew',
            'deceased' => 'Members who died',
            default => 'Members who left',
        };

        return new SourceResult($query->count(), SourceResult::labelFor($what, $to), 'count', $to, self::notYetEnded($to));
    }

    private function missing(array $params, SourceContext $context): SourceResult
    {
        $asOf = $context->date($params['as_of']);
        $field = (string) $params['field'];

        $query = $field === 'membership_date'
            ? Borrower::query()->members()->whereNull('membership_date')
                ->where(fn (Builder $q) => $q->whereNull('membership_ended_on')->orWhere('membership_ended_on', '>', $asOf))
            : $this->countedOn($asOf)->where(fn (Builder $q) => $q->whereNull($field)->orWhere($field, ''));

        return new SourceResult(
            $query->count(),
            SourceResult::labelFor('Members with no '.str_replace('_', ' ', $field === 'gender' ? 'sex' : $field), $asOf),
            'count',
            $asOf,
            self::notYetEnded($asOf),
        );
    }

    /** Members counted on the day: joined by then, not left by then. */
    private function countedOn(string $asOf): Builder
    {
        return Borrower::query()->members()
            ->whereNotNull('membership_date')
            ->where('membership_date', '<=', $asOf)
            ->where(fn (Builder $q) => $q->whereNull('membership_ended_on')->orWhere('membership_ended_on', '>', $asOf));
    }

    private function filterByPerson(Builder $query, array $params, string $asOf): void
    {
        $query
            ->when($params['gender'] ?? null, fn (Builder $q, string $gender) => $gender === 'unspecified' ? $q->whereNull('gender') : $q->where('gender', $gender))
            ->when($params['member_type'] ?? null, fn (Builder $q, string $type) => $q->where('member_type', $type));

        $day = CarbonImmutable::parse($asOf);

        if (isset($params['age_from'])) {
            // At least N years old on the day: born on or before the day N years earlier.
            $query->whereNotNull('birthdate')->where('birthdate', '<=', $day->subYears((int) $params['age_from'])->toDateString());
        }

        if (isset($params['age_to'])) {
            // At most N years old: born after the day N + 1 years earlier.
            $query->whereNotNull('birthdate')->where('birthdate', '>', $day->subYears((int) $params['age_to'] + 1)->toDateString());
        }
    }

    private function what(string $base, array $params): string
    {
        $parts = array_filter([
            match ($params['gender'] ?? null) {
                'male' => 'male', 'female' => 'female', 'unspecified' => 'sex not recorded', default => null,
            },
            $params['member_type'] ?? null,
            $params['activity'] ?? null,
            isset($params['age_from']) || isset($params['age_to'])
                ? 'aged '.($params['age_from'] ?? 0).(isset($params['age_to']) ? '–'.$params['age_to'] : '+')
                : null,
        ]);

        return $parts === [] ? $base : $base.' ('.implode(', ', $parts).')';
    }

    /** A figure for a day that hasn't ended yet can still change. */
    public static function notYetEnded(string $date): bool
    {
        return $date >= now()->toDateString();
    }
}

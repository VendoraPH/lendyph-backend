<?php

namespace App\Services\Cda;

use App\Models\Borrower;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\GeneratedNumberWrite;
use App\Services\Portal\Auth\MemberNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A member's membership details (finish run round 2, decision 15): the
 * membership date, member type and membership number, as the member page
 * edits them (PATCH /borrowers/{id}/membership).
 *
 * The CDA membership rules hold here as they do everywhere else:
 * - only a member has membership details: a pending or rejected registration
 *   has none (C1 review, M4);
 * - a withdrawn or deceased member stays a member for the records, and the
 *   CDA counts read their dates for the years they belonged, so their details
 *   can still be corrected. Their membership date can't fall after the day the
 *   membership ended, as an end date can't fall before it (MembershipStatus).
 *
 * The member is read again under their row lock, through the caller's branch
 * reach, so a member who moved out of it after the route bound them answers
 * 404. Blank sets the membership number back to the member code. The first
 * membership date still brings the member code as the number when there is
 * none (Borrower::booted()).
 *
 * One audit row on the member holds the old and new values of exactly the
 * fields that changed. A save that changes nothing writes nothing.
 */
final class MembershipDetails
{
    /** @var list<string> */
    public const FIELDS = ['membership_date', 'member_type', 'membership_number'];

    public const NOT_A_MEMBER = 'Only a member has membership details. Approve the registration first; approving sets them.';

    /** The words for each field, in the audit description. */
    private const WORDS = [
        'membership_date' => 'membership date',
        'member_type' => 'member type',
        'membership_number' => 'membership number',
    ];

    /** A number another member already holds, naming the number only. */
    public static function numberTaken(string $number): string
    {
        return "Membership number {$number} already belongs to another member. Each member's number is their own.";
    }

    /**
     * @param  array<string, mixed>  $input  the validated fields that were sent
     */
    public function change(Borrower $borrower, array $input, User $actor): Borrower
    {
        return DB::transaction(function () use ($borrower, $input, $actor): Borrower {
            /** @var Borrower $locked */
            $locked = Borrower::query()->visibleTo($actor)->whereKey($borrower->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, Borrower::NON_MEMBER_STATUSES, true)) {
                throw ValidationException::withMessages(['status' => self::NOT_A_MEMBER]);
            }

            $changes = $this->changes($locked, $input);
            $this->assertNotAfterTheEnd($locked, $changes);

            if ($changes === []) {
                return $locked;
            }

            $before = [];

            foreach (self::FIELDS as $field) {
                $before[$field] = $locked->getRawOriginal($field);
            }

            $this->write($locked, $changes);

            // What the save changed, the member code the first membership date
            // brings with it included.
            $changed = array_values(array_filter(self::FIELDS, fn (string $field): bool => $locked->wasChanged($field)));
            $new = [];

            foreach ($changed as $field) {
                $new[$field] = $changes[$field] ?? $locked->getRawOriginal($field);
            }

            AuditLogService::log(
                'updated',
                $locked,
                array_intersect_key($before, $new),
                $new,
                'Membership details changed: '.self::listInWords(array_map(fn (string $field): string => self::WORDS[$field], $changed)).'.',
            );

            return $locked;
        });
    }

    /**
     * The sent values that differ from the stored ones. The number is
     * compared as members sign in with it (MemberNumber), so the number a
     * member already has is never a change.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function changes(Borrower $member, array $input): array
    {
        $changes = [];

        if (array_key_exists('membership_date', $input) && $input['membership_date'] !== $member->membership_date?->toDateString()) {
            $changes['membership_date'] = (string) $input['membership_date'];
        }

        if (array_key_exists('member_type', $input) && $input['member_type'] !== ($member->member_type ?? Borrower::MEMBER_TYPE_REGULAR)) {
            $changes['member_type'] = (string) $input['member_type'];
        }

        if (array_key_exists('membership_number', $input)) {
            $number = blank($input['membership_number'])
                ? (string) $member->borrower_code
                : MemberNumber::normalise($input['membership_number']);

            if ($number !== MemberNumber::normalise((string) $member->membership_number)) {
                $changes['membership_number'] = $number;
            }
        }

        return $changes;
    }

    /**
     * A former member's membership date can't fall after the day their
     * membership ended, the other side of MembershipStatus's "the end date
     * can't be before the membership date".
     *
     * @param  array<string, string>  $changes
     */
    private function assertNotAfterTheEnd(Borrower $member, array $changes): void
    {
        $date = $changes['membership_date'] ?? null;

        if ($date === null || $member->membership_ended_on === null || $date <= $member->membership_ended_on->toDateString()) {
            return;
        }

        throw ValidationException::withMessages([
            'membership_date' => 'The membership date can\'t be after the day the membership ended ('.$member->membership_ended_on->format('M j, Y').').',
        ]);
    }

    /**
     * One write, with the automatic audit row turned off: that one would copy
     * the member's whole record into the trail. A number another member took
     * at the same moment answers like one already taken, never 500.
     *
     * @param  array<string, string>  $changes
     */
    private function write(Borrower $member, array $changes): void
    {
        try {
            AuditLogService::withoutModelAuditing(fn () => $member->update($changes));
        } catch (UniqueConstraintViolationException $e) {
            if (isset($changes['membership_number']) && str_contains($e->getMessage(), GeneratedNumberWrite::MEMBERSHIP_NUMBER_INDEX)) {
                throw ValidationException::withMessages(['membership_number' => self::numberTaken($changes['membership_number'])]);
            }

            throw $e;
        }
    }

    /** @param  list<string>  $items */
    private static function listInWords(array $items): string
    {
        if (count($items) <= 1) {
            return implode('', $items);
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}

<?php

namespace App\Services\LoanOrigination\Staff;

use App\Models\Borrower;
use App\Models\OriginationApplication;
use App\Models\User;
use App\Support\PhilippineMobile;
use Illuminate\Database\Eloquent\Builder;

/**
 * Existing members an online application may be, for staff to review
 * (origination decision 13). Suggestions only: nothing is ever linked by
 * itself, and the applicant never learns of them.
 *
 * A member matches on the same full name (first, middle and last, case and
 * spaces ignored, as BorrowerDuplicateDetector's exact tier), the same mobile
 * (last 10 digits, so 0917…, +63 917… and 917… agree) or the same email
 * (case ignored). Only members the caller can see are read (Borrower
 * visibleTo), so another branch's member is never shown or hinted at. At
 * most MAX matches, each with one reason in words, the strongest first.
 *
 * The mobile match reads every visible member's number through
 * REGEXP_REPLACE, a scan of the branch's members; fine for one cooperative's
 * book, and only run when a detail or the matches are opened.
 */
final class MemberMatches
{
    public const MAX = 5;

    private const REASONS = [
        'name' => 'Same full name',
        'mobile' => 'Same mobile number',
        'email' => 'Same email address',
    ];

    /**
     * @return list<array{id: int, name: string, member_number: ?string, status: string, reason: string}>
     */
    public function for(OriginationApplication $application, User $viewer): array
    {
        $personal = (array) (($application->draft ?? [])['personal'] ?? []);
        $first = $this->normalise($personal['first_name'] ?? null);
        $middle = $this->normalise($personal['middle_name'] ?? null);
        $last = $this->normalise($personal['last_name'] ?? null);
        $mobile = $this->lastTen($application->mobile);
        $email = $this->normalise($application->email);

        $found = [];

        if ($first !== '' && $last !== '') {
            $this->collect($found, 'name', $viewer, fn (Builder $q) => $q
                ->whereRaw('LOWER(TRIM(first_name)) = ?', [$first])
                ->whereRaw("LOWER(TRIM(COALESCE(middle_name, ''))) = ?", [$middle])
                ->whereRaw('LOWER(TRIM(last_name)) = ?', [$last]));
        }

        if ($mobile !== null) {
            $this->collect($found, 'mobile', $viewer, fn (Builder $q) => $q
                ->whereNotNull('contact_number')
                ->whereRaw("RIGHT(REGEXP_REPLACE(contact_number, '[^0-9]', ''), 10) = ?", [$mobile]));
        }

        if ($email !== '') {
            $this->collect($found, 'email', $viewer, fn (Builder $q) => $q->whereRaw('LOWER(TRIM(email)) = ?', [$email]));
        }

        return array_values(array_slice($found, 0, self::MAX));
    }

    /**
     * @param  array<int, array{id: int, name: string, member_number: ?string, status: string, reason: string}>  $found
     * @param  \Closure(Builder): mixed  $where
     */
    private function collect(array &$found, string $reason, User $viewer, \Closure $where): void
    {
        if (count($found) >= self::MAX) {
            return;
        }

        $members = Borrower::query()
            ->visibleTo($viewer)
            ->where('status', '!=', 'rejected')
            ->whereNotIn('borrowers.id', array_keys($found))
            ->tap($where)
            ->orderBy('id')
            ->limit(self::MAX)
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'borrower_code', 'membership_number', 'status']);

        foreach ($members as $member) {
            $found[(int) $member->id] = [
                'id' => (int) $member->id,
                'name' => (string) $member->full_name,
                'member_number' => $member->membership_number ?? $member->borrower_code,
                'status' => (string) $member->status,
                'reason' => self::REASONS[$reason],
            ];
        }
    }

    private function normalise(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function lastTen(?string $mobile): ?string
    {
        if (blank($mobile)) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', PhilippineMobile::normalise($mobile) ?? $mobile);

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }
}

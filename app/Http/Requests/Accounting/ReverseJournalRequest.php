<?php

namespace App\Http\Requests\Accounting;

use App\Models\AccountingJournal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Reversing a posted entry.
 *
 * Carries NO lines, and that absence is the point. The mirror is built from the
 * entry's own STORED lines — a caller that could supply them could "reverse" an
 * entry into a different shape than the one it undoes, and the two would not
 * net to zero while both looked perfectly correct in isolation.
 *
 * The reversal is never dated before the entry it reverses, given or by
 * default (today): it would undo the entry before the entry happened, and
 * every balance between the two dates would read the pair as a lone credit.
 *
 * The reversal of an entry with no branch that has a line on the allowance
 * can't be dated after today in Manila either
 * ({@see RefusesFutureNoBranchAllowanceDates}): the mirror has the same branch
 * and lines as the entry. The entry's own date is always allowed, because the
 * pair cancels out on that day; so an entry posted with a future date before
 * the rule existed can still be reversed, on its own date. Every refusal of
 * such an entry names the one range that satisfies both rules, so the two
 * never contradict each other.
 *
 * Unlike a manual entry's, a reversal date may carry a time or an offset: it
 * is read, checked and stored as its Manila day (see passedValidation()).
 */
class ReverseJournalRequest extends FormRequest
{
    use RefusesFutureNoBranchAllowanceDates;

    public function authorize(): bool
    {
        return $this->user()->can('journals:reverse');
    }

    public function rules(): array
    {
        return [
            // The date the reversal LANDS on, which is routinely not the
            // original's: reversing a September entry in October belongs in
            // October, or a period that has already been reported on silently
            // changes. Optional, defaulting to today.
            'date' => ['sometimes', 'nullable', 'date'],
            // Folded into the reversal's description, because there is no
            // column for it and it is the most useful half of the record.
            'reason' => ['sometimes', 'nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.date' => 'The reversal date isn\'t a valid date.',
            'reason.max' => 'The reason can be at most 300 characters.',
        ];
    }

    /**
     * Refuses, in words, once the date itself is valid, a reversal dated
     * before the entry it reverses, and one of a no-branch allowance entry
     * dated after today (unless it is the entry's own date). All days are
     * compared in the app's time zone (Manila), so a date sent with an offset
     * counts as its Manila day.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $journal = $this->route('journal');

                if ($validator->errors()->has('date') || ! $journal instanceof AccountingJournal || $journal->date === null) {
                    return;
                }

                $refusal = $this->dateRefusal($journal, $journal->date->toDateString(), $this->reversalDay());

                if ($refusal !== null) {
                    $validator->errors()->add('date', $refusal);
                }
            },
        ];
    }

    /**
     * Allowed: from the entry's own date on. For an entry with no branch and
     * a line on the allowance, also no later than today, except the entry's
     * own date, which is always allowed. Each refusal of that entry names the
     * days that are allowed, so following one message never meets the other.
     */
    private function dateRefusal(AccountingJournal $journal, string $entryDay, string $day): ?string
    {
        $today = $this->todayInManila();

        if ($day === $entryDay || ($day > $entryDay && $day <= $today)) {
            return null;
        }

        $allowance = $journal->branch_id === null
            ? $this->allowanceAccountAmong($journal->lines()->pluck('accounting_account_id')->all())
            : null;

        if ($allowance === null) {
            return $day < $entryDay
                ? sprintf(
                    'The reversal can\'t be dated before the entry it reverses (%1$s). Choose %1$s or later.',
                    $this->dayInWords($entryDay),
                )
                : null;
        }

        $account = $this->accountInWords($allowance);

        if ($entryDay > $today) {
            return sprintf(
                'This entry has no branch, a line on %1$s and a date after today (%2$s), so its reversal can only be dated on the entry\'s own date. Date it %3$s.',
                $account,
                $this->dayInWords($today),
                $this->dayInWords($entryDay),
            );
        }

        $allowed = $entryDay === $today
            ? $this->dayInWords($today)
            : 'from '.$this->dayInWords($entryDay).' to '.$this->dayInWords($today);

        return $day < $entryDay
            ? sprintf('The reversal can\'t be dated before the entry it reverses (%1$s). Date it %2$s.', $this->dayInWords($entryDay), $allowed)
            : sprintf(
                'This entry has no branch and a line on %1$s, so its reversal can\'t be dated after today (%2$s). Date it %3$s.',
                $account,
                $this->dayInWords($today),
                $allowed,
            );
    }

    /**
     * The reversal lands on the day it was checked as: a date sent with an
     * offset is stored as its Manila day, never the day at the sender's
     * offset.
     */
    protected function passedValidation(): void
    {
        if (filled($this->input('date'))) {
            $this->merge(['date' => $this->reversalDay()]);
        }
    }

    /** The reversal's day in the app's time zone, "2026-09-15"; today when none is sent. */
    private function reversalDay(): string
    {
        $timezone = config('app.timezone');
        $date = $this->input('date');

        return filled($date)
            ? Carbon::parse($date, $timezone)->setTimezone($timezone)->toDateString()
            : Carbon::now($timezone)->toDateString();
    }
}

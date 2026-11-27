<?php

namespace App\Services\Linda\Answers;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\ReminderQueueItem;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaLookupRequest;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaWording as W;
use App\Services\Reminders\Api\ReminderFormat;
use Illuminate\Support\Collection;

/**
 * "Did Juan Dela Cruz receive his due date reminder?" (Linda round 4, the
 * reminders task's section 55): a member lookup that mentions reminders, by
 * code or by name (LindaAssistant), answered on the server like every
 * lookup, so the model never sees it.
 *
 * For each of the member's loans the caller may see, the latest reminders:
 * the rule (else the reminder's type), the day it was for, the channel, and
 * whether it was sent, only logged in test mode, is still pending or failed,
 * in words, the latest one leading the answer. Never a contact, the message
 * or a failure reason, which can quote a contact.
 *
 * It needs `reminders:view`, as the reminders pages do, and `borrowers:view`,
 * as a member lookup does; the member is found only in the caller's branches,
 * so one elsewhere reads like no member at all.
 */
final class MemberRemindersAnswer extends LookupAnswer
{
    /** The latest reminders shown for each loan. */
    private const PER_LOAN = 3;

    /** @var array<string, string> each channel in words */
    private const CHANNELS = ['sms' => 'SMS', 'email' => 'Email'];

    public function intent(): LindaIntent
    {
        return LindaIntent::BorrowerLookup;
    }

    public function allows(User $user): bool
    {
        return $user->can('reminders:view') && $user->can('borrowers:view');
    }

    protected function subject(): string
    {
        return 'member reminders';
    }

    public function find(User $user, LindaLookupRequest $request): LindaReply
    {
        $member = $this->member($user, $request);

        return $member instanceof Borrower ? $this->about($user, $member) : $member;
    }

    /** A bare member code reads as the member lookup, so the hint keeps the word reminders. */
    protected function askAgainHint(): string
    {
        return 'Ask again with the member code and the word reminders (for example, "BRW-000012 reminders").';
    }

    private function about(User $user, Borrower $member): LindaReply
    {
        $loans = Loan::query()
            ->visibleTo($user)
            ->where('loans.borrower_id', $member->id)
            ->orderBy('loans.id')
            ->get();

        $reminders = $loans->flatMap(fn (Loan $loan): Collection => ReminderQueueItem::query()
            ->where('loan_id', $loan->id)
            ->with(['rule:id,name', 'message:id,queue_item_id,test_mode'])
            ->orderByDesc('fire_date')->orderByDesc('scheduled_at')->orderByDesc('id')
            ->limit(self::PER_LOAN)
            ->get()
            ->each(fn (ReminderQueueItem $item) => $item->setRelation('loan', $loan)))
            ->sortBy([['fire_date', 'desc'], ['scheduled_at', 'desc'], ['id', 'desc']])
            ->take(self::listMax())
            ->values();

        if ($reminders->isEmpty()) {
            return $this->reply(self::memberLabel($member).' has no payment reminders on the loans you can see.', 'answered', [], LindaLinks::for($user, 'reminders'), [$member->id]);
        }

        $latest = $reminders->first();
        $answer = 'The latest reminder to '.self::memberLabel($member).', '.self::typeOf($latest, true).' by '.self::channelOf($latest)
            .' on '.W::date($latest->fire_date->toDateString()).' for loan '.ReminderFormat::loanNumber($latest->loan).', '
            .self::outcome($latest).'. '.($reminders->count() === 1 ? 'It is the only one.' : 'Their latest reminders are listed below.');

        return $this->reply(
            $answer,
            'answered',
            [LindaReply::list('Latest reminders', $reminders->map(fn (ReminderQueueItem $item): array => [
                'title' => self::typeOf($item),
                'subtitle' => ReminderFormat::loanNumber($item->loan),
                'fields' => [
                    LindaReply::date('Date', $item->fire_date->toDateString()),
                    LindaReply::text('Channel', self::channelOf($item)),
                    LindaReply::text('Status', self::statusOf($item)),
                ],
            ])->all())],
            LindaLinks::for($user, 'reminders'),
            [$member->id],
            $reminders->pluck('loan_id')->unique()->values()->all(),
        );
    }

    /** The reminder's rule by name, else its type in words ("Due today"); lower case to read inside a sentence. */
    private static function typeOf(ReminderQueueItem $item, bool $inSentence = false): string
    {
        if ($item->rule?->name !== null && trim($item->rule->name) !== '') {
            return $item->rule->name;
        }

        $type = ReminderTemplate::typeLabel($item->template_type);

        return $inSentence ? lcfirst($type) : $type;
    }

    private static function channelOf(ReminderQueueItem $item): string
    {
        return self::CHANNELS[$item->channel] ?? ucfirst((string) $item->channel);
    }

    /** The reminder's state in words: handed over only in test mode reads "Logged (test mode)". */
    private static function statusOf(ReminderQueueItem $item): string
    {
        return match ($item->status) {
            'sent', 'delivered' => $item->message?->test_mode ? 'Logged (test mode)' : ($item->status === 'delivered' ? 'Delivered' : 'Sent'),
            'scheduled', 'queued', 'processing' => 'Pending',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
            'skipped' => 'Skipped',
            default => ucfirst(str_replace('_', ' ', (string) $item->status)),
        };
    }

    /** The latest reminder's state, to end its sentence. */
    private static function outcome(ReminderQueueItem $item): string
    {
        return match (self::statusOf($item)) {
            'Sent' => 'was sent',
            'Delivered' => 'was delivered',
            'Logged (test mode)' => 'was only logged in test mode, so it didn\'t reach the member',
            'Pending' => 'is still pending',
            'Failed' => 'failed',
            'Cancelled' => 'was cancelled',
            'Skipped' => 'was skipped',
            default => 'reads '.mb_strtolower(self::statusOf($item)),
        };
    }
}

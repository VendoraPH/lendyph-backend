<?php

namespace App\Services\Linda\Answers;

use App\Models\Borrower;
use App\Models\User;
use App\Services\ContactVisibility;
use App\Services\Linda\LindaFinder;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLookupRequest;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaWording as W;

/**
 * A lookup Linda answers on the server (LindaAnswerer::lookup()): one member,
 * one loan or one receipt, found only in the user's own branches. The
 * question is never sent to the model.
 *
 * A member named in a lookup is found by code or by name (LindaFinder). None
 * found says so, several are listed by name, code and branch for the user to
 * pick by code, and only one is ever answered for: never a guess. Names show
 * with their code only; no contact detail or address is ever in a reply.
 */
abstract class LookupAnswer
{
    public function __construct(
        protected readonly LindaFinder $finder,
        protected readonly ContactVisibility $visibility,
    ) {}

    abstract public function intent(): LindaIntent;

    abstract public function allows(User $user): bool;

    /** What the answer shows, in words, for a refusal ("member details"). */
    abstract protected function subject(): string;

    /** The reply for a request that names a target, for a user it allows. */
    abstract public function find(User $user, LindaLookupRequest $request): LindaReply;

    /** The name the question log records for this answer. */
    public function name(): string
    {
        return class_basename(static::class);
    }

    public function denied(): LindaReply
    {
        return new LindaReply($this->intent()->value, W::denied($this->subject()), 'denied', answerFunction: $this->name());
    }

    /**
     * @param  'no_target'|'not_found'|'ambiguous_record'|'answered'|'denied'  $outcome
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array{label: string, url: string}>  $links
     * @param  list<int>  $memberIds
     * @param  list<int>  $loanIds
     */
    protected function reply(string $answer, string $outcome = 'answered', array $blocks = [], array $links = [], array $memberIds = [], array $loanIds = []): LindaReply
    {
        return new LindaReply(
            $this->intent()->value,
            $answer,
            $outcome,
            $blocks,
            $links,
            array_values(array_unique(array_map('intval', $memberIds))),
            $this->name(),
            loanIds: array_values(array_unique(array_map('intval', $loanIds))),
        );
    }

    /**
     * The one member a request names, by code or by name, or the reply that
     * says none or several fit.
     */
    protected function member(User $user, LindaLookupRequest $request): Borrower|LindaReply
    {
        if ($request->code !== null && trim($request->code) !== '') {
            return $this->finder->memberByCode($user, $request->code)
                ?? $this->reply(W::noMemberWithCode(mb_strtoupper(trim($request->code))), 'not_found');
        }

        $name = (string) $request->name;
        $max = self::listMax();
        $found = $this->finder->membersNamed($user, $name, $max);

        if ($found['total'] === 0) {
            return $this->reply(W::noMemberNamed($name), 'not_found');
        }

        if ($found['total'] === 1) {
            return $found['members']->first();
        }

        $listed = $found['members'];
        $answer = 'I found '.W::count($found['total'], 'member').' named "'.W::echoed($name).'" in your branches.'
            .($found['total'] > $max ? " Here are the first {$max}." : '')
            .' Which one? Ask again with the member code.';

        $items = $listed->map(fn (Borrower $member): array => [
            'title' => (string) $member->full_name,
            'subtitle' => (string) $member->borrower_code,
            'fields' => [LindaReply::text('Branch', (string) ($member->branch?->name ?? '—'))],
            ...($this->visibility->allows($user, $member->branch_id === null ? null : (int) $member->branch_id)
                ? ['url' => '/borrowers/'.$member->id]
                : []),
        ])->values()->all();

        return $this->reply(
            $answer,
            'ambiguous_record',
            [LindaReply::list('Members named "'.W::echoed($name).'"', $items)],
            [],
            $listed->pluck('id')->all(),
        );
    }

    protected static function listMax(): int
    {
        return max(1, (int) config('linda.list_max', 10));
    }

    /** "Ana Cruz (BRW-000012)" */
    protected static function memberLabel(?Borrower $member): string
    {
        return $member === null ? 'an unknown member' : $member->full_name.' ('.$member->borrower_code.')';
    }
}

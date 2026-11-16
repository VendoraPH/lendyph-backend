<?php

namespace App\Services\Linda\Answers;

use App\Models\User;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaScope;
use App\Services\Linda\LindaWording;

/**
 * One of the questions Linda answers from the reports' own figures. Each
 * checks the permissions of the screen it mirrors, then reads the report
 * services for the caller's scope and words the result.
 */
abstract class Answer
{
    /** The intent this answer serves. */
    abstract public function intent(): LindaIntent;

    /** Whether $user may see what this answer shows. */
    abstract public function allows(User $user): bool;

    /** What the answer shows, in words, for a refusal ("collection figures"). */
    abstract protected function subject(): string;

    /**
     * The answer, for a user it allows and a scope that resolved. $period is
     * null only for an answer that is as of today.
     */
    abstract public function build(User $user, LindaScope $scope, ?LindaPeriod $period): LindaReply;

    /** The name the question log records for this answer. */
    public function name(): string
    {
        return class_basename(static::class);
    }

    public function denied(): LindaReply
    {
        return new LindaReply($this->intent()->value, LindaWording::denied($this->subject()), 'denied', answerFunction: $this->name());
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array{label: string, url: string}>  $links
     * @param  list<int>  $memberIds
     */
    protected function reply(string $answer, array $blocks, array $links, array $memberIds = []): LindaReply
    {
        return new LindaReply($this->intent()->value, $answer, 'answered', $blocks, $links, $memberIds, $this->name());
    }

    /** @param  list<string>  $permissions */
    protected static function holdsAll(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }

    /** The upper-cased first letter of a sentence. */
    protected static function sentence(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}

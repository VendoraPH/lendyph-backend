<?php

namespace App\Services\Linda\Model;

use App\Services\Linda\LindaIntent;

/**
 * Everything a model sees for one question: the question, the topics (the
 * intents) of up to five earlier turns, oldest first, and the model id to
 * ask. Never an earlier question's text, an earlier answer, a member row or a
 * figure (Arvin, 2026-11-16, D1): the server carries an earlier period and
 * branch itself (LindaFollowUp).
 *
 * A lookup's topic is left out too, as is a topic the server doesn't know: a
 * follow-up never carries a lookup on.
 */
final class LindaModelRequest
{
    /** The most earlier topics a model sees. */
    public const EARLIER_MAX = 5;

    /** @var list<string> the intents of earlier turns, oldest first */
    public readonly array $topics;

    /**
     * @param  array<int, LindaIntent|string|null>  $topics  the intents of earlier turns, oldest first
     */
    public function __construct(
        #[\SensitiveParameter] public readonly string $question,
        array $topics,
        public readonly string $model,
    ) {
        $kept = [];

        foreach ($topics as $topic) {
            $intent = $topic instanceof LindaIntent ? $topic : (is_string($topic) ? LindaIntent::tryFrom($topic) : null);

            if ($intent !== null && ! $intent->isLookup()) {
                $kept[] = $intent->value;
            }
        }

        $this->topics = array_slice($kept, -self::EARLIER_MAX);
    }

    /** The topic of the latest earlier turn, when there is one. */
    public function previousIntent(): ?LindaIntent
    {
        return $this->topics === [] ? null : LindaIntent::from($this->topics[count($this->topics) - 1]);
    }
}

<?php

namespace App\Services\Linda;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One Linda answer. toArray() is the frontend's reply (lendyph-frontend
 * src/types/linda.ts `LindaReply`): `{intent, answer, blocks, links, as_of}`.
 * The rest is for the question log, never sent: the member ids the answer
 * listed, the answer function that built it and how the question ended.
 */
final class LindaReply
{
    public const OUTCOMES = [
        'answered', 'not_yet', 'unsupported', 'denied', 'unmatched',
        'unclear_period', 'period_refused', 'no_branch', 'ambiguous_branch',
    ];

    /** When the figures were read, in Manila. */
    public readonly CarbonImmutable $asOf;

    /**
     * @param  list<array<string, mixed>>  $blocks  stats and list blocks
     * @param  list<array{label: string, url: string}>  $links
     * @param  list<int>  $memberIds
     */
    public function __construct(
        public readonly string $intent,
        public readonly string $answer,
        public readonly string $outcome,
        public readonly array $blocks = [],
        public readonly array $links = [],
        public readonly array $memberIds = [],
        public readonly ?string $answerFunction = null,
        ?CarbonImmutable $asOf = null,
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException("Unknown Linda outcome [{$outcome}].");
        }

        $this->asOf = ($asOf ?? CarbonImmutable::now())->setTimezone(LindaPeriod::timezone());
    }

    /**
     * @return array{intent: string, answer: string, blocks: list<array<string, mixed>>, links: list<array{label: string, url: string}>, as_of: string}
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'answer' => $this->answer,
            'blocks' => $this->blocks,
            'links' => $this->links,
            'as_of' => $this->asOf->toIso8601String(),
        ];
    }

    /**
     * A stats block item: money (`currency`, PHP) or a count (`number`).
     *
     * @return array{label: string, value: int|float, format: string, currency?: string}
     */
    public static function money(string $label, int|float|string $pesos): array
    {
        return ['label' => $label, 'value' => (float) $pesos, 'format' => 'currency', 'currency' => 'PHP'];
    }

    /** @return array{label: string, value: int, format: string} */
    public static function number(string $label, int $count): array
    {
        return ['label' => $label, 'value' => $count, 'format' => 'number'];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{type: string, title?: string, items: list<array<string, mixed>>}
     */
    public static function stats(array $items, ?string $title = null): array
    {
        return ['type' => 'stats', ...($title === null ? [] : ['title' => $title]), 'items' => $items];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{type: string, title: string, ordered: bool, items: list<array<string, mixed>>}
     */
    public static function list(string $title, array $items, bool $ordered = false): array
    {
        return ['type' => 'list', 'title' => $title, 'ordered' => $ordered, 'items' => $items];
    }
}

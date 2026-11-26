<?php

namespace App\Services\Linda;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One Linda answer. toArray() is the frontend's reply (lendyph-frontend
 * src/types/linda.ts `LindaReply`): `{intent, answer, blocks, links, as_of}`.
 * The rest is for the question log, never sent: the member and loan ids the
 * answer listed, the answer function that built it and how the question
 * ended.
 */
final class LindaReply
{
    public const OUTCOMES = [
        'answered', 'not_yet', 'unsupported', 'denied', 'unmatched',
        'unclear_period', 'period_refused', 'no_branch', 'ambiguous_branch',
        // A lookup with no member, loan or receipt named; none found; several found.
        'no_target', 'not_found', 'ambiguous_record', 'turned_off',
    ];

    /** When the figures were read, in Manila. */
    public readonly CarbonImmutable $asOf;

    /**
     * @param  list<array<string, mixed>>  $blocks  stats and list blocks
     * @param  list<array{label: string, url: string}>  $links
     * @param  list<int>  $memberIds
     * @param  list<int>  $loanIds
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
        public readonly array $loanIds = [],
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException("Unknown Linda outcome [{$outcome}].");
        }

        $this->asOf = ($asOf ?? CarbonImmutable::now())->setTimezone(LindaPeriod::timezone());
    }

    /**
     * This reply without the links `$drop` picks (Menu Visibility's hidden
     * pages): such a link leaves the links, and a row in a block keeps its
     * words but loses its link.
     *
     * @param  \Closure(string): bool  $drop
     */
    public function withoutLinks(\Closure $drop): self
    {
        $links = array_values(array_filter($this->links, fn (mixed $link): bool => ! (is_array($link) && is_string($link['url'] ?? null) && $drop($link['url']))));
        $blocks = self::withoutUrls($this->blocks, $drop);

        if ($links === $this->links && $blocks === $this->blocks) {
            return $this;
        }

        return new self($this->intent, $this->answer, $this->outcome, $blocks, $links, $this->memberIds, $this->answerFunction, $this->asOf, $this->loanIds);
    }

    /**
     * @param  array<mixed>  $value
     * @param  \Closure(string): bool  $drop
     * @return array<mixed>
     */
    private static function withoutUrls(array $value, \Closure $drop): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::withoutUrls($item, $drop);
            } elseif ($key === 'url' && is_string($item) && $drop($item)) {
                unset($value[$key]);
            }
        }

        return $value;
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

    /**
     * A whole percent: 87.5 reads "87.5%".
     *
     * @return array{label: string, value: float, format: string}
     */
    public static function percent(string $label, int|float $percent): array
    {
        return ['label' => $label, 'value' => (float) $percent, 'format' => 'percent'];
    }

    /**
     * A Manila calendar date, `YYYY-MM-DD`.
     *
     * @return array{label: string, value: string, format: string}
     */
    public static function date(string $label, string $date): array
    {
        return ['label' => $label, 'value' => $date, 'format' => 'date'];
    }

    /** @return array{label: string, value: string, format: string} */
    public static function text(string $label, string $text): array
    {
        return ['label' => $label, 'value' => $text, 'format' => 'text'];
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

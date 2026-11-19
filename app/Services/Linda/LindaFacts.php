<?php

namespace App\Services\Linda;

use App\Models\User;
use App\Services\Linda\LindaWording as W;

/**
 * The facts pack for an open question (round 3, LINDA_OPEN_QUESTIONS), and
 * the checks an answer written from it must pass before it is shown.
 *
 * The pack is built by the topic answers themselves (LindaAnswerer::answer()),
 * so every figure is the matching screen's, under the caller's permissions
 * and branches: a topic the caller can't see is left out. Only each answer's
 * summary block (`stats`) is kept, never its list rows, and a text item
 * (which could hold a name) is dropped. What's left is counts, ₱ totals,
 * rates and dates, written as the templates write them, with the period's
 * label and the branch scope's name. It has a fixed shape:
 *
 *   {as_of, branches, period, topics: [{topic, title, figures: [{label, value}]}]}
 *
 * The checks: every number in the answer appears in the pack (commas,
 * trailing zeros and the ₱ and % signs aside), no number is spelled out, and
 * no identifier appears (member, loan, receipt and other record numbers,
 * digit runs of 7 or more, emails and mobile numbers).
 */
final class LindaFacts
{
    /** The topics a pack reads, in this order. Performance rows and lookups are never read. */
    public const TOPICS = [
        LindaIntent::CollectionSummary,
        LindaIntent::CollectionEfficiency,
        LindaIntent::DisbursementSummary,
        LindaIntent::LoanSummary,
        LindaIntent::OverdueAccounts,
        LindaIntent::PortfolioSummary,
        LindaIntent::BorrowerSummary,
        LindaIntent::IncomeSummary,
        LindaIntent::ExpenseSummary,
        LindaIntent::SavingsSummary,
        LindaIntent::ShareCapitalSummary,
        LindaIntent::CdaSummary,
    ];

    /** Record numbers, by their prefixes, with a dash, a space or nothing before the digits. */
    private const RECORD_PATTERN = '/(?<![\pL\pN])(?:BRW|CMK|LA|LN|PN|SOA|RCP|SCV|SC|JE|ADJ|GC|SAV|SVT|SVI|TD|REQ|MC)\s*[-#]?\s*\d{2,}/iu';

    /** Any other code with a dash before 3 or more digits ("X-0001"). */
    private const CODE_PATTERN = '/(?<![\pL\pN])[A-Za-z]{1,5}(?:-[A-Za-z]{2,5})?-\d{3,}/u';

    private const EMAIL_PATTERN = '/[\pL\pN._%+\-]+@[\pL\pN.\-]+\.\pL{2,}/u';

    private const MOBILE_PATTERN = '/(?<!\d)(?:\+?63|0)[\s\-]?9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}(?!\d)/u';

    private const DIGIT_RUN_PATTERN = '/\d{7,}/u';

    /** A number as written: digits with thousands commas and decimals. */
    private const NUMBER_PATTERN = '/\d[\d,]*(?:\.\d+)?/u';

    /** Numbers spelled out, which the number check couldn't read. */
    private const NUMBER_WORDS = [
        'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen',
        'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty', 'thirty', 'forty', 'fifty',
        'sixty', 'seventy', 'eighty', 'ninety', 'hundred', 'thousand', 'million', 'billion', 'dozen', 'twice',
        'thrice', 'double', 'triple', 'half',
    ];

    public function __construct(private readonly LindaAnswerer $answerer) {}

    /**
     * The pack, and the links of the topics it read, by topic.
     *
     * @return array{facts: array{as_of: string, branches: string, period: string, topics: list<array{topic: string, title: string, figures: list<array{label: string, value: string}>}>}, links: array<string, list<array{label: string, url: string}>>}
     */
    public function build(User $user, LindaPeriod $period, ?string $branchName): array
    {
        $topics = [];
        $links = [];

        foreach (self::TOPICS as $intent) {
            $reply = $this->answerer->answer($user, $intent, $intent->takesPeriod() ? $period : null, $branchName);

            if ($reply->outcome !== 'answered') {
                continue;
            }

            $figures = [];
            $title = null;
            foreach ($reply->blocks as $block) {
                if (($block['type'] ?? null) !== 'stats') {
                    continue;
                }

                $title ??= $block['title'] ?? null;
                foreach ($block['items'] as $item) {
                    $value = self::shown($item);

                    if ($value !== null) {
                        $figures[] = ['label' => (string) $item['label'], 'value' => $value];
                    }
                }
            }

            if ($figures !== []) {
                $topics[] = ['topic' => $intent->value, 'title' => $title ?? ($intent->takesPeriod() ? self::periodWords($period) : W::asOfToday()), 'figures' => $figures];
                $links[$intent->value] = $reply->links;
            }
        }

        $scope = LindaScope::resolve($user, $branchName);

        return [
            'facts' => [
                'as_of' => W::today(),
                'branches' => $scope->ok() ? $scope->label : '',
                'period' => $period->label,
                'topics' => $topics,
            ],
            'links' => $links,
        ];
    }

    /**
     * Whether an answer may be shown: every number in it is in the pack, none
     * is spelled out, and it holds no identifier.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function fits(#[\SensitiveParameter] string $answer, array $facts): bool
    {
        if (self::hasIdentifier($answer)) {
            return false;
        }

        foreach (LindaText::words(LindaText::clean($answer)) as $word) {
            if (in_array($word, self::NUMBER_WORDS, true)) {
                return false;
            }
        }

        $allowed = self::numbers((string) json_encode($facts, JSON_UNESCAPED_UNICODE));

        return array_diff(self::numbers($answer), $allowed) === [];
    }

    /** Whether a text holds a record number, a long digit run, an email or a mobile number. */
    public static function hasIdentifier(#[\SensitiveParameter] string $text): bool
    {
        foreach ([self::RECORD_PATTERN, self::CODE_PATTERN, self::EMAIL_PATTERN, self::MOBILE_PATTERN, self::DIGIT_RUN_PATTERN] as $pattern) {
            if (preg_match($pattern, $text) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The topics whose figures an answer uses: any of their numbers appears
     * in it.
     *
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    public static function topicsUsed(#[\SensitiveParameter] string $answer, array $facts): array
    {
        $used = self::numbers($answer);
        $topics = [];

        foreach ($facts['topics'] as $topic) {
            $own = self::numbers(implode(' ', array_column($topic['figures'], 'value')));

            if (array_intersect($used, $own) !== []) {
                $topics[] = $topic['topic'];
            }
        }

        return $topics;
    }

    /**
     * The numbers in a text, as compared: commas dropped, and trailing zeros
     * after a decimal point dropped ("1,500.00" → "1500", "87.50" → "87.5").
     *
     * @return list<string>
     */
    private static function numbers(#[\SensitiveParameter] string $text): array
    {
        preg_match_all(self::NUMBER_PATTERN, $text, $found);

        $numbers = [];
        foreach ($found[0] as $number) {
            $plain = str_replace(',', '', rtrim($number, ','));

            if (str_contains($plain, '.')) {
                $plain = rtrim(rtrim($plain, '0'), '.');
            }

            $numbers[] = $plain;
        }

        return array_values(array_unique($numbers));
    }

    /**
     * A stats item as the templates write it, or null for a text item.
     *
     * @param  array<string, mixed>  $item
     */
    private static function shown(array $item): ?string
    {
        return match ($item['format'] ?? null) {
            'currency' => W::money($item['value']),
            'percent' => W::percent((float) $item['value']),
            'number' => number_format((int) $item['value']),
            'date' => W::date((string) $item['value']),
            default => null,
        };
    }

    private static function periodWords(LindaPeriod $period): string
    {
        return mb_strtoupper(mb_substr(W::during($period), 0, 1)).mb_substr(W::during($period), 1);
    }
}

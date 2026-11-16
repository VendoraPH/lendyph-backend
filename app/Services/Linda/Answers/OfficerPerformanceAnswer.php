<?php

namespace App\Services\Linda\Answers;

use App\Services\Linda\LindaIntent;
use Illuminate\Database\Eloquent\Builder;

/**
 * "How are our account officers doing?": the Performance report's officer
 * rows (`by_officer`, with loans that have no officer as their own
 * "Unassigned" row), ranked, with Past Due by the grace rule per officer.
 *
 * An officer the question names is looked for only among those rows: by
 * their full name anywhere in the question, or by one of their names right
 * after "officer", "AO", "si", "ni", "kay", "kang" or "of". A named officer
 * is shown alone; a name that fits no row answers with every row.
 */
final class OfficerPerformanceAnswer extends PerformanceAnswer
{
    private const MARKERS = 'officer|ao|si|ni|kay|kang|of';

    public function intent(): LindaIntent
    {
        return LindaIntent::OfficerPerformance;
    }

    protected function dimension(): string
    {
        return 'officer';
    }

    protected function plural(): string
    {
        return 'Account officers';
    }

    protected function narrowToRow(Builder $loans, array $row): void
    {
        $row['account_officer_id'] === null
            ? $loans->whereNull('loans.account_officer_id')
            : $loans->where('loans.account_officer_id', (int) $row['account_officer_id']);
    }

    protected function rowName(array $row): string
    {
        return (string) $row['account_officer_name'];
    }

    protected function named(array $rows, #[\SensitiveParameter] string $question): array
    {
        $text = ' '.trim((string) preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($question))).' ';
        $byFullName = [];
        $byOneName = [];

        foreach ($rows as $row) {
            if ($row['source']['account_officer_id'] === null) {
                continue;
            }

            $words = array_values(array_filter(preg_split('/[^\pL\pN]+/u', mb_strtolower($row['name'])) ?: []));

            if ($words === []) {
                continue;
            }

            if (str_contains($text, ' '.implode(' ', $words).' ')) {
                $byFullName[] = $row;

                continue;
            }

            foreach ($words as $word) {
                if (mb_strlen($word) >= 2 && preg_match('/ (?:'.self::MARKERS.') '.preg_quote($word, '/').' /u', $text) === 1) {
                    $byOneName[] = $row;
                    break;
                }
            }
        }

        return $byFullName !== [] ? $byFullName : $byOneName;
    }
}

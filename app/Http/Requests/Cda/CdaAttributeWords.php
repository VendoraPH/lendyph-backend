<?php

namespace App\Http\Requests\Cda;

/**
 * The names a CDA request's validation messages use for its fields, in lower
 * case words mid-sentence: "fields.0.override_reason" reads "override
 * reason", "submission.cda_reference_no" reads "CDA reference number" and
 * "borrower_id" reads "member". Messages a request writes itself are
 * unchanged; the API's field keys stay as they are.
 */
trait CdaAttributeWords
{
    /** @var array<string, string> a key's word => how it reads */
    private static array $attributeWordFixes = [
        'cda' => 'CDA',
        'cte' => 'CTE',
        'tin' => 'TIN',
        'url' => 'URL',
        'no' => 'number',
        'borrower' => 'member',
    ];

    /** @return array<string, string> */
    public function attributes(): array
    {
        $words = [];

        foreach (array_keys($this->rules()) as $key) {
            $words[$key] = self::attributeInWords((string) $key);
        }

        return $words;
    }

    private static function attributeInWords(string $key): string
    {
        $segments = array_values(array_filter(explode('.', $key), fn (string $segment) => $segment !== '*'));
        $parts = explode('_', (string) end($segments));

        if (count($parts) > 1 && end($parts) === 'id') {
            array_pop($parts);
        }

        return implode(' ', array_map(fn (string $part) => self::$attributeWordFixes[$part] ?? $part, $parts));
    }
}

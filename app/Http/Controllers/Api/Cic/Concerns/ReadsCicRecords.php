<?php

namespace App\Http\Controllers\Api\Cic\Concerns;

use App\Models\CicCapture;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The query parameters the CIC record screens share, validated in words:
 * `reference_date` (a live build's date: today by default, or a month's last
 * day within the last 92 days; 422 otherwise), `capture_id` (a capture to read
 * instead, any date), `record_type` and `page`.
 */
trait ReadsCicRecords
{
    public const RECORD_TYPES = ['ID', 'CI', 'CN', 'NE'];

    public const PER_PAGE = 25;

    /** How far back a live view may read a month end. */
    public const LIVE_DAYS_BACK = 92;

    public const LIVE_DATE_REFUSAL = 'Choose today, or the last day of a month within the last 92 days. Older months are read from their captures.';

    /** Today, or a month's last day within the last 92 days. */
    public static function liveDateAllowed(Carbon $date): bool
    {
        $today = Carbon::today();

        return $date->isSameDay($today)
            || ($date->isLastOfMonth() && $date->lessThan($today) && $date->greaterThanOrEqualTo($today->copy()->subDays(self::LIVE_DAYS_BACK)));
    }

    /**
     * @return array{reference_date: Carbon, capture: CicCapture|null, record_type: string|null, severity: string|null, page: int}
     */
    protected function recordFilters(Request $request): array
    {
        $data = $request->validate([
            'reference_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'capture_id' => ['nullable', 'integer', 'min:1'],
            'record_type' => ['nullable', Rule::in(self::RECORD_TYPES)],
            'severity' => ['nullable', Rule::in(['error', 'warning'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'reference_date.date_format' => 'The reference date must be a date written YYYY-MM-DD.',
            'reference_date.before_or_equal' => 'The reference date can\'t be after today.',
            'capture_id.integer' => 'Choose a capture from the list.',
            'capture_id.min' => 'Choose a capture from the list.',
            'record_type.in' => 'The record type must be ID, CI, CN or NE.',
            'severity.in' => 'The severity must be error or warning.',
            'page.integer' => 'The page must be a whole number.',
            'page.min' => 'The page must be 1 or more.',
        ]);

        $capture = null;
        $reference = isset($data['reference_date']) ? Carbon::createFromFormat('!Y-m-d', $data['reference_date']) : Carbon::today();

        // A live build is for today or a recent month end only (security
        // re-review SN1): each date is a full build, cached once.
        if (! isset($data['capture_id']) && ! self::liveDateAllowed($reference)) {
            throw ValidationException::withMessages(['reference_date' => self::LIVE_DATE_REFUSAL]);
        }

        if (isset($data['capture_id'])) {
            $capture = CicCapture::query()->find((int) $data['capture_id']);
            abort_if($capture === null, 404, 'Not found.');
        }

        return [
            'reference_date' => $reference,
            'capture' => $capture,
            'record_type' => $data['record_type'] ?? null,
            'severity' => $data['severity'] ?? null,
            'page' => (int) ($data['page'] ?? 1),
        ];
    }

    /**
     * A page envelope like the paginator's: `{data, meta: {current_page,
     * last_page, per_page, total}}`.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $extra  more `meta` keys
     * @return array<string, mixed>
     */
    protected function page(array $rows, int $total, int $page, array $extra = []): array
    {
        return [
            'data' => $rows,
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / self::PER_PAGE)),
                'per_page' => self::PER_PAGE,
                'total' => $total,
                ...$extra,
            ],
        ];
    }
}

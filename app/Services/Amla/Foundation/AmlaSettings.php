<?php

namespace App\Services\Amla\Foundation;

use App\Enums\PaymentMethod;
use App\Models\AmlaSettingsVersion;
use App\Services\Accounting\Money;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * The AMLA settings and their versions (AMLA design §4.6, §4.10, §4.15). Every
 * value starts "to verify": they are documented starting points, not legal
 * thresholds, until counsel confirms them. A change goes through an approved
 * change request (ChangeRequests) and adds a version; nothing is edited.
 *
 * - CTR: one transaction ABOVE `ctr_threshold` (₱500,000.00; exactly that is
 *   not a CTR), in a channel listed in `ctr_cash_channels` (cash by default),
 *   with no same-day sums.
 * - Deadlines, in working days counted from the day after: a CTR within
 *   `ctr_deadline_working_days` (5), an STR within `str_deadline_working_days`
 *   (1, the next working day).
 * - Customer review cycles in years by risk: high 1, medium 2, low 3.
 */
final class AmlaSettings
{
    /**
     * Each setting: its label (in words, shown on screen), a short name for
     * change summaries, and its unit (peso, channels, working_days, years).
     *
     * @var array<string, array{label: string, short: string, unit: string}>
     */
    public const DEFINITIONS = [
        'ctr_threshold' => ['label' => 'Covered transaction (CTR): one transaction above this amount', 'short' => 'CTR threshold', 'unit' => 'peso'],
        'ctr_cash_channels' => ['label' => 'Payment channels counted as cash for a CTR', 'short' => 'CTR cash channels', 'unit' => 'channels'],
        'ctr_deadline_working_days' => ['label' => 'A CTR is due within this many working days after the transaction', 'short' => 'CTR deadline', 'unit' => 'working_days'],
        'str_deadline_working_days' => ['label' => 'An STR is due within this many working days after the suspicion is established', 'short' => 'STR deadline', 'unit' => 'working_days'],
        'review_years_high' => ['label' => 'Customer review cycle for high risk, in years', 'short' => 'High risk review cycle', 'unit' => 'years'],
        'review_years_medium' => ['label' => 'Customer review cycle for medium risk, in years', 'short' => 'Medium risk review cycle', 'unit' => 'years'],
        'review_years_low' => ['label' => 'Customer review cycle for low risk, in years', 'short' => 'Low risk review cycle', 'unit' => 'years'],
    ];

    /** @var list<string> the channels a CTR may count as cash: the payment methods, without auto-pay */
    public const CHANNELS = ['cash', 'gcash', 'maya', 'bank_transfer', 'online'];

    /** @var array<string, array{0: int, 1: int}> each whole-number setting's limits */
    public const INTEGER_LIMITS = [
        'ctr_deadline_working_days' => [1, 60],
        'str_deadline_working_days' => [1, 30],
        'review_years_high' => [1, 10],
        'review_years_medium' => [1, 10],
        'review_years_low' => [1, 10],
    ];

    public const REVIEW_ORDER_MESSAGE = 'High risk is reviewed at least as often as medium risk, and medium at least as often as low.';

    /** The version in force now: the newest one. */
    public function current(): AmlaSettingsVersion
    {
        return AmlaSettingsVersion::query()->orderByDesc('version')->first()
            ?? throw new RuntimeException('The AMLA settings have no version. The AMLA foundation migration writes version 1.');
    }

    /**
     * The version in force at a moment: the newest one effective by then, else
     * version 1 (the starting points were in force from the start).
     */
    public function at(CarbonInterface $moment): AmlaSettingsVersion
    {
        return AmlaSettingsVersion::query()
            ->where('effective_at', '<=', $moment)
            ->orderByDesc('version')
            ->first()
            ?? AmlaSettingsVersion::query()->orderBy('version')->first()
            ?? throw new RuntimeException('The AMLA settings have no version. The AMLA foundation migration writes version 1.');
    }

    /**
     * A sent value as stored: money as a two-decimal string, channels as a
     * list in CHANNELS order, whole numbers as integers.
     */
    public static function normalise(string $key, mixed $value): mixed
    {
        return match (self::DEFINITIONS[$key]['unit']) {
            'peso' => self::money((int) Money::toCentavos((string) $value)),
            'channels' => array_values(array_intersect(self::CHANNELS, (array) $value)),
            default => (int) $value,
        };
    }

    /** A stored value in words: "₱500,000.00", "Cash, GCash", "5 working days", "1 year". */
    public static function valueWords(string $key, mixed $value): string
    {
        return match (self::DEFINITIONS[$key]['unit']) {
            'peso' => Money::format((int) Money::toCentavos((string) $value)),
            'channels' => implode(', ', array_map(fn (string $channel) => (string) PaymentMethod::labelFor($channel), (array) $value)),
            'working_days' => $value.' working '.((int) $value === 1 ? 'day' : 'days'),
            default => $value.' '.((int) $value === 1 ? 'year' : 'years'),
        };
    }

    /**
     * Whether the review cycles keep their order: high ≤ medium ≤ low.
     *
     * @param  array<string, mixed>  $values
     */
    public static function reviewOrderHolds(array $values): bool
    {
        return (int) $values['review_years_high'] <= (int) $values['review_years_medium']
            && (int) $values['review_years_medium'] <= (int) $values['review_years_low'];
    }

    private static function money(int $centavos): string
    {
        return sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
    }
}

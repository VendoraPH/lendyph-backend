<?php

namespace App\Services\Cda\Registers;

use App\Models\CdaOfficer;
use App\Models\CdaOfficerTraining;
use App\Models\CdaSetting;
use App\Services\Accounting\Money;

/**
 * Whether an officer has the trainings their position requires (CDA design
 * §6): the position's keys in `CdaSetting::required_trainings`, against the
 * officer's completed trainings with a matching `requirement_key`.
 *
 * Over a range (a reporting year, or one day for "today"), a requirement is
 * met by a training completed on or before the range's end that hadn't
 * expired before its start. Hours add up the trainings completed inside the
 * range. A position with no required trainings reads `not_required`.
 *
 * Reads the officer's `trainings` relation, so eager-load it for a list.
 */
final class TrainingCompliance
{
    public const COMPLETE = 'complete';

    public const INCOMPLETE = 'incomplete';

    public const NOT_REQUIRED = 'not_required';

    public const STATUS_WORDS = [self::COMPLETE => 'Complete', self::INCOMPLETE => 'Incomplete', self::NOT_REQUIRED => 'Not required'];

    /** @var array<string, list<string>>|null */
    private ?array $required = null;

    /** @return list<string> the training keys the position requires */
    public function requiredFor(string $position): array
    {
        $this->required ??= array_map(
            fn ($keys) => array_values(array_unique(array_filter((array) $keys, 'is_string'))),
            (array) (CdaSetting::current()->required_trainings ?? []),
        );

        return $this->required[$position] ?? [];
    }

    /**
     * @return array{required: list<string>, completed: list<string>, missing: list<string>, status: string, hours_centi: int}
     */
    public function evaluate(CdaOfficer $officer, string $from, string $to): array
    {
        $required = $this->requiredFor($officer->position);
        $met = [];
        $hours = 0;

        foreach ($officer->trainings as $training) {
            if (! $this->isCompleted($training)) {
                continue;
            }

            $completedOn = $training->completed_on->toDateString();

            if ($completedOn >= $from && $completedOn <= $to) {
                $hours += (int) Money::toCentavos((string) $training->hours);
            }

            $stillValid = $training->expires_on === null || $training->expires_on->toDateString() >= $from;

            if ($completedOn <= $to && $stillValid && in_array($training->requirement_key, $required, true)) {
                $met[$training->requirement_key] = true;
            }
        }

        $completed = array_values(array_filter($required, fn (string $key) => isset($met[$key])));
        $missing = array_values(array_diff($required, $completed));

        return [
            'required' => $required,
            'completed' => $completed,
            'missing' => $missing,
            'status' => $required === [] ? self::NOT_REQUIRED : ($missing === [] ? self::COMPLETE : self::INCOMPLETE),
            'hours_centi' => $hours,
        ];
    }

    /** A requirement key in words: "basic_accounting" → "Basic Accounting". */
    public static function keyInWords(string $key): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }

    private function isCompleted(CdaOfficerTraining $training): bool
    {
        return $training->status === 'completed' && $training->completed_on !== null;
    }
}

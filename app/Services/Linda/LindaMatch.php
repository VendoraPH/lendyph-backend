<?php

namespace App\Services\Linda;

/**
 * What the deterministic matcher understood in a question: the intent, the
 * period it names (null when it names none and the intent has no default, or
 * when a follow-up leaves the earlier period in place), the branch phrase as
 * the user wrote it (cleaned, never looked up), and whether it follows on
 * from the previous question.
 */
final class LindaMatch
{
    public function __construct(
        public readonly LindaIntent $intent,
        public readonly ?LindaPeriod $period,
        public readonly ?string $branchName,
        public readonly bool $followUp,
    ) {}

    /**
     * @return array{intent: string, period: ?array{from: string, to: string, label: string, key: ?string}, branch_name: ?string, follow_up: bool}
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent->value,
            'period' => $this->period?->toArray(),
            'branch_name' => $this->branchName,
            'follow_up' => $this->followUp,
        ];
    }
}

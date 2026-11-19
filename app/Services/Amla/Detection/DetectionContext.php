<?php

namespace App\Services\Amla\Detection;

use App\Services\Amla\Events\PesoAmount;

/**
 * What a rule reads besides the events: its own parameters (from the rule
 * version it runs under) and the CTR settings in force when the trigger was
 * recorded (AMLA-B1's versioned settings).
 */
final class DetectionContext
{
    /**
     * @param  array<string, mixed>  $params
     * @param  list<string>  $ctrChannels
     */
    public function __construct(
        public readonly array $params,
        public readonly string $ctrThreshold,
        public readonly array $ctrChannels,
        public readonly int $settingsVersion,
    ) {}

    public function int(string $key): int
    {
        return (int) $this->params[$key];
    }

    public function centavos(string $key): int
    {
        return PesoAmount::centavos((string) $this->params[$key]);
    }

    /** @return list<string> */
    public function channels(string $key = 'channels'): array
    {
        return array_values((array) $this->params[$key]);
    }

    public function thresholdCentavos(): int
    {
        return PesoAmount::centavos($this->ctrThreshold);
    }
}

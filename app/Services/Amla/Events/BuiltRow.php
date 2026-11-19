<?php

namespace App\Services\Amla\Events;

/**
 * What one source row becomes: the events to add (when not there yet), and the
 * events it marks reversed (a void or a reversal row).
 */
final class BuiltRow
{
    /**
     * @param  list<array<string, mixed>>  $events
     * @param  list<array{source: string, source_id: int, by: string, by_id: int, at: string}>  $reversals
     */
    public function __construct(
        public readonly array $events = [],
        public readonly array $reversals = [],
    ) {}
}

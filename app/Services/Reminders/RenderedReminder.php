<?php

namespace App\Services\Reminders;

final readonly class RenderedReminder
{
    /**
     * @param  list<string>  $unknownVariables  `{{names}}` the renderer does not know, left as written
     */
    public function __construct(
        public ?string $subject,
        public string $body,
        public array $unknownVariables,
    ) {}
}

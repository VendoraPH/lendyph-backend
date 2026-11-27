<?php

namespace App\Http\Requests\Concerns;

/**
 * The `reference_number` messages, in words, for every request that takes a
 * payment method and its reference: the release, extension "pay", Record
 * Payment and the share capital entries. Laravel's defaults ("The reference
 * number field is required unless method is in cash.") never reach the screen.
 */
trait ReferenceNumberMessages
{
    /**
     * @return array<string, string>
     */
    protected function referenceNumberMessages(): array
    {
        return [
            'reference_number.required_unless' => 'Enter the reference number. Only a cash payment can be saved without one.',
            'reference_number.string' => 'The reference number must be text.',
            'reference_number.max' => 'The reference number can be at most 100 characters.',
        ];
    }
}

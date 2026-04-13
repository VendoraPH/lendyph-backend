<?php

namespace App\Rules;

use App\Services\BorrowerDuplicateDetector;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a borrower create/update if a similar borrower already exists.
 *
 * Attached to the `first_name` field so the error surfaces there in the standard
 * `{errors: {first_name: [...]}}` validation envelope. Clients can pass
 * `force=true` in the request to bypass this rule (see StoreBorrowerRequest).
 */
class NoDuplicateBorrower implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    public function __construct(private readonly ?int $ignoreId = null) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $firstName = (string) ($this->data['first_name'] ?? $value ?? '');
        $middleName = $this->data['middle_name'] ?? null;
        $lastName = (string) ($this->data['last_name'] ?? '');
        $birthdate = $this->data['birthdate'] ?? null;

        if ($firstName === '' || $lastName === '') {
            return;
        }

        $duplicate = app(BorrowerDuplicateDetector::class)->findDuplicate(
            firstName: $firstName,
            middleName: $middleName,
            lastName: $lastName,
            birthdate: $birthdate ? (string) $birthdate : null,
            ignoreId: $this->ignoreId,
        );

        if (! $duplicate) {
            return;
        }

        $dob = $duplicate->birthdate?->toDateString() ?? 'unknown DOB';
        $fail(sprintf(
            'A similar borrower already exists: %s (%s, born %s). Pass force=true to create anyway.',
            $duplicate->full_name,
            $duplicate->borrower_code,
            $dob,
        ));
    }
}

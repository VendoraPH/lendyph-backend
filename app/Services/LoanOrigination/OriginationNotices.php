<?php

namespace App\Services\LoanOrigination;

use App\Models\OriginationNotice;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * The texts an applicant accepts, by version (origination decision 9): the
 * newest published version of each type is its current one. Publishing adds
 * a version and never changes one, so every consent keeps pointing at the
 * exact text it accepted (by version and SHA-256 of the text).
 *
 * The privacy notice, terms and declaration are required before a link can
 * take an application; the credit authorization is optional.
 */
final class OriginationNotices
{
    public function current(string $type): ?OriginationNotice
    {
        return OriginationNotice::query()->where('type', $type)->orderByDesc('id')->first();
    }

    /** @return array<string, ?OriginationNotice> */
    public function currentAll(): array
    {
        $current = [];

        foreach (OriginationNotice::TYPES as $type) {
            $current[$type] = $this->current($type);
        }

        return $current;
    }

    /**
     * The required notices with no published version, in words.
     *
     * @return list<string>
     */
    public function missingRequired(): array
    {
        return array_values(array_map(
            fn (string $type) => OriginationNotice::TYPE_LABELS[$type],
            array_filter(OriginationNotice::REQUIRED_TYPES, fn (string $type) => $this->current($type) === null),
        ));
    }

    /**
     * @throws ValidationException 422 on `version` when the type already has it
     */
    public function publish(string $type, string $version, string $body, ?string $title, bool $final, User $user): OriginationNotice
    {
        if (OriginationNotice::query()->where('type', $type)->where('version', $version)->exists()) {
            throw self::duplicate($type, $version);
        }

        try {
            return OriginationNotice::query()->create([
                'type' => $type,
                'version' => $version,
                'title' => $title,
                'body' => $body,
                'body_hash' => hash('sha256', $body),
                'is_final' => $final,
                'published_by' => $user->id,
                'published_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw self::duplicate($type, $version);
        }
    }

    private static function duplicate(string $type, string $version): ValidationException
    {
        return ValidationException::withMessages([
            'version' => sprintf('%s already has a version %s. Give the new text its own version.', OriginationNotice::TYPE_LABELS[$type], $version),
        ]);
    }
}

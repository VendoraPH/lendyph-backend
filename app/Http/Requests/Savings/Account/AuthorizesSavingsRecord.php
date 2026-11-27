<?php

namespace App\Http\Requests\Savings\Account;

use App\Services\Savings\Api\AccountScope;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Authorization for the accounts and teller requests (slice S2), in the
 * order the API answers: the permission first (403), then the `{id}` record
 * in the caller's branches (404, the same as a missing one), and only then
 * the body's validation (422). So another branch's record never answers 422.
 *
 * The record found is kept for the controller ({@see self::record()}).
 */
trait AuthorizesSavingsRecord
{
    private ?Model $savingsRecord = null;

    /**
     * @param  string|null  $permission  null when any signed-in user may try (the desk checks who)
     * @param  string|null  $parameter  the route parameter holding the id
     * @param  string|null  $finder  the AccountScope method that finds it (account, hold, transaction, approval)
     */
    protected function authorizeRecord(?string $permission, ?string $parameter = null, ?string $finder = null): bool
    {
        $user = $this->user();

        if ($user === null || ($permission !== null && ! $user->can($permission))) {
            return false;
        }

        if ($parameter !== null && $finder !== null) {
            $this->savingsRecord = app(AccountScope::class)->{$finder}($user, (int) $this->route($parameter));
        }

        return true;
    }

    public function record(): Model
    {
        return $this->savingsRecord ?? throw new LogicException('This request has no savings record.');
    }

    /** @return array<string, string> */
    protected function dateMessages(string $field, string $words): array
    {
        return [
            "{$field}.required" => "Enter the {$words}.",
            "{$field}.date_format" => "The {$words} must be written as YYYY-MM-DD.",
            "{$field}.before_or_equal" => "The {$words} can't be after today.",
            "{$field}.after_or_equal" => "The {$words} can be today or yesterday only: back-dating is allowed until the next morning.",
        ];
    }

    /** @return list<string> today or yesterday, Manila */
    protected function businessDate(): array
    {
        return ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString(), 'after_or_equal:'.now()->subDay()->toDateString()];
    }
}

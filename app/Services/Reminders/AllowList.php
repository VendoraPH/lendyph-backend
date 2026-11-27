<?php

namespace App\Services\Reminders;

use App\Models\BorrowerNotificationPreference;
use App\Support\PhilippineMobile;

/**
 * Who a driver may be handed a reminder for. Unless
 * `reminders.allow_all_recipients` is on, only the staff numbers and
 * addresses in `reminders.allow_list`; with the default empty list, nobody.
 */
final class AllowList
{
    public function permits(string $channel, string $recipient): bool
    {
        if (config('reminders.allow_all_recipients') === true) {
            return true;
        }

        $wanted = $channel === 'email'
            ? BorrowerNotificationPreference::normaliseEmail($recipient)
            : PhilippineMobile::normalise($recipient);

        return $wanted !== null && in_array($wanted, $this->entries($channel), true);
    }

    /**
     * @return list<string>
     */
    private function entries(string $channel): array
    {
        $entries = [];

        foreach ((array) config('reminders.allow_list', []) as $entry) {
            $entry = (string) $entry;
            $isEmail = str_contains($entry, '@');

            if ($isEmail !== ($channel === 'email')) {
                continue;
            }

            $normalised = $isEmail ? BorrowerNotificationPreference::normaliseEmail($entry) : PhilippineMobile::normalise($entry);

            if ($normalised !== null) {
                $entries[] = $normalised;
            }
        }

        return $entries;
    }
}

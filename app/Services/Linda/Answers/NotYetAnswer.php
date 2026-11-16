<?php

namespace App\Services\Linda\Answers;

use App\Models\User;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaWording;

/**
 * A question Linda understood but doesn't answer yet (income, efficiency,
 * member lookup, …): a fixed reply, with a link to the matching report when
 * there is one and the user can open it. Never a second model call.
 */
final class NotYetAnswer
{
    public static function for(LindaIntent $intent, ?User $user = null): LindaReply
    {
        $links = LindaLinks::forNotYet($user, $intent);

        return new LindaReply(
            $intent->value,
            $links === [] ? LindaWording::notYet() : LindaWording::notYetWithReport(),
            'not_yet',
            links: $links,
            answerFunction: class_basename(self::class),
        );
    }
}

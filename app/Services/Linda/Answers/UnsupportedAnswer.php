<?php

namespace App\Services\Linda\Answers;

use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaWording;

/**
 * A question outside lending figures: the fixed refusal, with no figures
 * and no links.
 */
final class UnsupportedAnswer
{
    public static function reply(): LindaReply
    {
        return new LindaReply(
            LindaIntent::UnsupportedQuestion->value,
            LindaWording::unsupported(),
            'unsupported',
            answerFunction: class_basename(self::class),
        );
    }
}

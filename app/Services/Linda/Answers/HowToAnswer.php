<?php

namespace App\Services\Linda\Answers;

use App\Models\User;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaMenu;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaWording;

/**
 * "How do I add a member?", "Where is the trial balance?": where the page is
 * in the side menu (LindaMenu), with a link to it. Only pages the caller may
 * open are named; when every page found is closed to them, the reply says
 * so in words. A how or where question about nothing in the menu (one the
 * model read as how_to) gets the "not yet" reply with what Linda answers.
 * Never free text, and never a figure, so no branch scope applies.
 */
final class HowToAnswer
{
    public function answer(User $user, #[\SensitiveParameter] ?string $question): LindaReply
    {
        $pages = $question === null ? null : LindaMenu::find($question);

        if ($pages === null) {
            return new LindaReply(LindaIntent::HowTo->value, LindaWording::notYet(), 'not_yet', answerFunction: $this->name());
        }

        $open = array_values(array_filter($pages, fn (array $page) => LindaMenu::canOpen($user, $page)));

        if ($open === []) {
            return new LindaReply(LindaIntent::HowTo->value, LindaWording::menuDenied(), 'denied', answerFunction: $this->name());
        }

        return new LindaReply(
            LindaIntent::HowTo->value,
            LindaWording::menuPlaces(array_column($open, 'path')),
            'answered',
            links: array_map(fn (array $page) => ['label' => 'Open '.$page['title'], 'url' => $page['url']], $open),
            answerFunction: $this->name(),
        );
    }

    public function name(): string
    {
        return class_basename(self::class);
    }
}

<?php

namespace App\Services\Linda;

use App\Services\MenuVisibility\MenuModules;
use App\Services\MenuVisibility\MenuVisibility;

/**
 * What Menu Visibility hides, as Linda reads it (finish run round 6): a topic
 * whose module is hidden gets a short local answer, never the model, a figure
 * or a link, and no answer links or names a hidden page. Read through the
 * MenuVisibility service, from this deployment's own database and default
 * cache (LindaStaysInItsDeploymentArchTest).
 */
final class LindaVisibility
{
    public function __construct(private readonly MenuVisibility $visibility) {}

    /** @return list<string> the hidden modules, submenus of a hidden menu included */
    public function hidden(): array
    {
        return $this->visibility->hidden();
    }

    public function hides(?string $module): bool
    {
        return $module !== null && in_array($module, $this->hidden(), true);
    }

    public function hidesIntent(LindaIntent $intent): bool
    {
        return $this->hides($intent->module());
    }

    /** Whether a page of the app (a link's URL) belongs to a hidden module. */
    public function hidesPage(string $url): bool
    {
        return $this->hides(MenuModules::forPage($url));
    }

    /** The short local answer for a hidden module's topic: no figure, no link. */
    public function turnedOff(LindaIntent $intent): LindaReply
    {
        return new LindaReply($intent->value, LindaWording::turnedOff(), 'turned_off', answerFunction: 'MenuVisibility');
    }

    /** The reply without links to a hidden page, in its links and in its blocks' rows. */
    public function withoutHiddenLinks(LindaReply $reply): LindaReply
    {
        $hidden = $this->hidden();

        return $hidden === [] ? $reply : $reply->withoutLinks(fn (string $url): bool => in_array(MenuModules::forPage($url), $hidden, true));
    }
}

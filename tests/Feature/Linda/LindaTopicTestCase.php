<?php

namespace Tests\Feature\Linda;

use App\Models\User;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;

/**
 * Round 3's topic answers: the answerer, and readers for a reply's stats,
 * list rows and links.
 */
abstract class LindaTopicTestCase extends LindaAnswersTestCase
{
    protected function ask(User $user, LindaIntent $intent, ?LindaPeriod $period = null, ?string $branch = null, ?string $question = null): LindaReply
    {
        return app(LindaAnswerer::class)->answer($user, $intent, $period, $branch, $question);
    }

    /** @return array<string, mixed> every stats item, by label */
    protected function stats(LindaReply $reply): array
    {
        $values = [];
        foreach ($reply->blocks as $block) {
            foreach ($block['type'] === 'stats' ? $block['items'] : [] as $item) {
                $values[$item['label']] = $item['value'];
            }
        }

        return $values;
    }

    /** @return array<string, array<string, mixed>> list rows' fields, by row title */
    protected function rows(LindaReply $reply): array
    {
        $rows = [];
        foreach ($reply->blocks as $block) {
            foreach ($block['type'] === 'list' ? $block['items'] : [] as $item) {
                $rows[$item['title']] = array_column($item['fields'], 'value', 'label');
            }
        }

        return $rows;
    }

    /** @return list<string> */
    protected function linkUrls(LindaReply $reply): array
    {
        return array_column($reply->links, 'url');
    }
}

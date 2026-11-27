<?php

namespace App\Services\Cda\Sources;

/**
 * A family of CDA sources (CDA design §2). One class per family in
 * SourceCatalog::FAMILIES, named `{Family}Source` in this namespace
 * (`share_capital` → ShareCapitalSource) and resolved from the container,
 * so constructor injection works.
 *
 * Rules for an implementation:
 * - `$params` arrive checked and defaulted (SourceCatalog::normalize());
 *   read dates through `$context->date($params['as_of'])`.
 * - Run unscoped: CDA reports cover the whole cooperative, never a branch.
 * - Read only; never write.
 * - Compute money in centavos and return it as a 2-decimal string; counts
 *   as int; percents as 2-decimal strings; tables as lists of rows.
 * - Return `provisional: true` when the figure may still change (an
 *   accounting year not closed, a live figure where a capture should be).
 * - Throw SourceUnavailable for a key the family doesn't know.
 */
interface CdaSource
{
    /** @param  array<string, mixed>  $params */
    public function resolve(string $key, array $params, SourceContext $context): SourceResult;
}

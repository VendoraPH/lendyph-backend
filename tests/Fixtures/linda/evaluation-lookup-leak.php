<?php

/**
 * A two-case question set for EvaluateLindaTest: a lookup the lookup parser
 * can't read (no marker, no name, no record number), which would reach the
 * model, so `linda:evaluate --set` must fail the run.
 *
 * @return list<array{question: string, earlier: list<array{string, string}>, intent: string, period: string|null, branch: ?string, language: string}>
 */
return [
    ['question' => 'How much has the member we spoke about paid so far?', 'earlier' => [], 'intent' => 'borrower_lookup', 'period' => null, 'branch' => null, 'language' => 'en'],
    ['question' => 'How much have we collected this month?', 'earlier' => [], 'intent' => 'collection_summary', 'period' => 'this_month', 'branch' => null, 'language' => 'en'],
];

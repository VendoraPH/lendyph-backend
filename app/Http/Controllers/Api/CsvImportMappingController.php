<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Traits\ResolvesImportRuns;
use App\Http\Controllers\Controller;
use App\Models\CsvImportRun;
use App\Models\LoanProduct;
use App\Services\AuditLogService;
use App\Services\CsvImport\ProductMappingResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * The product-mapping gate.
 *
 * A CSV names its loan products as free text. `loans.loan_product_id` is a NOT
 * NULL foreign key, `loan_products.name` is not unique, and nothing in this
 * codebase resolves a product by name — so until a human says which
 * LoanProduct each legacy string becomes, not one loan row can be written. This
 * controller is that conversation, and confirming it is an accountability
 * record: it is the decision that fixes the rate, method, penalty and fee
 * schedule an entire legacy cohort will be carried on from now on.
 */
class CsvImportMappingController extends Controller
{
    /** The run id is resolved AFTER the permission check — see the trait. */
    use ResolvesImportRuns;

    public function __construct(private ProductMappingResolver $resolver) {}

    #[OA\Get(
        path: '/api/imports/{run}/product-mapping',
        summary: 'Legacy product strings, candidate loan products, and what will disagree',
        description: 'Returns every distinct Loan Product string in the run\'s staged loan rows with a row count, '
            .'every loan product on this deployment, an exact-match suggestion per string, and the compatibility '
            .'warnings for the product each string would land on.',
        tags: ['CSV Import'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Product mapping state'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(int $run): JsonResponse
    {
        $this->authorize('imports:process');

        return response()->json(['data' => $this->resolver->payload($this->importRun($run))]);
    }

    #[OA\Put(
        path: '/api/imports/{run}/product-mapping',
        summary: 'Confirm which loan product each legacy product string becomes',
        description: 'Body is an object of CSV product string to loan_products.id, optionally wrapped in a '
            .'`product_mapping` key. Every distinct string carried by an importable row must be present, and every '
            .'id must exist. No loan product is ever created from a name.',
        tags: ['CSV Import'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object', example: ['Regular Loan' => 1, 'REGULAR' => 1]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Mapping confirmed'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 409, description: 'Run is not in a state where the mapping can be set'),
            new OA\Response(response: 422, description: 'Unmapped strings, or an id that does not exist'),
        ],
    )]
    public function update(Request $request, int $run): JsonResponse
    {
        $this->authorize('imports:process');

        $run = $this->importRun($run);

        if (in_array($run->phase, ['completed', 'cancelled'], true)) {
            return response()->json([
                'message' => "This import is {$run->phase}. Its product mapping is the record of what was written and cannot be changed.",
            ], 409);
        }

        $loansFile = $run->loansFile;

        if ($loansFile === null) {
            return response()->json([
                'message' => 'No loans file has been uploaded for this import yet, so there is nothing to map.',
            ], 409);
        }

        /**
         * The body is read and bounded BEFORE the loans file is scanned.
         * distinctProductStrings() is a GROUP BY over a JSON expression — a
         * full scan of the widest table in the schema — and running it ahead of
         * this check meant any garbage body bought a full scan before earning
         * its 422.
         */
        $submitted = $this->submittedMapping($request);

        if (count($submitted) > ProductMappingResolver::MAX_CSV_PRODUCT_STRINGS) {
            throw ValidationException::withMessages([
                'product_mapping' => [
                    'A mapping may cover at most '.ProductMappingResolver::MAX_CSV_PRODUCT_STRINGS
                    .' product names; this one has '.count($submitted).'.',
                ],
            ]);
        }

        $mapping = ProductMappingResolver::normalizeStoredMapping($submitted);

        $entries = $this->resolver->distinctProductStrings($loansFile);

        if ($entries === []) {
            return response()->json([
                'message' => 'The loans file has not been staged yet, so its product names are not known. Stage the file first.',
            ], 409);
        }

        if (ProductMappingResolver::isTruncated($entries)) {
            /**
             * At or above the cap, the Loan Product column is almost certainly
             * not the Loan Product column — a column-shifted file yields one
             * distinct "product" per row, because the cell being read is
             * somebody's phone number. Confirming a mapping over that would
             * put a real product id on rows nobody has actually looked at.
             */
            return response()->json([
                'message' => 'This file has at least '.ProductMappingResolver::MAX_CSV_PRODUCT_STRINGS
                    .' distinct Loan Product values, which almost always means its columns are shifted. '
                    .'Check the file against the expected column order before mapping it.',
            ], 409);
        }

        /**
         * Anything that did not survive normalisation was not a positive
         * integer id. Named separately from a nonexistent id, because "3.5" and
         * "3" fail for genuinely different reasons and the admin fixes them
         * differently.
         */
        $rejected = array_diff_key($submitted, array_flip(ProductMappingResolver::mappingKeys($mapping)));

        if ($rejected !== []) {
            $described = [];

            foreach ($rejected as $csvValue => $value) {
                $shown = is_scalar($value) ? var_export($value, true) : get_debug_type($value);
                $described[] = ($csvValue === '' ? '(blank)' : '"'.$csvValue.'"').' => '.$shown;
            }

            throw ValidationException::withMessages([
                'product_mapping' => ['Each value must be a loan product id. These are not: '.implode(', ', array_slice($described, 0, 20)).'.'],
            ]);
        }

        /**
         * The literal `exists:loan_products,id` rule, run over the ids as a flat
         * list. Validating the submitted object directly would put the CSV
         * strings into validation rule keys, where a product legitimately named
         * "Reg. Loan" or "Loan *" is read as dot/wildcard notation and silently
         * validates the wrong thing.
         */
        Validator::make(
            ['loan_product_ids' => array_values(array_unique(array_values($mapping)))],
            ['loan_product_ids.*' => ['required', 'integer', 'exists:loan_products,id']],
            [],
            ['loan_product_ids.*' => 'loan product'],
        )->validate();

        $unmapped = $this->resolver->unmapped(array_values($entries), $mapping);

        if ($unmapped !== []) {
            /**
             * Blocking, not a warning. An unmapped string is a row that cannot
             * be written at all — and the tempting alternative, creating a
             * product from the name, would invent a rate, an interest method, a
             * term, fees, a penalty rate and a grace period that nobody chose
             * and that every loan in the cohort would then be carried on.
             */
            throw ValidationException::withMessages([
                'product_mapping' => [
                    'Every loan product name in the file must be mapped to an existing loan product before this import can run. '
                    .'Still unmapped: '.implode(', ', array_map(
                        fn (string $value): string => $value === '' ? '(blank)' : '"'.$value.'"',
                        $unmapped,
                    )).'.',
                ],
                'unmapped' => $unmapped,
            ]);
        }

        $known = ProductMappingResolver::mappingKeys(array_column($entries, 'csv_value', 'csv_value'));
        $ignored = array_values(array_diff(ProductMappingResolver::mappingKeys($mapping), $known));

        $previous = ProductMappingResolver::normalizeStoredMapping($run->product_mapping);
        $products = LoanProduct::query()->whereIn('id', array_values($mapping))->get()->keyBy('id');

        /**
         * Loans already written under a previous mapping are NOT retroactively
         * moved — the loan rows carry their product id, and changing this map
         * only changes what happens next. Surfaced rather than blocked: a run
         * that failed part-way may need exactly this fix to resume.
         */
        $alreadyWritten = DB::table('csv_import_rows')
            ->where('csv_import_file_id', $loansFile->id)
            ->whereIn('result', ['imported', 'already_imported'])
            ->count();

        /**
         * Only keys the file actually carries are stored. `$ignored` is
         * reported back so a client can see what was dropped, but persisting a
         * mapping for names that are not in the file would let an unbounded
         * request body become unbounded state in a JSON column and an unbounded
         * `new_values` payload in the audit trail.
         */
        $stored = array_diff_key($mapping, array_flip($ignored));

        /**
         * The phase guard is part of the WRITE, not a read a hundred lines and
         * several full scans earlier. The importer runs on the queue and can
         * finish inside that window; without this condition the update would
         * still land and silently rewrite the accountability record for loans
         * that have already been created under the previous mapping.
         *
         * The mapping is all that moves. `phase` belongs to the import
         * processor's state machine, and a run sitting in `awaiting_mapping`
         * with a complete `product_mapping` is the signal it reads to go on.
         * Advancing it from here would mean this controller deciding whether
         * the customers file is finished — a question it cannot answer — and
         * two writers would then own one column.
         */
        $written = CsvImportRun::query()
            ->whereKey($run->getKey())
            ->whereNotIn('phase', ['completed', 'cancelled'])
            ->update(['product_mapping' => $stored]);

        if ($written === 0) {
            return response()->json([
                'message' => 'This import finished while the mapping was being confirmed. Its product mapping is the record of what was written and cannot be changed.',
            ], 409);
        }

        AuditLogService::log(
            action: 'import_product_mapping_confirmed',
            auditable: $run,
            oldValues: ['product_mapping' => $previous === [] ? null : $previous],
            newValues: [
                'product_mapping' => $stored,
                'loan_product_names' => array_map(
                    fn (int $id): string => (string) ($products[$id]->name ?? "#{$id}"),
                    $stored,
                ),
                'loans_already_written_under_previous_mapping' => $alreadyWritten,
            ],
            description: $this->auditDescription($run, $stored, $products),
        );

        $payload = $this->resolver->payload($run->refresh());
        $payload['ignored_keys'] = $ignored;
        $payload['loans_already_written_under_previous_mapping'] = $alreadyWritten;

        return response()->json([
            'message' => $this->confirmationMessage($payload),
            'data' => $payload,
        ]);
    }

    /**
     * The submitted map, accepting both the bare object the contract specifies
     * and the same object wrapped in `product_mapping` for clients that cannot
     * send a bare one.
     *
     * @return array<array-key, mixed>
     */
    private function submittedMapping(Request $request): array
    {
        /**
         * The BODY only. `$request->all()` merges the query string in, so
         * `PUT .../product-mapping?Regular=1` would inject "Regular" => 1 as a
         * confirmed mapping key — a stray link parameter becoming persisted
         * state about which product a cohort of loans lands on.
         */
        $body = $request->isJson() ? $request->json()->all() : $request->post();

        if (! is_array($body)) {
            $body = [];
        }

        foreach (['product_mapping', 'mapping'] as $wrapper) {
            if (array_key_exists($wrapper, $body) && is_array($body[$wrapper])) {
                return $body[$wrapper];
            }
        }

        if ($body === []) {
            throw ValidationException::withMessages([
                'product_mapping' => ['Send an object of CSV product name to loan product id.'],
            ]);
        }

        return $body;
    }

    /**
     * @param  array<string, int>  $mapping
     * @param  Collection<int, LoanProduct>  $products
     */
    private function auditDescription(CsvImportRun $run, array $mapping, $products): string
    {
        $pairs = [];

        foreach ($mapping as $csvValue => $productId) {
            $name = $products[$productId]->name ?? "#{$productId}";
            $pairs[] = ($csvValue === '' ? '(blank)' : "\"{$csvValue}\"")." -> {$name} (#{$productId})";
        }

        $description = "CSV import #{$run->id}: product mapping confirmed — ".implode('; ', $pairs);

        return mb_strlen($description) > 1000 ? mb_substr($description, 0, 997).'...' : $description;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function confirmationMessage(array $payload): string
    {
        $totals = $payload['totals'];
        $message = 'Product mapping confirmed for '.$totals['csv_product_strings'].' legacy product name(s).';

        if ($totals['rows_with_interest_method_disagreement'] > 0) {
            /**
             * Said out loud because nothing downstream will say it. The importer
             * writes the CSV's interest method onto the loan — that is the
             * legacy contract, and it is deliberate — but every loan originated
             * here takes its method from the product, so these loans
             * permanently disagree with theirs, and
             * DisclosureService::generateDisclosure() prints the product name
             * and the loan's method in the same block.
             */
            $message .= ' '.$totals['rows_with_interest_method_disagreement']
                .' loan(s) carry an interest method that differs from their mapped product and will permanently'
                .' disagree with it, including on their disclosure statement.';
        }

        if ($totals['rows_outside_product_bounds'] > 0) {
            $message .= ' '.$totals['rows_outside_product_bounds']
                .' loan(s) fall outside their mapped product\'s amount, term or rate range and will be imported anyway.';
        }

        return $message;
    }
}

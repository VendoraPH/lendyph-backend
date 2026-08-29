<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CsvImport\StoreCsvImportRunRequest;
use App\Http\Requests\CsvImport\UploadCsvImportChunkRequest;
use App\Models\CsvImportRun;
use App\Services\CsvImport\CsvImportUploadService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use OpenApi\Attributes as OA;
use RuntimeException;

/**
 * The upload half of the CSV migration importer.
 *
 * Opening a run, receiving its files a chunk at a time, and concatenating those
 * chunks back into the exact bytes the client held. Everything after assembly —
 * staging, product mapping, the import itself, the error report — lives
 * elsewhere; this controller's job ends the moment both files are on the
 * private disk and provably intact.
 */
class CsvImportController extends Controller
{
    public function __construct(private readonly CsvImportUploadService $uploads) {}

    #[OA\Post(
        path: '/api/imports',
        summary: 'Open a CSV migration import run',
        description: 'Declares the two files about to be uploaded and returns the server-chosen chunk size plus the number of chunks each file must be cut into. Refuses (409) while another run is still in progress.',
        tags: ['CSV Import'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['branch_id', 'files'],
                properties: [
                    new OA\Property(property: 'branch_id', type: 'integer', description: 'Branch every imported member and loan is assigned to. Required — a legacy export cannot supply it and loans.branch_id is NOT NULL.', example: 1),
                    new OA\Property(property: 'as_of_date', type: 'string', format: 'date', nullable: true, description: 'Date the extract represents. Defaults to today (Asia/Manila).', example: '2026-08-01'),
                    new OA\Property(
                        property: 'files',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'customers', type: 'object', properties: [
                                new OA\Property(property: 'filename', type: 'string', example: 'members.csv'),
                                new OA\Property(property: 'size_bytes', type: 'integer', example: 1048576),
                                new OA\Property(property: 'sha256', type: 'string', example: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'),
                            ]),
                            new OA\Property(property: 'loans', type: 'object', properties: [
                                new OA\Property(property: 'filename', type: 'string', example: 'loans.csv'),
                                new OA\Property(property: 'size_bytes', type: 'integer', example: 2097152),
                                new OA\Property(property: 'sha256', type: 'string'),
                            ]),
                        ],
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Run opened; returns chunk_size and total_chunks per file'),
            new OA\Response(response: 403, description: 'Requires the imports:process permission'),
            new OA\Response(response: 409, description: 'Another run is still in progress'),
            new OA\Response(response: 422, description: 'Validation failed (missing branch_id, oversize file, bad digest)'),
        ],
    )]
    public function store(StoreCsvImportRunRequest $request): JsonResponse
    {
        $this->authorize('imports:process');

        ['run' => $run, 'warning' => $warning, 'reclaimed_run_id' => $reclaimedRunId] = $this->uploads->createRun(
            $request->validated(),
            $request->user()?->id,
            $request->ip(),
        );

        return response()->json([
            'message' => 'Import run opened. Upload each file in chunks of the returned chunk_size.',
            'warning' => $warning,
            // Non-null when a previous run had been abandoned mid-upload and
            // was reclaimed to free this slot. The operator whose browser died
            // is entitled to know their run was discarded rather than find it
            // silently gone.
            'reclaimed_run_id' => $reclaimedRunId,
            ...$this->uploads->runPayload($run),
        ], 201);
    }

    #[OA\Put(
        path: '/api/imports/{run}/files/{kind}/chunks/{index}',
        summary: 'Upload one chunk of an import file',
        description: <<<'TXT'
        Idempotent. Re-sending a chunk whose digest matches the one already stored is a 200 no-op, which is what makes a dropped connection free to retry. Sending DIFFERENT bytes for an index already held is a 409 — the client resumed against a different file.

        Two body encodings are accepted, over either PUT or POST: `multipart/form-data` with a `chunk` part and a `sha256` field, or the raw chunk bytes as the request body with the digest in an `X-Chunk-Sha256` header. The raw form avoids ~410 bytes of multipart framing per chunk.
        TXT,
        tags: ['CSV Import'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['chunk', 'sha256'],
                    properties: [
                        new OA\Property(property: 'chunk', type: 'string', format: 'binary'),
                        new OA\Property(property: 'sha256', type: 'string', description: 'SHA-256 of this chunk alone, 64 lowercase hex characters'),
                    ],
                ),
            ),
        ),
        parameters: [
            new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['customers', 'loans'])),
            new OA\Parameter(name: 'index', in: 'path', required: true, description: 'Zero-based chunk index', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Chunk already held with this digest — no-op'),
            new OA\Response(response: 201, description: 'Chunk verified and stored'),
            new OA\Response(response: 403, description: 'Requires the imports:process permission'),
            new OA\Response(response: 404, description: 'No such run, or the run has no file of this kind'),
            new OA\Response(response: 409, description: 'This index is already held with a different digest, or the run is past the uploading phase'),
            new OA\Response(response: 422, description: 'Digest did not match the bytes received, or the chunk is the wrong size'),
        ],
    )]
    public function uploadChunk(UploadCsvImportChunkRequest $request, CsvImportRun $run, string $kind, int $index): JsonResponse
    {
        $this->authorize('imports:process');

        $file = $this->uploads->fileFor($run, $kind);

        [$sourcePath, $temporaryStream] = $this->resolveChunkBytes(
            $request,
            $this->uploads->chunkByteCeiling($file, $index),
        );

        try {
            $result = $this->uploads->storeChunk(
                $file,
                $index,
                (string) $request->validated('sha256'),
                $sourcePath,
            );
        } finally {
            // tmpfile() handles unlink itself on close, so the received bytes
            // never outlive the request that carried them.
            if (is_resource($temporaryStream)) {
                fclose($temporaryStream);
            }
        }

        $chunk = $result['chunk'];

        /**
         * Deliberately does NOT report progress.
         *
         * This is the hot path — a 100 MiB export is two hundred of these — and
         * `missing_chunks` costs a query per call to compute. The client knows
         * which chunks it has sent; the authoritative answer after an
         * interruption is GET /api/imports/{run}, which is called once on
         * resume rather than once per part.
         */
        return response()->json([
            'message' => $result['status'] === 'duplicate'
                ? 'Chunk already received; nothing was rewritten.'
                : 'Chunk verified and stored.',
            'status' => $result['status'],
            'chunk_index' => $chunk->chunk_index,
            'size_bytes' => $chunk->size_bytes,
            'sha256' => $chunk->sha256,
        ], $result['status'] === 'duplicate' ? 200 : 201);
    }

    #[OA\Post(
        path: '/api/imports/{run}/assemble',
        summary: 'Concatenate a run\'s chunks and verify the whole-file digests',
        description: 'Concatenates every chunk of both files in index order, hashes each assembled file and compares it to the digest declared when the run was opened. On success the chunks are deleted and the run moves to phase `assembled`. On a mismatch the assembled file is deleted and the chunks are KEPT, so the client can re-send specific chunks instead of the whole export.',
        tags: ['CSV Import'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Both files assembled and verified'),
            new OA\Response(response: 403, description: 'Requires the imports:process permission'),
            new OA\Response(response: 409, description: 'The run is past the point where it can be assembled'),
            new OA\Response(response: 422, description: 'Chunks are still missing, or an assembled file did not match its declared digest'),
        ],
    )]
    public function assemble(CsvImportRun $run): JsonResponse
    {
        $this->authorize('imports:process');

        return response()->json([
            'message' => 'Both files assembled and verified against their declared digests.',
            ...$this->uploads->assemble($run),
        ]);
    }

    #[OA\Delete(
        path: '/api/imports/{run}',
        summary: 'Cancel an import run and free what it was holding',
        description: <<<'TXT'
        Moves the run to `cancelled`, deletes its chunks and any assembled file from the private disk, and frees the slot so a new import can be started.

        Only permitted from `uploading`, `assembled` or `awaiting_mapping` — the phases before anything has been written to the members and loans tables. From `staging` or either importing phase it is refused with 409, and the response names the phases it would have accepted. Idempotent: cancelling an already-cancelled run is a 200 no-op.
        TXT,
        tags: ['CSV Import'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Run cancelled and its storage released'),
            new OA\Response(response: 403, description: 'Requires the imports:process permission'),
            new OA\Response(response: 409, description: 'The run has progressed past the point where cancelling is safe'),
        ],
    )]
    public function destroy(CsvImportRun $run): JsonResponse
    {
        $this->authorize('imports:process');

        $result = $this->uploads->cancelRun($run);

        return response()->json([
            'message' => $result['cancelled']
                ? 'Import run cancelled. Its uploaded data has been deleted and a new run can now be started.'
                : 'Import run was already cancelled.',
            ...$result,
            ...$this->uploads->runPayload($run->fresh()), // @phpstan-ignore-line
        ]);
    }

    /**
     * Find the received bytes, whichever way the client sent them.
     *
     *   - a multipart `chunk` part, with the digest in a `sha256` field
     *   - the raw request body, with the digest in an `X-Chunk-Sha256` header
     *
     * A multipart body on a genuine PUT does work here, but not because PHP
     * parses it: PHP still populates `$_FILES` only for requests whose method
     * is literally POST. It works because symfony/http-foundation calls PHP
     * 8.4's `request_parse_body()` from `createFromGlobals()` for PUT, PATCH,
     * DELETE and QUERY, and hands the result to the Request object. Verified
     * against a real server on PHP 8.4.1, both encodings, POST and PUT.
     *
     * That is a dependency-level guarantee rather than a language-level one,
     * and worth writing down: composer.lock pins symfony/http-foundation to a
     * version that itself requires PHP >= 8.4.1, so it holds on every box this
     * application can run on — but anything here that reached for `$_FILES`
     * directly, or any downgrade of that package, would silently see an empty
     * file bag on PUT and nothing else would explain why.
     *
     * The raw form is the cheaper one on the wire regardless: it carries none
     * of the ~410 bytes of multipart framing that pushed a 1 MiB chunk over the
     * frontend vhost's inherited 1 MiB `client_max_body_size` and 413'd it on
     * every retry.
     *
     * @return array{0: string, 1: resource|null} local path holding the bytes, and a temp stream to close
     */
    private function resolveChunkBytes(Request $request, int $maxBytes): array
    {
        $uploaded = $request->file('chunk');

        if ($uploaded instanceof UploadedFile) {
            // getRealPath() resolves symlinks and returns false for a path that
            // does not exist; getPathname() is the raw temp path PHP gave us.
            return [$uploaded->getRealPath() ?: $uploaded->getPathname(), null];
        }

        /**
         * A multipart body that nothing managed to parse.
         *
         * An oversized part does NOT land here — it arrives as a present but
         * invalid UploadedFile and the `file` rule rejects it first, on POST
         * and PUT alike. This is the residue: a malformed body, or a SAPI with
         * `enable_post_data_reading` off, where `request_parse_body()` threw and
         * the file bag was left empty. Falling through to the raw-body branch
         * would hash the multipart framing along with the chunk and report a
         * digest mismatch, sending the client off to debug the wrong problem.
         */
        if (str_contains(strtolower((string) $request->header('Content-Type')), 'multipart/form-data')) {
            throw new HttpResponseException(response()->json([
                'message' => 'This request carried a multipart body that could not be parsed, so it has no readable `chunk` part. Re-send it, or send the raw chunk bytes as the request body with an `X-Chunk-Sha256` header instead.',
            ], 422));
        }

        $body = $request->getContent(true);

        if (! is_resource($body)) {
            throw new HttpResponseException(response()->json([
                'message' => 'No chunk was received.',
            ], 422));
        }

        $temporary = tmpfile();

        if ($temporary === false) {
            throw new RuntimeException('Unable to open a temporary file for the received chunk.');
        }

        /**
         * Streamed, and bounded.
         *
         * Streamed because the body is a chunk-sized blob and this runs once
         * per chunk of every import on the box. Bounded because nothing has
         * checked its size yet: the multipart path is capped by PHP and by the
         * form request, but a raw PUT of `application/octet-stream` is refused
         * by `request_parse_body()` without being read, so PHP never applies
         * `post_max_size` to it and the only ceiling left is nginx's 25M. One
         * byte over the limit is enough to detect it and stop.
         */
        $copied = stream_copy_to_stream($body, $temporary, $maxBytes + 1);

        if ($copied !== false && $copied > $maxBytes) {
            fclose($temporary);

            throw new HttpResponseException(response()->json([
                'message' => "This chunk is larger than the {$maxBytes} bytes expected at this index.",
                'expected_size_bytes' => $maxBytes,
            ], 413));
        }

        $path = stream_get_meta_data($temporary)['uri'];

        // fstat() rather than filesize(): PHP caches stat results per path, and
        // php-fpm reuses a worker process across requests.
        if ((int) (fstat($temporary)['size'] ?? 0) === 0) {
            fclose($temporary);

            throw new HttpResponseException(response()->json([
                'message' => 'No chunk was received. Send the chunk as a multipart `chunk` part over POST, or as the raw body of a PUT.',
            ], 422));
        }

        return [$path, $temporary];
    }
}

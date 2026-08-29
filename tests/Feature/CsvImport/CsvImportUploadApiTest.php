<?php

namespace Tests\Feature\CsvImport;

use App\Models\CsvImportFile;
use App\Models\CsvImportFileChunk;
use App\Models\CsvImportRun;
use App\Models\Role;
use App\Models\User;
use App\Services\CsvImport\CsvImportUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The resumable chunked upload: POST /api/imports, the chunk PUT, and assemble.
 *
 * The properties pinned here are the ones that decide whether a coop can
 * actually get its data in over a connection that drops. A chunk that lands
 * twice must be free, a chunk that lands wrong must be loud, and a failed
 * assembly must leave the parts behind so the client re-sends bytes rather than
 * an entire membership register.
 */
class CsvImportUploadApiTest extends TestCase
{
    use SetupLendyPH;

    /**
     * 64 KiB — the service's floor, so the fixtures stay small while still
     * producing several chunks with a short final one.
     */
    private const CHUNK = 65536;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        Storage::fake('private');
        Storage::fake('public');

        config(['imports.chunk_size' => self::CHUNK]);
    }

    /**
     * Deterministic filler, so a failure shows the same bytes every run.
     */
    private function payload(int $bytes, string $seed): string
    {
        $out = '';
        $counter = 0;

        while (strlen($out) < $bytes) {
            $out .= hash('sha256', $seed.$counter++);
        }

        return substr($out, 0, $bytes);
    }

    /**
     * @return array<int, string>
     */
    private function slice(string $content): array
    {
        return str_split($content, self::CHUNK);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function openRunPayload(string $customers, string $loans, array $overrides = []): array
    {
        return array_replace([
            'branch_id' => $this->branch->id,
            'files' => [
                'customers' => [
                    'filename' => 'members.csv',
                    'size_bytes' => strlen($customers),
                    'sha256' => hash('sha256', $customers),
                ],
                'loans' => [
                    'filename' => 'loans.csv',
                    'size_bytes' => strlen($loans),
                    'sha256' => hash('sha256', $loans),
                ],
            ],
        ], $overrides);
    }

    /**
     * A chunk as a browser sends one: multipart, over POST.
     *
     * PUT works identically — verified against a real server — but only via
     * symfony/http-foundation's `request_parse_body()` call, which the test
     * client bypasses entirely by building the Request object itself. So this
     * helper proves the handler, not the parsing; see
     * test_a_put_whose_multipart_body_could_not_be_parsed_says_so.
     */
    private function sendChunk(int $runId, string $kind, int $index, string $bytes, ?string $digest = null)
    {
        return $this->post("/api/imports/{$runId}/files/{$kind}/chunks/{$index}", [
            'sha256' => $digest ?? hash('sha256', $bytes),
            'chunk' => UploadedFile::fake()->createWithContent("chunk{$index}.part", $bytes),
        ], ['Accept' => 'application/json']);
    }

    private function sendRawChunk(int $runId, string $kind, int $index, string $bytes, ?string $digest = null)
    {
        return $this->call(
            'PUT',
            "/api/imports/{$runId}/files/{$kind}/chunks/{$index}",
            [], [], [],
            $this->transformHeadersToServerVars([
                'Accept' => 'application/json',
                'Content-Type' => 'application/octet-stream',
                'X-Chunk-Sha256' => $digest ?? hash('sha256', $bytes),
            ]),
            $bytes,
        );
    }

    /** Open a run and upload both files in full. */
    private function uploadBothFiles(string $customers, string $loans): int
    {
        $runId = $this->postJson('/api/imports', $this->openRunPayload($customers, $loans))
            ->assertCreated()
            ->json('run.id');

        foreach (['customers' => $customers, 'loans' => $loans] as $kind => $content) {
            foreach ($this->slice($content) as $index => $bytes) {
                $this->sendChunk($runId, $kind, $index, $bytes)->assertCreated();
            }
        }

        return $runId;
    }

    // ---------------------------------------------------------------- opening

    public function test_the_advertised_chunk_size_is_configuration_not_a_literal(): void
    {
        // The client uploads at whatever size this endpoint advertises, so the
        // default is a deployment-wide decision: 512 KiB, sized against the
        // smallest limit in the frontend-nginx -> Next -> API-nginx -> PHP
        // chain rather than against PHP alone. Read from the config file rather
        // than the container, which setUp() has already overridden.
        $shipped = require config_path('imports.php');

        $this->assertSame(512 * 1024, (int) $shipped['chunk_size']);

        config(['imports.chunk_size' => 128 * 1024]);

        $customers = $this->payload(300000, 'customers');
        $loans = $this->payload(10, 'loans');

        $response = $this->postJson('/api/imports', $this->openRunPayload($customers, $loans))
            ->assertCreated();

        $response->assertJsonPath('chunk_size', 128 * 1024)
            ->assertJsonPath('files.customers.chunk_size', 128 * 1024)
            // ceil(300000 / 131072)
            ->assertJsonPath('files.customers.total_chunks', 3)
            ->assertJsonPath('files.loans.total_chunks', 1);
    }

    public function test_total_chunks_is_computed_by_the_server_and_cannot_be_declared_by_the_client(): void
    {
        $customers = $this->payload(self::CHUNK * 3 + 1, 'customers');

        /**
         * The client never supplies `total_chunks`; it declares only the file's
         * size and digest, and the server derives the count from the chunk size
         * it chose itself. That is what makes an absurd count structurally
         * impossible rather than something to validate against — a client
         * cannot declare 2,000,000 chunks for a 300 KB file, because it cannot
         * declare a chunk count at all.
         *
         * The `array:` rule makes that explicit rather than merely ignoring the
         * key, so a client that tries learns it is not part of the contract.
         */
        $payload = $this->openRunPayload($customers, 'x');
        $payload['files']['customers']['total_chunks'] = 2_000_000;

        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('files.customers');

        $this->assertSame(0, CsvImportRun::count());

        // And the derived value is exactly ceil(size / chunk_size).
        $this->postJson('/api/imports', $this->openRunPayload($customers, 'x'))
            ->assertCreated()
            ->assertJsonPath('files.customers.total_chunks', (int) ceil(strlen($customers) / self::CHUNK))
            ->assertJsonPath('files.customers.total_chunks', 4);
    }

    public function test_branch_id_is_required_to_open_a_run(): void
    {
        $payload = $this->openRunPayload('a', 'b');
        unset($payload['branch_id']);

        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('branch_id');

        $this->assertSame(0, CsvImportRun::count());
    }

    public function test_a_file_larger_than_the_import_limit_is_refused_before_any_bytes_are_sent(): void
    {
        $service = app(CsvImportUploadService::class);

        $payload = $this->openRunPayload('a', 'b');
        $payload['files']['customers']['size_bytes'] = $service->maxFileBytes() + 1;

        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('files.customers.size_bytes');

        $this->assertSame(0, CsvImportRun::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_a_second_run_is_refused_while_one_is_still_open(): void
    {
        $first = $this->postJson('/api/imports', $this->openRunPayload('a', 'b'))
            ->assertCreated()
            ->json('run.id');

        $this->postJson('/api/imports', $this->openRunPayload('c', 'd'))
            ->assertStatus(409)
            ->assertJsonPath('run_id', $first)
            ->assertJsonPath('phase', 'uploading');

        $this->assertSame(1, CsvImportRun::count());
    }

    public function test_a_completed_run_warns_but_does_not_block_a_new_one(): void
    {
        $first = $this->postJson('/api/imports', $this->openRunPayload('a', 'b'))
            ->assertCreated()
            ->json('run.id');

        CsvImportRun::whereKey($first)->update(['phase' => 'completed']);

        $response = $this->postJson('/api/imports', $this->openRunPayload('c', 'd'))
            ->assertCreated();

        $this->assertNotNull($response->json('warning'));
        $this->assertSame(2, CsvImportRun::count());
    }

    public function test_the_first_run_on_a_deployment_carries_no_warning(): void
    {
        $this->postJson('/api/imports', $this->openRunPayload('a', 'b'))
            ->assertCreated()
            ->assertJsonPath('warning', null);
    }

    // ------------------------------------------------------------- permission

    public function test_a_loan_officer_may_not_open_a_run(): void
    {
        $officer = User::factory()->create(['branch_id' => $this->branch->id]);
        $officer->assignRole(Role::where('name', 'loan_officer')->firstOrFail());

        $this->actingAs($officer)
            ->postJson('/api/imports', $this->openRunPayload('a', 'b'))
            ->assertForbidden();

        $this->assertSame(0, CsvImportRun::count());
    }

    public function test_a_loan_officer_may_not_upload_a_chunk_or_assemble(): void
    {
        $runId = $this->postJson('/api/imports', $this->openRunPayload('a', 'b'))
            ->assertCreated()
            ->json('run.id');

        $officer = User::factory()->create(['branch_id' => $this->branch->id]);
        $officer->assignRole(Role::where('name', 'loan_officer')->firstOrFail());

        $this->actingAs($officer);

        $this->sendChunk($runId, 'customers', 0, 'a')->assertForbidden();
        $this->postJson("/api/imports/{$runId}/assemble")->assertForbidden();

        $this->assertSame(0, CsvImportFileChunk::count());
    }

    public function test_admin_and_super_admin_may_run_an_import(): void
    {
        foreach (['admin', 'super_admin'] as $roleName) {
            CsvImportRun::query()->delete();

            $user = User::factory()->create(['branch_id' => $this->branch->id]);
            $user->assignRole(Role::where('name', $roleName)->firstOrFail());

            $this->actingAs($user)
                ->postJson('/api/imports', $this->openRunPayload('a', 'b'))
                ->assertCreated();
        }
    }

    // ------------------------------------------------------------------ chunks

    public function test_a_chunk_is_stored_once_and_replaying_it_is_a_free_no_op(): void
    {
        $customers = $this->payload(self::CHUNK * 2, 'customers');
        $runId = $this->postJson('/api/imports', $this->openRunPayload($customers, 'x'))
            ->assertCreated()
            ->json('run.id');

        $first = $this->slice($customers)[0];

        $this->sendChunk($runId, 'customers', 0, $first)
            ->assertCreated()
            ->assertJsonPath('status', 'stored');

        $stored = CsvImportFileChunk::firstOrFail();

        // The whole point of the idempotency: a client that never learned
        // whether its PUT landed may simply send it again.
        $this->sendChunk($runId, 'customers', 0, $first)
            ->assertOk()
            ->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, CsvImportFileChunk::count());

        $after = CsvImportFileChunk::firstOrFail();
        $this->assertSame($stored->id, $after->id);
        $this->assertEquals($stored->updated_at, $after->updated_at, 'A replayed chunk must not rewrite the row.');
    }

    public function test_a_different_digest_for_an_index_already_held_is_a_conflict(): void
    {
        $customers = $this->payload(self::CHUNK * 2, 'customers');
        $runId = $this->postJson('/api/imports', $this->openRunPayload($customers, 'x'))
            ->assertCreated()
            ->json('run.id');

        $chunks = $this->slice($customers);

        $this->sendChunk($runId, 'customers', 0, $chunks[0])->assertCreated();

        // Same slot, different content — the client resumed against a different
        // local file. Accepting it would splice two exports into one CSV that
        // still parses and still imports as real people.
        $other = $this->payload(self::CHUNK, 'a-different-export');

        $this->sendChunk($runId, 'customers', 0, $other)
            ->assertStatus(409)
            ->assertJsonPath('stored_sha256', hash('sha256', $chunks[0]))
            ->assertJsonPath('received_sha256', hash('sha256', $other));

        $this->assertSame(
            hash('sha256', $chunks[0]),
            CsvImportFileChunk::firstOrFail()->sha256,
            'The stored chunk must survive a conflicting resend.',
        );
    }

    public function test_a_chunk_whose_bytes_do_not_match_its_declared_digest_is_refused(): void
    {
        $customers = $this->payload(self::CHUNK * 2, 'customers');
        $runId = $this->postJson('/api/imports', $this->openRunPayload($customers, 'x'))
            ->assertCreated()
            ->json('run.id');

        $chunks = $this->slice($customers);

        // A byte flipped in transit: the right length, the wrong content. This
        // is a different failure from the 409 above and gets a different
        // status, because the fix is different — resend this one part.
        $corrupted = $chunks[0];
        $corrupted[10] = $corrupted[10] === 'a' ? 'b' : 'a';

        $this->sendChunk($runId, 'customers', 0, $corrupted, hash('sha256', $chunks[0]))
            ->assertStatus(422)
            ->assertJsonPath('declared_sha256', hash('sha256', $chunks[0]))
            ->assertJsonPath('received_sha256', hash('sha256', $corrupted));

        $this->assertSame(0, CsvImportFileChunk::count());
        $this->assertSame([], Storage::disk('private')->allFiles(), 'Bytes must be verified before they are stored.');
    }

    public function test_a_chunk_of_the_wrong_length_is_refused(): void
    {
        $customers = $this->payload(self::CHUNK * 2, 'customers');
        $runId = $this->postJson('/api/imports', $this->openRunPayload($customers, 'x'))
            ->assertCreated()
            ->json('run.id');

        $short = substr($this->slice($customers)[0], 0, 1000);

        $this->sendChunk($runId, 'customers', 0, $short)
            ->assertStatus(422)
            ->assertJsonPath('expected_size_bytes', self::CHUNK)
            ->assertJsonPath('received_size_bytes', 1000);
    }

    public function test_a_chunk_index_past_the_end_of_the_file_is_refused(): void
    {
        $customers = $this->payload(self::CHUNK, 'customers');
        $runId = $this->postJson('/api/imports', $this->openRunPayload($customers, 'x'))
            ->assertCreated()
            ->json('run.id');

        $this->sendChunk($runId, 'customers', 5, $customers)
            ->assertStatus(422)
            ->assertJsonPath('total_chunks', 1);
    }

    public function test_a_chunk_may_be_sent_as_the_raw_body_of_a_real_put(): void
    {
        $customers = $this->payload(self::CHUNK * 2, 'customers');
        $runId = $this->postJson('/api/imports', $this->openRunPayload($customers, 'x'))
            ->assertCreated()
            ->json('run.id');

        $first = $this->slice($customers)[0];

        $this->sendRawChunk($runId, 'customers', 0, $first)
            ->assertCreated()
            ->assertJsonPath('status', 'stored')
            ->assertJsonPath('sha256', hash('sha256', $first));

        $this->assertSame(1, CsvImportFileChunk::count());
    }

    public function test_a_put_whose_multipart_body_could_not_be_parsed_says_so(): void
    {
        $runId = $this->postJson('/api/imports', $this->openRunPayload('a', 'b'))
            ->assertCreated()
            ->json('run.id');

        /**
         * A multipart request that arrives with an empty file bag.
         *
         * On this stack that means `request_parse_body()` threw — a malformed
         * body, or a SAPI with `enable_post_data_reading` off — because
         * symfony/http-foundation parses PUT bodies through it and an
         * oversized part comes through as a present-but-invalid file instead.
         * Whatever the cause, the handler must not fall through to the
         * raw-body branch: that would hash the multipart framing along with the
         * chunk and report a digest mismatch, pointing the client at entirely
         * the wrong problem.
         */
        $response = $this->call(
            'PUT',
            "/api/imports/{$runId}/files/customers/chunks/0",
            ['sha256' => hash('sha256', 'a')],
            [], [],
            $this->transformHeadersToServerVars([
                'Accept' => 'application/json',
                'Content-Type' => 'multipart/form-data; boundary=----boundary',
            ]),
            '------boundary--',
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('could not be parsed', (string) $response->json('message'));
        $this->assertSame(0, CsvImportFileChunk::count());
    }

    public function test_chunks_are_refused_once_the_run_has_moved_past_uploading(): void
    {
        $customers = $this->payload(self::CHUNK, 'customers');
        $loans = $this->payload(100, 'loans');

        $runId = $this->uploadBothFiles($customers, $loans);
        $this->postJson("/api/imports/{$runId}/assemble")->assertOk();

        $this->sendChunk($runId, 'customers', 0, $customers)
            ->assertStatus(409)
            ->assertJsonPath('phase', 'assembled');
    }

    // ---------------------------------------------------------- missing chunks

    public function test_missing_chunks_are_reported_after_a_partial_upload(): void
    {
        $customers = $this->payload(self::CHUNK * 3 + 500, 'customers');
        $loans = $this->payload(100, 'loans');

        $runId = $this->postJson('/api/imports', $this->openRunPayload($customers, $loans))
            ->assertCreated()
            ->assertJsonPath('files.customers.total_chunks', 4)
            ->assertJsonPath('files.customers.missing_chunks', [0, 1, 2, 3])
            ->json('run.id');

        $chunks = $this->slice($customers);

        // A dropped connection: 0 and 2 landed, 1 and 3 did not.
        $this->sendChunk($runId, 'customers', 0, $chunks[0])->assertCreated();
        $this->sendChunk($runId, 'customers', 2, $chunks[2])->assertCreated();

        $payload = app(CsvImportUploadService::class)->runPayload(CsvImportRun::findOrFail($runId));

        $this->assertSame([1, 3], $payload['files']['customers']['missing_chunks']);
        $this->assertSame(2, $payload['files']['customers']['received_chunks']);
        $this->assertSame([0], $payload['files']['loans']['missing_chunks']);
        $this->assertFalse($payload['files']['customers']['assembled']);

        /**
         * The list is capped for the wire; the COUNT never is. The status
         * endpoint is polled throughout an upload, so a client that reads a
         * shortened list must still be told exactly how much work is left —
         * a truncated list costs it another poll, never a wrong answer.
         */
        $this->assertSame(2, $payload['files']['customers']['missing_chunk_count']);
        $this->assertFalse($payload['files']['customers']['missing_chunks_truncated']);
    }

    public function test_assembly_refuses_a_run_that_is_short_a_chunk_and_names_it(): void
    {
        $customers = $this->payload(self::CHUNK * 2, 'customers');
        $loans = $this->payload(100, 'loans');

        $runId = $this->postJson('/api/imports', $this->openRunPayload($customers, $loans))
            ->assertCreated()
            ->json('run.id');

        $chunks = $this->slice($customers);
        $this->sendChunk($runId, 'customers', 0, $chunks[0])->assertCreated();
        $this->sendChunk($runId, 'loans', 0, $loans)->assertCreated();

        $this->postJson("/api/imports/{$runId}/assemble")
            ->assertStatus(422)
            ->assertJsonPath('missing_chunks.customers', [1])
            ->assertJsonPath('missing_chunk_counts.customers', 1)
            ->assertJsonPath('missing_chunks_truncated.customers', false);

        $this->assertSame('uploading', CsvImportRun::findOrFail($runId)->phase);
    }

    // ---------------------------------------------------------------- assembly

    public function test_chunks_sent_out_of_order_assemble_into_the_original_file(): void
    {
        $customers = $this->payload(self::CHUNK * 3 + 4321, 'customers');
        $loans = $this->payload(self::CHUNK + 7, 'loans');

        $runId = $this->postJson('/api/imports', $this->openRunPayload($customers, $loans))
            ->assertCreated()
            ->json('run.id');

        // Deliberately scrambled. Concurrent uploads arrive in whatever order
        // the network hands them over, and nothing may depend on that order —
        // assembly reads `chunk_index` out of the database, never a directory
        // listing.
        foreach ([3, 0, 2, 1] as $index) {
            $this->sendChunk($runId, 'customers', $index, $this->slice($customers)[$index])->assertCreated();
        }

        foreach ([1, 0] as $index) {
            $this->sendChunk($runId, 'loans', $index, $this->slice($loans)[$index])->assertCreated();
        }

        $this->postJson("/api/imports/{$runId}/assemble")
            ->assertOk()
            ->assertJsonPath('run.phase', 'assembled')
            ->assertJsonPath('files.customers.assembled', true)
            ->assertJsonPath('files.loans.assembled', true)
            ->assertJsonPath('files.customers.missing_chunks', []);

        $customersFile = CsvImportFile::where('csv_import_run_id', $runId)->where('kind', 'customers')->firstOrFail();
        $loansFile = CsvImportFile::where('csv_import_run_id', $runId)->where('kind', 'loans')->firstOrFail();

        $this->assertSame($customers, Storage::disk('private')->get($customersFile->assembled_path));
        $this->assertSame($loans, Storage::disk('private')->get($loansFile->assembled_path));
        $this->assertSame(hash('sha256', $customers), $customersFile->assembled_sha256);
    }

    public function test_a_successful_assembly_deletes_the_chunks(): void
    {
        $customers = $this->payload(self::CHUNK * 2, 'customers');
        $loans = $this->payload(100, 'loans');

        $runId = $this->uploadBothFiles($customers, $loans);

        $this->assertSame(3, CsvImportFileChunk::count());

        $this->postJson("/api/imports/{$runId}/assemble")->assertOk();

        $this->assertSame(0, CsvImportFileChunk::count());

        $remaining = Storage::disk('private')->allFiles();
        $this->assertCount(2, $remaining, 'Only the two assembled files should remain: '.implode(', ', $remaining));
    }

    public function test_the_assembled_file_lands_on_the_private_disk_and_never_the_public_one(): void
    {
        $customers = $this->payload(self::CHUNK + 12, 'customers');
        $loans = $this->payload(100, 'loans');

        $runId = $this->uploadBothFiles($customers, $loans);
        $this->postJson("/api/imports/{$runId}/assemble")->assertOk();

        $file = CsvImportFile::where('csv_import_run_id', $runId)->where('kind', 'customers')->firstOrFail();

        Storage::disk('private')->assertExists($file->assembled_path);

        /**
         * These files are a whole cooperative's membership register — names,
         * birthdates, incomes. The public disk is served straight off the
         * filesystem by nginx with no authentication at all, so nothing to do
         * with an import may ever touch it.
         */
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(CsvImportUploadService::DISK, 'private');
    }

    public function test_a_whole_file_digest_mismatch_rejects_assembly_and_leaves_the_chunks_intact(): void
    {
        $customers = $this->payload(self::CHUNK * 2, 'customers');
        $loans = $this->payload(100, 'loans');

        // Every chunk will verify individually; only the whole-file digest the
        // run was opened with is wrong. That is what a lost or duplicated part
        // looks like from here.
        $payload = $this->openRunPayload($customers, $loans);
        $declared = hash('sha256', 'a completely different export');
        $payload['files']['customers']['sha256'] = $declared;

        $runId = $this->postJson('/api/imports', $payload)->assertCreated()->json('run.id');

        foreach ($this->slice($customers) as $index => $bytes) {
            $this->sendChunk($runId, 'customers', $index, $bytes)->assertCreated();
        }

        $this->sendChunk($runId, 'loans', 0, $loans)->assertCreated();

        $response = $this->postJson("/api/imports/{$runId}/assemble")
            ->assertStatus(422)
            ->assertJsonPath('kind', 'customers')
            ->assertJsonPath('declared_sha256', $declared)
            ->assertJsonPath('assembled_sha256', hash('sha256', $customers));

        $this->assertNotSame($response->json('declared_sha256'), $response->json('assembled_sha256'));

        /**
         * The chunks stay. A whole-file mismatch is fixed by re-sending
         * specific parts; deleting them would turn a recoverable state into a
         * full re-upload of a coop's entire membership over a mobile link.
         */
        $customersFile = CsvImportFile::where('csv_import_run_id', $runId)->where('kind', 'customers')->firstOrFail();

        $this->assertSame(2, $customersFile->chunks()->count());
        $this->assertNull($customersFile->assembled_path);
        $this->assertSame('uploading', CsvImportRun::findOrFail($runId)->phase);

        foreach ($customersFile->chunks as $chunk) {
            Storage::disk('private')->assertExists($chunk->path);
        }

        /**
         * Nothing is published at all. Files are assembled in `kind` order and
         * the first mismatch aborts the call, so a run whose customers file
         * fails verification never gets as far as writing its loans file — the
         * client fixes the bad chunks and calls assemble again, and anything
         * that did succeed is skipped on the retry rather than redone.
         */
        $this->assertSame(
            [],
            Storage::disk('private')->files("csv-imports/{$runId}"),
            'A failed assembly must leave no assembled file behind.',
        );
    }

    public function test_two_requests_racing_the_same_index_resolve_through_the_unique_index(): void
    {
        $customers = $this->payload(self::CHUNK * 2, 'customers');
        $runId = $this->postJson('/api/imports', $this->openRunPayload($customers, 'x'))
            ->assertCreated()
            ->json('run.id');

        $file = CsvImportFile::where('csv_import_run_id', $runId)->where('kind', 'customers')->firstOrFail();
        $bytes = $this->slice($customers)[0];

        /**
         * A genuinely separate connection, so the rival row is COMMITTED
         * outside the transaction the service is about to open — which is what
         * a second browser tab actually does. Writing it on the default
         * connection would only roll back alongside ours and prove nothing.
         */
        config(['database.connections.race' => config('database.connections.'.config('database.default'))]);
        DB::purge('race');

        $fired = false;

        CsvImportFileChunk::creating(function () use (&$fired, $file, $bytes, $runId): void {
            if ($fired) {
                return;
            }

            $fired = true;

            DB::connection('race')->table('csv_import_file_chunks')->insert([
                'csv_import_file_id' => $file->id,
                'chunk_index' => 0,
                'size_bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'path' => 'csv-imports/'.$runId.'/chunks/customers/000000.part',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // The loser of the race carries the same bytes, so it is a retry and is
        // answered as one rather than as an error.
        $this->sendChunk($runId, 'customers', 0, $bytes)
            ->assertOk()
            ->assertJsonPath('status', 'duplicate');

        $this->assertTrue($fired, 'The rival insert never ran; the race was not reproduced.');
        $this->assertSame(1, CsvImportFileChunk::count(), 'The unique index must leave exactly one row for an index.');
    }

    public function test_abandoned_chunks_can_be_discarded_without_leaking_pii_onto_the_volume(): void
    {
        $customers = $this->payload(self::CHUNK * 2, 'customers');
        $runId = $this->postJson('/api/imports', $this->openRunPayload($customers, 'x'))
            ->assertCreated()
            ->json('run.id');

        foreach ($this->slice($customers) as $index => $bytes) {
            $this->sendChunk($runId, 'customers', $index, $bytes)->assertCreated();
        }

        $this->assertNotEmpty(Storage::disk('private')->allFiles());

        // The handoff for whoever owns cancellation: assembly deletes its own
        // chunks, and an abandoned run would otherwise leave a coop's members
        // sitting on the volume in plain text forever.
        $removed = app(CsvImportUploadService::class)
            ->discardChunks(CsvImportRun::findOrFail($runId));

        $this->assertSame(2, $removed);
        $this->assertSame(0, CsvImportFileChunk::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_a_second_concurrent_assemble_is_refused_rather_than_racing_the_first(): void
    {
        $customers = $this->payload(self::CHUNK * 2, 'customers');
        $loans = $this->payload(100, 'loans');

        $runId = $this->uploadBothFiles($customers, $loans);

        /**
         * Holding the lock stands in for an assemble already in flight.
         *
         * Unserialised, the second call hashes the file the first is still
         * writing, sees a mismatch and deletes it — while the first commits
         * `assembled_path` and drops every chunk. The run then claims to be
         * assembled, has no file, and has nothing left to rebuild from.
         */
        $lock = Cache::lock("csv-import:assemble:{$runId}", 300);
        $this->assertTrue($lock->get());

        try {
            $this->postJson("/api/imports/{$runId}/assemble")->assertStatus(409);
        } finally {
            $lock->release();
        }

        $this->assertSame('uploading', CsvImportRun::findOrFail($runId)->phase);
        $this->assertSame(3, CsvImportFileChunk::count(), 'A refused assemble must not touch the chunks.');

        // And once the lock clears, the same call succeeds untouched.
        $this->postJson("/api/imports/{$runId}/assemble")->assertOk();
    }

    public function test_a_raw_body_larger_than_the_index_expects_is_refused_before_it_is_all_read(): void
    {
        $customers = $this->payload(self::CHUNK * 2, 'customers');
        $runId = $this->postJson('/api/imports', $this->openRunPayload($customers, 'x'))
            ->assertCreated()
            ->json('run.id');

        /**
         * The raw path has no PHP-level ceiling: `request_parse_body()` refuses
         * `application/octet-stream` without reading it, so `post_max_size`
         * never applies and only nginx's 25M is left. The copy is bounded here
         * instead, at one byte past what this index can legitimately hold.
         */
        $oversized = $this->payload(self::CHUNK * 2, 'oversized');

        $this->sendRawChunk($runId, 'customers', 0, $oversized)
            ->assertStatus(413)
            ->assertJsonPath('expected_size_bytes', self::CHUNK);

        $this->assertSame(0, CsvImportFileChunk::count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_an_unprivileged_caller_is_refused_before_the_validator_can_answer_anything(): void
    {
        $officer = User::factory()->create(['branch_id' => $this->branch->id]);
        $officer->assignRole(Role::where('name', 'loan_officer')->firstOrFail());

        /**
         * A deliberately invalid payload. With the gate only in the controller
         * this returned 422 first, which told a caller who may not import
         * anything which branch ids exist and what the size limit is. The
         * refusal has to come before the validator.
         */
        $this->actingAs($officer)
            ->postJson('/api/imports', ['branch_id' => 999999, 'files' => []])
            ->assertForbidden();
    }

    // ------------------------------------------------------- lifecycle / cleanup

    public function test_cancelling_mid_upload_frees_the_slot_and_leaves_nothing_on_disk(): void
    {
        $customers = $this->payload(self::CHUNK * 2, 'customers');
        $loans = $this->payload(100, 'loans');

        $runId = $this->postJson('/api/imports', $this->openRunPayload($customers, $loans))
            ->assertCreated()
            ->json('run.id');

        // Half an upload, exactly as a browser that is about to die would leave it.
        $this->sendChunk($runId, 'customers', 0, $this->slice($customers)[0])->assertCreated();
        $this->sendChunk($runId, 'loans', 0, $loans)->assertCreated();

        $this->assertNotEmpty(Storage::disk('private')->allFiles());

        // Without this endpoint the run below is refused forever: POST /imports
        // 409s while any run is open, and nothing in the UI could clear it.
        $this->postJson('/api/imports', $this->openRunPayload($customers, $loans))
            ->assertStatus(409)
            ->assertJsonPath('cancellable', true);

        $this->deleteJson("/api/imports/{$runId}")
            ->assertOk()
            ->assertJsonPath('cancelled', true)
            ->assertJsonPath('chunks_removed', 2)
            ->assertJsonPath('run.phase', 'cancelled');

        $this->assertSame([], Storage::disk('private')->allFiles(), 'Cancelling must leave no member data on the volume.');
        $this->assertSame(0, CsvImportFileChunk::count());

        // And the slot is genuinely free.
        $second = $this->postJson('/api/imports', $this->openRunPayload($customers, $loans))
            ->assertCreated()
            ->json('run.id');

        $this->assertNotSame($runId, $second);
        $this->sendChunk($second, 'customers', 0, $this->slice($customers)[0])->assertCreated();
    }

    public function test_cancelling_is_idempotent(): void
    {
        $runId = $this->postJson('/api/imports', $this->openRunPayload('a', 'b'))
            ->assertCreated()
            ->json('run.id');

        $this->deleteJson("/api/imports/{$runId}")->assertOk()->assertJsonPath('cancelled', true);

        // A client that retried a DELETE it never saw the response to must not
        // be punished for it.
        $this->deleteJson("/api/imports/{$runId}")->assertOk()->assertJsonPath('cancelled', false);
    }

    public function test_a_run_that_has_started_writing_members_cannot_be_cancelled(): void
    {
        $runId = $this->postJson('/api/imports', $this->openRunPayload('a', 'b'))
            ->assertCreated()
            ->json('run.id');

        // Past the point of no return: real borrowers and real released debt
        // exist by now, and unwinding those is a reversal, not a cancellation.
        CsvImportRun::whereKey($runId)->update(['phase' => 'importing_customers']);

        $this->deleteJson("/api/imports/{$runId}")
            ->assertStatus(409)
            ->assertJsonPath('phase', 'importing_customers')
            // The refusal has to say which phases it WOULD have accepted,
            // or the operator is left guessing at a bare 409.
            ->assertJsonPath('cancellable_phases', CsvImportUploadService::CANCELLABLE_PHASES);

        $this->assertSame('importing_customers', CsvImportRun::findOrFail($runId)->phase);
    }

    public function test_a_loan_officer_cannot_cancel_a_run(): void
    {
        $runId = $this->postJson('/api/imports', $this->openRunPayload('a', 'b'))
            ->assertCreated()
            ->json('run.id');

        $officer = User::factory()->create(['branch_id' => $this->branch->id]);
        $officer->assignRole(Role::where('name', 'loan_officer')->firstOrFail());

        $this->actingAs($officer)->deleteJson("/api/imports/{$runId}")->assertForbidden();

        $this->assertSame('uploading', CsvImportRun::findOrFail($runId)->phase);
    }

    public function test_an_upload_abandoned_past_the_ttl_stops_blocking_the_next_one(): void
    {
        $customers = $this->payload(self::CHUNK * 2, 'customers');

        $abandoned = $this->postJson('/api/imports', $this->openRunPayload($customers, 'x'))
            ->assertCreated()
            ->json('run.id');

        $this->sendChunk($abandoned, 'customers', 0, $this->slice($customers)[0])->assertCreated();

        // Still active, so the guard rightly holds.
        $this->postJson('/api/imports', $this->openRunPayload($customers, 'x'))->assertStatus(409);

        // Now age it past the window. Both the run and its chunk have to move:
        // staleness is measured from the newest chunk, precisely so that an
        // upload still making slow progress is never reclaimed.
        $stale = now()->subMinutes(config('imports.stale_upload_after_minutes') + 60);
        CsvImportRun::whereKey($abandoned)->update(['updated_at' => $stale]);
        CsvImportFileChunk::query()->update(['created_at' => $stale]);

        $response = $this->postJson('/api/imports', $this->openRunPayload($customers, 'x'))
            ->assertCreated()
            ->assertJsonPath('reclaimed_run_id', $abandoned);

        $reclaimed = CsvImportRun::findOrFail($abandoned);
        $this->assertSame('cancelled', $reclaimed->phase);
        $this->assertStringContainsString('Abandoned mid-upload', (string) $reclaimed->failure_reason);

        // The reclaimed run's data goes with it; only the new run's slot remains.
        $this->assertSame(0, CsvImportFileChunk::where('csv_import_file_id', '!=', 0)
            ->whereIn('csv_import_file_id', CsvImportFile::where('csv_import_run_id', $abandoned)->pluck('id'))
            ->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertNotSame($abandoned, $response->json('run.id'));
    }

    public function test_an_upload_still_making_progress_is_never_reclaimed(): void
    {
        $customers = $this->payload(self::CHUNK * 2, 'customers');

        $runId = $this->postJson('/api/imports', $this->openRunPayload($customers, 'x'))
            ->assertCreated()
            ->json('run.id');

        /**
         * Opened long ago, but a chunk landed a minute ago. A 100 MiB export
         * over a bad mobile link can legitimately take all day; reclaiming it
         * for being old rather than for being idle would destroy exactly the
         * uploads this feature exists to protect.
         */
        CsvImportRun::whereKey($runId)->update([
            'updated_at' => now()->subMinutes(config('imports.stale_upload_after_minutes') + 600),
        ]);
        $this->sendChunk($runId, 'customers', 0, $this->slice($customers)[0])->assertCreated();

        $this->postJson('/api/imports', $this->openRunPayload($customers, 'x'))
            ->assertStatus(409)
            ->assertJsonPath('run_id', $runId);

        $this->assertSame('uploading', CsvImportRun::findOrFail($runId)->phase);
    }

    public function test_a_completed_run_gives_up_its_assembled_member_data(): void
    {
        $customers = $this->payload(self::CHUNK + 12, 'customers');
        $loans = $this->payload(100, 'loans');

        $runId = $this->uploadBothFiles($customers, $loans);
        $this->postJson("/api/imports/{$runId}/assemble")->assertOk();

        $run = CsvImportRun::findOrFail($runId);
        $file = $run->files()->where('kind', 'customers')->firstOrFail();

        Storage::disk('private')->assertExists($file->assembled_path);
        $this->assertSame(hash('sha256', $customers), $file->assembled_sha256);

        /**
         * The importer finishing the run is what releases the files. An
         * assembled customers CSV is every member's name, birthdate, contact
         * number and income in plaintext; once the rows are staged it has done
         * its job and keeping it is a standing disclosure risk with no
         * remaining benefit.
         */
        $run->update(['phase' => 'completed']);

        $this->assertSame([], Storage::disk('private')->allFiles(), 'A completed run must not leave member data on the volume.');

        $file->refresh();
        $this->assertNull($file->assembled_path);

        // The digest survives: it is the evidence of what was imported, and
        // costs nothing to keep once the bytes are gone.
        $this->assertSame(hash('sha256', $customers), $file->assembled_sha256);
    }

    public function test_a_failed_run_gives_up_its_assembled_member_data_too(): void
    {
        $customers = $this->payload(self::CHUNK + 12, 'customers');
        $loans = $this->payload(100, 'loans');

        $runId = $this->uploadBothFiles($customers, $loans);
        $this->postJson("/api/imports/{$runId}/assemble")->assertOk();

        CsvImportRun::findOrFail($runId)->update(['phase' => 'failed']);

        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_a_run_failed_by_a_mass_update_still_gives_up_its_files(): void
    {
        $customers = $this->payload(self::CHUNK + 12, 'customers');
        $loans = $this->payload(100, 'loans');

        $runId = $this->uploadBothFiles($customers, $loans);
        $this->postJson("/api/imports/{$runId}/assemble")->assertOk();

        $this->assertCount(2, Storage::disk('private')->allFiles());

        /**
         * Exactly how the importer's abandoned-run cleanup finishes a run:
         * a query-builder update from a scheduled command. Eloquent fires no
         * model events for that, so the retention listener never sees it — and
         * that command must not unlink anything itself, because it runs as root
         * and deleting only the database rows would strand the assembled CSVs
         * on the volume with nothing left pointing at them.
         */
        CsvImportRun::whereKey($runId)->update(['phase' => 'failed']);

        $this->assertCount(2, Storage::disk('private')->allFiles(), 'Precondition: the listener did not fire, as expected.');

        // Opening the next import reconciles it, in a web request, where the
        // uid permits the unlink.
        $this->postJson('/api/imports', $this->openRunPayload('a', 'b'))->assertCreated();

        $this->assertSame([], Storage::disk('private')->allFiles(), 'A run failed by mass update must not keep member data forever.');
        $this->assertNull(
            CsvImportFile::where('csv_import_run_id', $runId)->where('kind', 'customers')->firstOrFail()->assembled_path,
        );
    }

    public function test_the_payload_publishes_is_closed_from_the_shared_phase_constant(): void
    {
        $runId = $this->postJson('/api/imports', $this->openRunPayload('a', 'b'))
            ->assertCreated()
            ->assertJsonPath('run.is_closed', false)
            ->json('run.id');

        $this->deleteJson("/api/imports/{$runId}")
            ->assertOk()
            ->assertJsonPath('run.phase', 'cancelled')
            // Derived, never hardcoded: a client that kept its own phase list
            // would have missed `cancelled` entirely when it was added.
            ->assertJsonPath('run.is_closed', true);

        $this->assertContains('cancelled', CsvImportUploadService::CLOSED_PHASES);
        $this->assertContains('completed', CsvImportUploadService::CLOSED_PHASES);
        $this->assertContains('failed', CsvImportUploadService::CLOSED_PHASES);
    }

    public function test_chunk_files_are_zero_padded_so_a_lexical_sort_is_the_concatenation_order(): void
    {
        $customers = $this->payload(self::CHUNK * 2, 'customers');
        $runId = $this->postJson('/api/imports', $this->openRunPayload($customers, 'x'))
            ->assertCreated()
            ->json('run.id');

        foreach ($this->slice($customers) as $index => $bytes) {
            $this->sendChunk($runId, 'customers', $index, $bytes)->assertCreated();
        }

        $paths = CsvImportFileChunk::orderBy('chunk_index')->pluck('path')->all();
        $sorted = $paths;
        sort($sorted);

        $this->assertSame($paths, $sorted);
        $this->assertStringEndsWith('/000000.part', $paths[0]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Services\Diagnostics\ErrorDigest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The bulk borrower endpoints return a per-id failure list. What goes in it.
 *
 * `bulkDeactivate()` and `bulkDestroy()` both built that list from
 * `$e->getMessage()` and returned it in the HTTP RESPONSE BODY, which made them
 * worse than the log leaks fixed alongside them: a log file needs shell access
 * to read, a response body is handed to whoever called the endpoint.
 *
 * A Laravel QueryException's message is the failing SQL WITH THE BINDINGS
 * SUBSTITUTED IN. The statement that fails is rarely the one the controller
 * wrote: `App\Traits\Auditable` hangs `updated` and `deleted` hooks on Borrower
 * that copy the model's FULL attribute set into `audit_logs.old_values`, so an
 * `update(['status' => 'inactive'])` whose own SQL names one harmless column
 * fires a second INSERT carrying the member's name, birthdate, address, contact
 * number, employer and income. That insert's failure is the disclosure.
 *
 * ## Why each test proves the message was dangerous BEFORE proving it is gone
 *
 * An absence assertion is worthless on its own. `assertStringNotContainsString('Juanita', $body)`
 * passes just as happily when the fixture never had a Juanita in it, when the
 * request 404'd, when the borrower was never created, or when the failure being
 * exercised was not the failure that carries the record. So every test here
 * first switches the restricted diagnostics channel on, points it at a file of
 * its own, and asserts the member's data IS in the message the controller
 * caught. Only then does it assert that none of it reached the response.
 *
 * That ordering also pins the second half of the fix — that the full text still
 * exists somewhere an engineer can get at it, rather than having been thrown
 * away.
 *
 * ## Real exceptions, not mocks
 *
 * A mocked QueryException proves the formatting of a string this codebase wrote
 * itself. Every failure below is induced in the database: a column too small for
 * the JSON the audit trail puts in it (1406), and a genuine restricted-delete
 * foreign key (1451), which is not exotic — `loans.borrower_id` is
 * `restrictOnDelete`, so every member who has ever borrowed raises it.
 */
class BorrowerBulkErrorDisclosureTest extends TestCase
{
    use SetupLendyPH;

    /**
     * The member's record, as distinctive strings. Every one of these must be
     * findable in the raw driver message and findable nowhere else.
     *
     * Chosen to survive JSON encoding intact: no slashes, no unicode escapes.
     * `monthly_income` casts to decimal:2, so it reaches the binding as the
     * string asserted here rather than as a float.
     *
     * The birthdate is NOT in this list, because it is the one field the two
     * hooks spell differently. See self::BIRTHDATE_SPELLINGS.
     */
    private const PII = [
        'Juanita',
        'Marquez-Delacruz',
        '09171234567',
        'juanita.marquez.delacruz@example.test',
        'Sampaguita Street, Barangay Malinis, Iloilo City',
        'Panay Mill Workers Cooperative',
        '42350.75',
    ];

    private const HUMAN_BIRTHDATE = '1974-03-09';

    /**
     * Every spelling of this member's birthdate that an audit row can carry.
     *
     * The two Auditable hooks disagree, and the disagreement is real rather
     * than an artefact of this test:
     *
     *  - `updated` logs `$model->getOriginal()`, which APPLIES CASTS, so
     *    `birthdate` arrives as a Carbon and JSON-encodes in UTC. This app runs
     *    at UTC+8, where midnight on the 9th is 16:00 on the 8th — so the
     *    leaked string reads `1974-03-08T16:00:00.000000Z`, a date that is not
     *    the one anybody typed.
     *  - `deleted` logs `$model->getAttributes()`, which does NOT, so the same
     *    field arrives as the raw column value `1974-03-09`.
     *
     * Both disclose the birthdate. Pinning either one alone would have made the
     * presence half of one of these tests silently unprovable — and an absence
     * assertion whose presence half never fired proves nothing at all, which is
     * the failure this whole test class is shaped to avoid. So presence is
     * satisfied by ANY spelling and absence is required of EVERY spelling.
     */
    private const BIRTHDATE_SPELLINGS = [
        '1974-03-09',
        '1974-03-08T16:00:00',
    ];

    /**
     * The birthdate leaked, however it was spelled.
     */
    private function assertBirthdateIsInTheRawMessage(string $diagnostics): void
    {
        $found = array_filter(
            self::BIRTHDATE_SPELLINGS,
            static fn (string $spelling): bool => str_contains($diagnostics, $spelling),
        );

        $this->assertNotEmpty(
            $found,
            'The raw driver message contained no spelling of the birthdate, so this test is not exercising the '
            .'leak it claims to. Check that the audit hook still copies the full attribute set.'
        );
    }

    /**
     * ...and none of its spellings came out here.
     */
    private function assertNoBirthdateIn(string $haystack, string $where): void
    {
        foreach (self::BIRTHDATE_SPELLINGS as $spelling) {
            $this->assertStringNotContainsString($spelling, $haystack, "The birthdate reached {$where}.");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAndLogin();
    }

    /**
     * A member whose every sensitive field is a string we can search for.
     */
    private function member(): Borrower
    {
        return Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'first_name' => 'Juanita',
            'last_name' => 'Marquez-Delacruz',
            'middle_name' => 'Bautista',
            'birthdate' => self::HUMAN_BIRTHDATE,
            'address' => '14 Sampaguita Street, Barangay Malinis, Iloilo City',
            'contact_number' => '09171234567',
            'email' => 'juanita.marquez.delacruz@example.test',
            'employer_or_business' => 'Panay Mill Workers Cooperative',
            'monthly_income' => 42350.75,
        ]);
    }

    /**
     * Make the audit trail's INSERT fail, the way it would in production.
     *
     * `audit_logs.old_values` is a JSON column holding a whole model. Shrinking
     * it to something a real record cannot fit in reproduces the ordinary
     * version of this incident — a blob outgrowing its column — and MySQL raises
     * 1406 for it under the `strict` connection this app configures.
     *
     * Called AFTER the fixture exists, because creating a borrower writes an
     * audit row of its own and would otherwise fail first.
     */
    private function breakTheAuditTrail(): void
    {
        DB::statement('DELETE FROM audit_logs');
        DB::statement('ALTER TABLE audit_logs MODIFY old_values VARCHAR(24) NULL');
    }

    /**
     * Point the restricted channel at a file of this test's own and switch the
     * diagnostics flag on.
     *
     * A real file, because "which destination did the message reach" is the
     * whole question and every channel raises the same MessageLogged event.
     *
     * @return string the path to read back
     */
    private function captureRestrictedChannel(): string
    {
        /*
         * The REAL channel's mode, asserted before this method replaces it.
         *
         * Everything these tests claim rests on "the full message went
         * somewhere restricted" — and the override below is a plain `single`
         * file with no `permission` key, because these assertions are about
         * WHICH destination took the line, and a 0600 file the test runner
         * must read back is an obstacle to that. So the override would quietly
         * make the mode untestable. This is the one assertion that keeps it
         * tested: `single` is 644 and world-readable, which is tolerable for
         * ordinary logging and is not tolerable for a file that may hold a
         * membership register.
         */
        $this->assertSame(
            0600,
            config('logging.channels.'.ErrorDigest::RESTRICTED_CHANNEL.'.permission'),
            'The restricted channel is no longer mode 0600. The whole argument for sending the unredacted '
            .'message there rather than to `single` is that its file is not world-readable — see '
            .'config/logging.php.'
        );

        /*
         * The driver, pinned in the same breath, because `permission` alone
         * does not carry the argument.
         *
         * 0600 applies to files the channel CREATES. Under `daily` that is
         * benign: the real file is `csv-import-<today>.log`, a name that did
         * not exist before today, so StreamHandler chmods it on open and any
         * stale 644 file from an earlier incident carries an older date-stamped
         * name that is never reopened. Flip this to `single` and the path
         * collapses to a literal `csv-import.log` — one file, reopened
         * forever, which a stale 644 mode would survive and which would never
         * rotate. The mode assertion above would still pass while the property
         * it is standing in for had gone.
         */
        $this->assertSame(
            'daily',
            config('logging.channels.'.ErrorDigest::RESTRICTED_CHANNEL.'.driver'),
            'The restricted channel is no longer a `daily` channel. 0600 only protects a file at CREATION, so '
            .'this sink depends on getting a new filename each day; a single reopened file would keep whatever '
            .'mode it was first given and never expire.'
        );

        $path = storage_path('logs/testing-'.Str::random(12).'.log');

        config()->set('logging.channels.'.ErrorDigest::RESTRICTED_CHANNEL, [
            'driver' => 'single',
            'path' => $path,
            'level' => 'debug',
        ]);
        config()->set(ErrorDigest::BORROWER_DIAGNOSTICS_FLAG, true);

        Log::forgetChannel(ErrorDigest::RESTRICTED_CHANNEL);

        return $path;
    }

    /**
     * Every line logged during the request, whichever channel took it.
     *
     * @var list<string>
     */
    private array $logEntries = [];

    private function listenToLogs(): void
    {
        $this->logEntries = [];

        Event::listen(function (MessageLogged $message): void {
            $this->logEntries[] = $message->message.' '.json_encode($message->context);
        });
    }

    /**
     * The same lines MINUS the restricted channel's.
     *
     * Every channel raises the same MessageLogged event, so the restricted
     * line — which is SUPPOSED to hold the record — would otherwise satisfy an
     * assertion meant for the shared log and hide a real leak. Filtered on the
     * one message ErrorDigest::recordDiagnostics() writes, and the tests here
     * assert on the restricted FILE for anything that must be present in it, so
     * this filter can only ever make an absence assertion stricter.
     */
    private function sharedLog(): string
    {
        return implode("\n", array_filter(
            $this->logEntries,
            static fn (string $entry): bool => ! str_contains($entry, 'full exception detail'),
        ));
    }

    /**
     * bulkDeactivate: the `updated` hook's insert fails, and the member's whole
     * record is in the exception the controller catches.
     */
    public function test_bulk_deactivate_keeps_the_members_record_out_of_the_response_and_the_shared_log(): void
    {
        $borrower = $this->member();
        $this->breakTheAuditTrail();

        $path = $this->captureRestrictedChannel();
        $this->listenToLogs();

        try {
            $response = $this->patchJson('/api/borrowers/bulk-deactivate', ['ids' => [$borrower->id]])
                ->assertSuccessful();

            $body = $response->getContent();

            /*
             * FIRST: prove the message was dangerous.
             *
             * Without this half, everything below passes on an empty fixture, a
             * request that never reached the controller, or a failure that never
             * carried a record. The file read here is the message the controller
             * actually caught on this request.
             */
            $this->assertFileExists($path, 'Nothing was caught, so the rest of this test proves nothing.');
            $diagnostics = (string) file_get_contents($path);

            foreach (self::PII as $value) {
                $this->assertStringContainsString(
                    $value,
                    $diagnostics,
                    "The raw driver message did not contain [{$value}], so this test is not exercising the leak "
                    .'it claims to. Check that the audit hook still copies the full attribute set.'
                );
            }

            $this->assertBirthdateIsInTheRawMessage($diagnostics);

            // SECOND, and only now meaningfully: none of it is in the response.
            foreach (self::PII as $value) {
                $this->assertStringNotContainsString(
                    $value,
                    $body,
                    "[{$value}] reached the HTTP response body."
                );
                $this->assertStringNotContainsString(
                    $value,
                    $this->sharedLog(),
                    "[{$value}] reached the shared log channel."
                );
            }

            // Nor is any other part of the exception's own text — the SQL, the
            // driver's prose, the class.
            $this->assertNoBirthdateIn($body, 'the HTTP response body');
            $this->assertNoBirthdateIn($this->sharedLog(), 'the shared log channel');

            $this->assertStringNotContainsString('SQLSTATE', $body);
            $this->assertStringNotContainsString('insert into', $body);
            $this->assertStringNotContainsString('audit_logs', $body);
            $this->assertStringNotContainsString('QueryException', $body);

            // What the operator DOES get: the id they sent and a code they can
            // act on, in prose written here rather than by a driver.
            $this->assertSame([$borrower->id], $response->json('failed.*.id'));
            $this->assertSame(
                "Borrower {$borrower->id} could not be deactivated (database error 1406). See the application log.",
                $response->json('failed.0.reason'),
            );
            $this->assertSame([], $response->json('deactivated'));

            // And the engineer gets enough in the shared log to triage without
            // it: which borrower, which SQLSTATE, which driver code.
            $shared = $this->sharedLog();
            $this->assertStringContainsString('borrowers: bulk deactivate failed for one borrower', $shared);
            $this->assertStringContainsString('"borrower_id":'.$borrower->id, $shared);
            $this->assertStringContainsString('"driver_code":"1406"', $shared);
            $this->assertStringContainsString('"sql_state":"22001"', $shared);
        } finally {
            Log::forgetChannel(ErrorDigest::RESTRICTED_CHANNEL);
            @unlink($path);
        }
    }

    /**
     * bulkDestroy, same vector through the `deleted` hook.
     *
     * Worth its own test rather than a data provider: the two endpoints catch in
     * separate loops, and the one that was fixed is never the one that was not.
     */
    public function test_bulk_destroy_keeps_the_members_record_out_of_the_response_and_the_shared_log(): void
    {
        $borrower = $this->member();
        $this->breakTheAuditTrail();

        $path = $this->captureRestrictedChannel();
        $this->listenToLogs();

        try {
            $response = $this->deleteJson('/api/borrowers/bulk', ['ids' => [$borrower->id]])
                ->assertSuccessful();

            $body = $response->getContent();

            $this->assertFileExists($path, 'Nothing was caught, so the rest of this test proves nothing.');
            $diagnostics = (string) file_get_contents($path);

            foreach (self::PII as $value) {
                $this->assertStringContainsString(
                    $value,
                    $diagnostics,
                    "The raw driver message did not contain [{$value}], so this test is not exercising the leak "
                    .'it claims to.'
                );
            }

            $this->assertBirthdateIsInTheRawMessage($diagnostics);

            foreach (self::PII as $value) {
                $this->assertStringNotContainsString($value, $body, "[{$value}] reached the HTTP response body.");
                $this->assertStringNotContainsString(
                    $value,
                    $this->sharedLog(),
                    "[{$value}] reached the shared log channel."
                );
            }

            $this->assertNoBirthdateIn($body, 'the HTTP response body');
            $this->assertNoBirthdateIn($this->sharedLog(), 'the shared log channel');

            $this->assertStringNotContainsString('SQLSTATE', $body);
            $this->assertStringNotContainsString('insert into', $body);

            $this->assertSame(
                "Borrower {$borrower->id} could not be deleted (database error 1406). See the application log.",
                $response->json('failed.0.reason'),
            );
            $this->assertSame([], $response->json('deleted'));

            // The purge is transactional, so a failed audit row leaves the
            // member intact rather than half-deleted.
            $this->assertDatabaseHas('borrowers', ['id' => $borrower->id]);
        } finally {
            Log::forgetChannel(ErrorDigest::RESTRICTED_CHANNEL);
            @unlink($path);
        }
    }

    /**
     * The ordinary failure, with nothing broken on purpose.
     *
     * `loans.borrower_id` is `restrictOnDelete`, so deleting a member who has
     * ever borrowed raises 1451 — this is the case operators hit, not an
     * induced one. It is here to pin the OTHER half of the contract: the
     * response must stay actionable. An admin who bulk-deletes forty members
     * and gets back forty identical "could not be deleted" lines learns
     * nothing; the code is what tells them this is their problem (the member
     * still has a loan) rather than something to retry.
     */
    public function test_a_restricted_delete_returns_an_actionable_code_and_no_sql(): void
    {
        $loan = $this->createReleasedLoan();
        $borrowerId = $loan->borrower_id;

        $path = $this->captureRestrictedChannel();
        $this->listenToLogs();

        try {
            $response = $this->deleteJson('/api/borrowers/bulk', ['ids' => [$borrowerId]])
                ->assertSuccessful();

            $body = $response->getContent();

            // The exception really did carry the failing statement...
            $this->assertFileExists($path);
            $diagnostics = (string) file_get_contents($path);
            $this->assertStringContainsString('foreign key constraint fails', $diagnostics);
            $this->assertStringContainsString('delete from', $diagnostics);

            // ...and none of it is in the response.
            $this->assertStringNotContainsString('foreign key', $body);
            $this->assertStringNotContainsString('delete from', $body);
            $this->assertStringNotContainsString('SQLSTATE', $body);
            $this->assertStringNotContainsString('loans', $body);

            $this->assertSame(
                "Borrower {$borrowerId} could not be deleted (database error 1451). See the application log.",
                $response->json('failed.0.reason'),
            );
            $this->assertSame([], $response->json('deleted'));
            $this->assertDatabaseHas('borrowers', ['id' => $borrowerId]);

            $this->assertStringContainsString('"driver_code":"1451"', $this->sharedLog());
        } finally {
            Log::forgetChannel(ErrorDigest::RESTRICTED_CHANNEL);
            @unlink($path);
        }
    }

    /**
     * The switch is off by default, and off means nothing is written at all.
     *
     * The tests above turn it on to read the message back, which would be a
     * poor guarantee if that were also its resting state. This is the resting
     * state: the response is still safe, the shared log is still useful, and
     * the file with the record in it does not exist.
     */
    public function test_with_diagnostics_off_the_full_message_is_written_nowhere(): void
    {
        $borrower = $this->member();
        $this->breakTheAuditTrail();

        $path = storage_path('logs/testing-'.Str::random(12).'.log');
        config()->set('logging.channels.'.ErrorDigest::RESTRICTED_CHANNEL, [
            'driver' => 'single',
            'path' => $path,
            'level' => 'debug',
        ]);
        config()->set(ErrorDigest::BORROWER_DIAGNOSTICS_FLAG, false);
        Log::forgetChannel(ErrorDigest::RESTRICTED_CHANNEL);

        $this->listenToLogs();

        try {
            $response = $this->patchJson('/api/borrowers/bulk-deactivate', ['ids' => [$borrower->id]])
                ->assertSuccessful();

            $this->assertFileDoesNotExist($path, 'Diagnostics are off, so nothing may have been written at all.');

            $everything = $response->getContent()."\n".implode("\n", $this->logEntries);

            foreach (self::PII as $value) {
                $this->assertStringNotContainsString($value, $everything, "[{$value}] escaped.");
            }

            $this->assertNoBirthdateIn($everything, 'the response or the shared log');

            $this->assertSame(
                "Borrower {$borrower->id} could not be deactivated (database error 1406). See the application log.",
                $response->json('failed.0.reason'),
            );
            $this->assertStringContainsString('"driver_code":"1406"', implode("\n", $this->logEntries));
        } finally {
            Log::forgetChannel(ErrorDigest::RESTRICTED_CHANNEL);
            @unlink($path);
        }
    }

    /**
     * The IMPORTER's switch does not arm these endpoints.
     *
     * The channel is shared with the CSV importer deliberately — it is the
     * application's one restricted sink and renaming it would silently
     * disarm boxes that had turned it on. The FLAG is not shared, and this is
     * why. `LOG_CSV_IMPORT_DIAGNOSTICS` ships in .env.example, so it is a real
     * knob on ten deployments; if an operator sets it to chase an import
     * incident, that must not also start capturing whole member records from
     * an admin-triggered borrower endpoint they were not asking about.
     *
     * config/logging.php says to enable these "only to diagnose a specific
     * incident". This test is what makes the word "specific" true.
     */
    public function test_the_importers_diagnostics_switch_does_not_arm_the_borrower_endpoints(): void
    {
        $borrower = $this->member();
        $this->breakTheAuditTrail();

        $path = storage_path('logs/testing-'.Str::random(12).'.log');
        config()->set('logging.channels.'.ErrorDigest::RESTRICTED_CHANNEL, [
            'driver' => 'single',
            'path' => $path,
            'level' => 'debug',
        ]);

        // The importer's switch ON, as it would be mid-incident...
        config()->set(ErrorDigest::DIAGNOSTICS_FLAG, true);
        // ...and this controller's own left at its default.
        config()->set(ErrorDigest::BORROWER_DIAGNOSTICS_FLAG, false);

        Log::forgetChannel(ErrorDigest::RESTRICTED_CHANNEL);
        $this->listenToLogs();

        try {
            $response = $this->patchJson('/api/borrowers/bulk-deactivate', ['ids' => [$borrower->id]])
                ->assertSuccessful();

            $this->assertFileDoesNotExist(
                $path,
                'The import diagnostics flag armed a borrower endpoint. These share a channel on purpose and '
                .'must not share a switch: see ErrorDigest::BORROWER_DIAGNOSTICS_FLAG.'
            );

            $everything = $response->getContent()."\n".implode("\n", $this->logEntries);

            foreach (self::PII as $value) {
                $this->assertStringNotContainsString($value, $everything, "[{$value}] escaped.");
            }

            $this->assertNoBirthdateIn($everything, 'the response or the shared log');

            // The endpoint still behaves: the failure is reported, safely.
            $this->assertSame(
                "Borrower {$borrower->id} could not be deactivated (database error 1406). See the application log.",
                $response->json('failed.0.reason'),
            );
        } finally {
            Log::forgetChannel(ErrorDigest::RESTRICTED_CHANNEL);
            @unlink($path);
        }
    }

    /**
     * A partial failure is still partial.
     *
     * The fix rewrites the contents of the `failed` array, and the frontend
     * reports partial results off the two arrays beside it. Nothing about that
     * changed, and this says so.
     */
    public function test_the_successful_ids_are_unaffected_by_a_neighbours_failure(): void
    {
        $healthy = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $loan = $this->createReleasedLoan();

        $response = $this->deleteJson('/api/borrowers/bulk', [
            'ids' => [$healthy->id, $loan->borrower_id],
        ])->assertSuccessful();

        $this->assertSame([$healthy->id], $response->json('deleted'));
        $this->assertSame([$loan->borrower_id], $response->json('failed.*.id'));
        $this->assertDatabaseMissing('borrowers', ['id' => $healthy->id]);
        $this->assertDatabaseHas('borrowers', ['id' => $loan->borrower_id]);
    }
}

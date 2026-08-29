<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One attempt at migrating a coop's legacy records in from CSV.
     *
     * A run is the unit of resume and the unit of audit. It owns the two
     * uploaded files, the mapping the admin confirmed, and — via `phase` and
     * `cursor_row_id` — enough state to pick a half-finished import back up
     * where it stopped rather than starting over and writing everything twice.
     */
    public function up(): void
    {
        Schema::create('csv_import_runs', function (Blueprint $table) {
            $table->id();

            /**
             * NOT NULL, unlike most branch references in this schema.
             *
             * Every deployment is single-tenant, but it is not single-branch:
             * imported members and their loans have to land somewhere, and
             * "somewhere" cannot be decided per row from a legacy file that has
             * no concept of this system's branches. The admin picks it once, up
             * front, for the whole run. Leaving it nullable would let a run
             * begin with the question unanswered and only discover it thousands
             * of rows in.
             *
             * restrictOnDelete because a run is a permanent record of what was
             * written; deleting a branch must not silently erase the evidence
             * of how its members got here.
             */
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            /**
             * The date the legacy extract represents, NOT the date it was
             * uploaded. Balances, accrued interest and past-due status in the
             * file are all true as of this date, so it is what the importer
             * reckons from. An extract pulled on the 1st and uploaded on the
             * 9th must not be interpreted as if it were current.
             */
            $table->date('as_of_date');

            /**
             * Where the run is, as a single authoritative value.
             *
             * The customers file must be fully imported before the loans file
             * starts, because loan rows join to borrowers by
             * `external_account_no` — hence two distinct importing phases
             * rather than one. `awaiting_mapping` is a real stop: the run
             * blocks on a human confirming which legacy product maps to which
             * LoanProduct, and no rows are written until they do.
             */
            $table->enum('phase', [
                'uploading',
                'assembled',
                'staging',
                'awaiting_mapping',
                'importing_customers',
                'importing_loans',
                'completed',
                'failed',
                'cancelled',
            ])->default('uploading');

            /**
             * The confirmed legacy-product-code to LoanProduct id map, as the
             * admin approved it. Stored on the run rather than resolved live so
             * a resumed import keeps applying the same mapping even if a
             * product is renamed or deactivated mid-run.
             */
            $table->json('product_mapping')->nullable();

            /**
             * The last `csv_import_rows.id` this run finished processing.
             *
             * The resume marker is really the NULL `result` on a row; this is
             * the fast path that keeps a resume from rescanning millions of
             * already-decided rows to find where it left off.
             */
            $table->unsignedBigInteger('cursor_row_id')->nullable();

            /**
             * Who started it.
             *
             * nullOnDelete: staff turnover must not be able to delete the
             * record of an import. The row survives with a null actor, which is
             * still more truthful than cascading it away.
             */
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();

            /**
             * `$request->ip()` at the moment the run was opened — which on this
             * deployment is NOT "from where".
             *
             * Every browser call reaches this API through the Next.js server-side
             * rewrite, so REMOTE_ADDR is the frontend host. TrustProxies is wired
             * in bootstrap/app.php but `TRUSTED_PROXIES` ships EMPTY (see
             * config/trustedproxy.php for why turning it on needs the frontend to
             * authenticate itself as a proxy first), so X-Forwarded-For is not
             * honoured and this column holds the same single address for every
             * admin on the box. It is not spoofable — it is uninformative, which
             * is the harder failure to notice: an incident review reads a value
             * that looks like provenance and is not.
             *
             * Kept because it costs nothing and becomes truthful the moment the
             * proxy list is populated. If "from where" ever has to mean
             * something before then, the answer is the authenticated Sanctum
             * token id — `$request->user()?->currentAccessToken()?->id` — which
             * identifies the session that asked and survives the proxy hop. That
             * is a column and an upload-service write, not a comment.
             */
            $table->string('initiated_ip', 45)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('failure_reason')->nullable();

            /**
             * Free-form operational breadcrumbs — counts, warnings, the
             * detected delimiter, anything an operator needs when a run is
             * questioned months later. Deliberately unstructured: this is a
             * migration tool, and pinning a schema on its diagnostics would
             * mean a migration every time one is added.
             */
            $table->json('notes')->nullable();

            /**
             * No secondary indexes here on purpose. A deployment accumulates a
             * handful of import runs over its lifetime, not millions, so every
             * query against this table is a full scan of a few dozen rows —
             * cheaper than any index would be. `branch_id` already carries the
             * index MySQL creates to enforce its foreign key.
             */
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_import_runs');
    }
};

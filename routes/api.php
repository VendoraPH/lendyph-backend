<?php

use App\Http\Controllers\Api\AccountingAccountController;
use App\Http\Controllers\Api\AccountingBookController;
use App\Http\Controllers\Api\AccountingCashAccountController;
use App\Http\Controllers\Api\AccountingExpenseController;
use App\Http\Controllers\Api\AccountingJournalController;
use App\Http\Controllers\Api\AccountingPeriodController;
use App\Http\Controllers\Api\AccountingReconciliationController;
use App\Http\Controllers\Api\AccountingReportController;
use App\Http\Controllers\Api\AccountingSettingsController;
use App\Http\Controllers\Api\AccountingStatementController;
use App\Http\Controllers\Api\ApprovalWorkflowController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutoCreditController;
use App\Http\Controllers\Api\AutoPayController;
use App\Http\Controllers\Api\BorrowerController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\CollateralController;
use App\Http\Controllers\Api\CollateralTypeController;
use App\Http\Controllers\Api\CoMakerController;
use App\Http\Controllers\Api\CsvImportController;
use App\Http\Controllers\Api\CsvImportErrorReportController;
use App\Http\Controllers\Api\CsvImportMappingController;
use App\Http\Controllers\Api\CsvImportStatusController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DisclosureController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FeeController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\GCashNonMemberController;
use App\Http\Controllers\Api\GCashReportController;
use App\Http\Controllers\Api\GCashTierController;
use App\Http\Controllers\Api\GCashTransactionController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\LoanAdjustmentController;
use App\Http\Controllers\Api\LoanApprovalStepController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\LoanProductController;
use App\Http\Controllers\Api\PromissoryNoteController;
use App\Http\Controllers\Api\RepaymentController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ShareCapitalLedgerController;
use App\Http\Controllers\Api\ShareCapitalPledgeController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\AllowAuthOrSubmissionToken;
use App\Http\Middleware\CheckTokenExpiry;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\OptionalSanctumAuth;
use App\Http\Middleware\RequirePasswordChange;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

// Auth (stricter rate limit — 10/min per IP)
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');

// Public branch picker for the registration page — slim, no auth.
Route::get('/branches/public', [BranchController::class, 'publicIndex']);

// Public organization branding (logo) for the login/registration pages — no auth.
Route::get('/branding/public', [BrandingController::class, 'publicShow']);

// The logo bytes, served through PHP so they carry CORS headers. /storage/** is
// handled by nginx and never reaches Laravel, so a cross-origin read of the
// logo there always fails — which is why report exports lost their logo.
Route::get('/branding/logo', [BrandingController::class, 'publicLogo']);

// Borrower KYC documents and photos, off the private disk. Authenticated by a
// temporary signature rather than a bearer token: these URLs are consumed by
// <img src>, which cannot send an Authorization header. Links are only minted
// while serialising a response for an already-authorised caller, and expire
// after FileController::LINK_TTL_MINUTES.
Route::middleware('signed')->group(function () {
    Route::get('/files/documents/{document}', [FileController::class, 'document'])
        ->name('files.document');
    Route::get('/files/borrowers/{borrower}/photo', [FileController::class, 'borrowerPhoto'])
        ->name('files.borrower-photo');
});

// Public registration: anonymous borrower create (status=pending only) + the
// two upload endpoints. Anonymous calls go through a 15-min submission token;
// authenticated calls keep today's operator behavior. The auth middleware
// runs FIRST so the throttle limiter can inspect $request->user() and skip
// the per-IP cap for operator traffic — and, on the upload routes, so it can
// read the X-Submission-Token the limiter keys on. Do not move the throttle
// ahead of it. CheckTokenExpiry + EnsureUserIsActive are no-ops when no user
// is attached, so they're safe to apply across both paths.
//
// The create and the uploads are metered by SEPARATE limiters on purpose.
// They shared `public-registration` until one applicant's create + photo +
// valid IDs spent most of a 5-per-10-minute per-IP budget, and the next
// person to open the form was refused. See App\Providers\AppServiceProvider.
//
// RequirePasswordChange rides along for the same reason EnsureUserIsActive
// does. These three sit OUTSIDE the auth group but still serve authenticated
// operators on their normal paths, so leaving it off would mean an operator
// whose password was just reset is refused everywhere in the product except
// creating borrowers and uploading their documents — the lock would have a
// hole in it exactly where borrower PII is written. It is a no-op without a
// user attached, so the anonymous registration flow is untouched.
Route::post('/borrowers', [BorrowerController::class, 'store'])
    ->middleware([OptionalSanctumAuth::class, 'throttle:public-registration', CheckTokenExpiry::class, EnsureUserIsActive::class, RequirePasswordChange::class]);

Route::post('/borrowers/{borrower}/photo', [BorrowerController::class, 'uploadPhoto'])
    ->middleware([AllowAuthOrSubmissionToken::class, 'throttle:registration-uploads', CheckTokenExpiry::class, EnsureUserIsActive::class, RequirePasswordChange::class]);

Route::post('/borrowers/{borrower}/valid-ids', [BorrowerController::class, 'uploadValidId'])
    ->middleware([AllowAuthOrSubmissionToken::class, 'throttle:registration-uploads', CheckTokenExpiry::class, EnsureUserIsActive::class, RequirePasswordChange::class]);

// Protected routes
//
// RequirePasswordChange runs LAST of the three, and the order is the point.
// "Your session expired" (401) and "your account was deactivated" (403) are
// both truths about the token or the account that outrank "you owe us a new
// password" — a deactivated user must be turned away, not sent to a
// change-password screen that would let them back in. It also means the
// middleware only ever sees a live token on a live account, so a 423 is always
// actionable by the person who received it.
//
// It allowlists GET /auth/me, POST /auth/change-password and POST /auth/logout
// by controller action; everything else in this group, including PATCH
// /auth/me and POST /auth/refresh, is refused while the flag is set.
Route::middleware(['auth:sanctum', CheckTokenExpiry::class, EnsureUserIsActive::class, RequirePasswordChange::class])->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/auth/me', [AuthController::class, 'updateMe']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    // Users
    //
    // The three non-resource routes carry explicit names so that ALL of user
    // management answers to one `users.*` predicate. `apiResource` names its
    // four for free (users.index/store/show/update) and these three were left
    // anonymous, so `routeIs('users.*')` silently covered four of the seven —
    // including neither deactivate nor reactivate nor reset-password, the three
    // that actually change an account. The `api` rate limiter in
    // AppServiceProvider is the caller that needs the predicate to be whole.
    Route::apiResource('users', UserController::class)->except(['destroy']);
    Route::patch('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::patch('/users/{user}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');

    // Branches
    Route::apiResource('branches', BranchController::class)->except(['destroy']);

    // Borrowers — bulk routes FIRST so `bulk` is not matched as a {borrower} parameter.
    // `store`, photo upload, and valid-id upload are defined OUTSIDE the auth group
    // so the public-registration flow can post anonymously with a submission token;
    // their authenticated paths still work via the same routes.
    Route::patch('/borrowers/bulk-deactivate', [BorrowerController::class, 'bulkDeactivate']);
    Route::delete('/borrowers/bulk', [BorrowerController::class, 'bulkDestroy']);
    Route::apiResource('borrowers', BorrowerController::class)->except(['store']);
    Route::patch('/borrowers/{borrower}/deactivate', [BorrowerController::class, 'deactivate']);
    Route::patch('/borrowers/{borrower}/reactivate', [BorrowerController::class, 'reactivate']);
    Route::patch('/borrowers/{borrower}/approve-registration', [BorrowerController::class, 'approveRegistration']);
    Route::patch('/borrowers/{borrower}/reject', [BorrowerController::class, 'reject']);
    Route::delete('/borrowers/{borrower}/photo', [BorrowerController::class, 'deletePhoto']);
    Route::get('/borrowers/{borrower}/valid-ids', [BorrowerController::class, 'listValidIds']);
    Route::delete('/borrowers/{borrower}/valid-ids/{validIdId}', [BorrowerController::class, 'deleteValidId']);
    Route::get('/borrowers/{borrower}/ledger', [BorrowerController::class, 'ledger']);

    // Co-makers
    Route::get('/borrowers/{borrower}/co-makers', [CoMakerController::class, 'index']);
    Route::post('/borrowers/{borrower}/co-makers', [CoMakerController::class, 'store']);
    Route::get('/co-makers/{coMaker}', [CoMakerController::class, 'show']);
    Route::put('/co-makers/{coMaker}', [CoMakerController::class, 'update']);
    Route::delete('/co-makers/{coMaker}', [CoMakerController::class, 'destroy']);

    // Documents
    Route::get('/borrowers/{borrower}/documents', [DocumentController::class, 'index']);
    Route::post('/borrowers/{borrower}/documents', [DocumentController::class, 'store']);
    Route::get('/co-makers/{coMaker}/documents', [DocumentController::class, 'index']);
    Route::post('/co-makers/{coMaker}/documents', [DocumentController::class, 'store']);
    Route::get('/loans/{loan}/documents', [DocumentController::class, 'index']);
    Route::post('/loans/{loan}/documents', [DocumentController::class, 'store']);
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);

    // Dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('/stats', [DashboardController::class, 'stats']);
        Route::get('/collections-trend', [DashboardController::class, 'collectionsTrend']);
        Route::get('/daily-dues', [DashboardController::class, 'dailyDues']);
        Route::get('/recent-transactions', [DashboardController::class, 'recentTransactions']);
    });

    // Fees
    Route::apiResource('fees', FeeController::class);

    // Loan Products
    Route::apiResource('loan-products', LoanProductController::class);

    // Loans
    Route::apiResource('loans', LoanController::class);
    Route::patch('/loans/{loan}/submit', [LoanController::class, 'submit']);
    Route::patch('/loans/{loan}/approve', [LoanController::class, 'approve']);
    Route::patch('/loans/{loan}/reject', [LoanController::class, 'reject']);
    Route::get('/loans/{loan}/release-preview', [LoanController::class, 'releasePreview']);
    Route::patch('/loans/{loan}/release', [LoanController::class, 'release']);
    Route::patch('/loans/{loan}/void', [LoanController::class, 'void']);
    Route::post('/loans/{loan}/extend', [LoanController::class, 'extend']);
    Route::post('/loans/{loan}/restructure', [LoanController::class, 'restructure']);
    Route::get('/loans/{loan}/ledger-entries', [LoanController::class, 'ledgerEntries']);
    Route::patch('/loans/{loan}/auto-pay', [LoanController::class, 'toggleAutoPay']);
    Route::get('/loans/{loan}/amortization-preview', [LoanController::class, 'amortizationPreview']);
    Route::get('/loans/{loan}/amortization-schedule', [LoanController::class, 'amortizationSchedule']);

    // Multi-step BOD approval chain.
    //
    // The child parameter is `{approvalStep}`, not `{step}`, because
    // scopeBindings() resolves it through Str::plural(Str::camel($param)) —
    // `approvalStep` finds Loan::approvalSteps(), `step` would look for a
    // steps() relation that does not exist and throw. Scoping is what makes a
    // step id from a DIFFERENT loan 404 at the router instead of reaching the
    // controller.
    Route::get('/loans/{loan}/approval-steps', [LoanApprovalStepController::class, 'index']);
    Route::patch('/loans/{loan}/approval-steps/{approvalStep}/approve', [LoanApprovalStepController::class, 'approve'])->scopeBindings();
    Route::patch('/loans/{loan}/approval-steps/{approvalStep}/send-back', [LoanApprovalStepController::class, 'sendBack'])->scopeBindings();

    // Repayments
    Route::get('/repayments', [RepaymentController::class, 'listAll']);
    Route::get('/loans/{loan}/repayments', [RepaymentController::class, 'index']);
    Route::post('/loans/{loan}/repayments/preview', [RepaymentController::class, 'preview']);
    Route::post('/loans/{loan}/repayments', [RepaymentController::class, 'store']);
    Route::get('/loans/{loan}/summary', [RepaymentController::class, 'summary']);
    Route::get('/repayments/{repayment}', [RepaymentController::class, 'show']);
    Route::patch('/repayments/{repayment}/void', [RepaymentController::class, 'void']);

    // Loan Documents
    Route::get('/loans/{loan}/disclosure', [DisclosureController::class, 'show']);
    Route::get('/loans/{loan}/promissory-note', [PromissoryNoteController::class, 'show']);

    // Loan Adjustments
    Route::get('/loans/{loan}/adjustments', [LoanAdjustmentController::class, 'index']);
    Route::post('/loans/{loan}/adjustments', [LoanAdjustmentController::class, 'store']);
    Route::get('/loan-adjustments/{loanAdjustment}', [LoanAdjustmentController::class, 'show']);
    Route::patch('/loan-adjustments/{loanAdjustment}/approve', [LoanAdjustmentController::class, 'approve']);
    Route::patch('/loan-adjustments/{loanAdjustment}/reject', [LoanAdjustmentController::class, 'reject']);
    Route::patch('/loan-adjustments/{loanAdjustment}/apply', [LoanAdjustmentController::class, 'apply']);

    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('/statement-of-account/{loan}', [ReportController::class, 'statementOfAccount']);
        Route::get('/subsidiary-ledger/{borrower}', [ReportController::class, 'subsidiaryLedger']);
        Route::get('/releases', [ReportController::class, 'listOfReleases']);
        Route::get('/repayments', [ReportController::class, 'listOfRepayments']);
        Route::get('/due-past-due', [ReportController::class, 'listOfDuePastDue']);
        Route::get('/loan-balance-summary', [ReportController::class, 'loanBalanceSummary']);
        Route::get('/daily-collection', [ReportController::class, 'dailyCollection']);
        Route::get('/income', [ReportController::class, 'incomeReport']);
        Route::get('/aging', [ReportController::class, 'agingReport']);
        Route::get('/borrowers', [ReportController::class, 'borrowerReport']);
        Route::get('/disbursements', [ReportController::class, 'disbursementReport']);

        // Financial reports (Part B)
        Route::get('/cash-flow', [ReportController::class, 'cashFlow']);
        Route::get('/collection-efficiency', [ReportController::class, 'collectionEfficiency']);
        Route::get('/portfolio-by-product', [ReportController::class, 'portfolioByProduct']);
        Route::get('/share-capital', [ReportController::class, 'shareCapital']);
        Route::get('/performance', [ReportController::class, 'performance']);
        Route::get('/provisioning', [ReportController::class, 'provisioning']);

        // Feeds the printable Share Capital Certificate — unpaginated, unlike
        // GET /api/share-capital/ledger which caps per_page at 100.
        Route::get('/share-capital-statement/{borrower}', [ReportController::class, 'shareCapitalStatement']);

        // CSV Exports (stricter rate limit — 5/min)
        Route::middleware('throttle:exports')->group(function () {
            Route::get('/releases/export', [ReportController::class, 'exportReleases']);
            Route::get('/repayments/export', [ReportController::class, 'exportRepayments']);
            Route::get('/due-past-due/export', [ReportController::class, 'exportDuePastDue']);
        });
    });

    // Roles — full CRUD for custom role management
    Route::get('/roles', [RoleController::class, 'index']);
    Route::get('/roles/{role}', [RoleController::class, 'show']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::put('/roles/{role}', [RoleController::class, 'update']);
    Route::patch('/roles/{role}/deactivate', [RoleController::class, 'deactivate']);
    Route::patch('/roles/{role}/reactivate', [RoleController::class, 'reactivate']);
    Route::delete('/roles/{role}', [RoleController::class, 'destroy']);

    // Audit Logs (read-only + CSV export)
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/audit-logs/export', [AuditLogController::class, 'export'])
        ->middleware('throttle:exports');
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show']);

    // Collaterals
    Route::get('/collaterals', [CollateralController::class, 'index']);
    Route::post('/collaterals', [CollateralController::class, 'store']);
    Route::get('/collaterals/{collateral}', [CollateralController::class, 'show']);
    Route::put('/collaterals/{collateral}', [CollateralController::class, 'update']);
    Route::delete('/collaterals/{collateral}', [CollateralController::class, 'destroy']);

    Route::get('/loans/{loan}/collaterals', [CollateralController::class, 'loanIndex']);
    Route::post('/loans/{loan}/collaterals', [CollateralController::class, 'attach']);
    Route::delete('/loans/{loan}/collaterals/{collateral}', [CollateralController::class, 'detach']);

    // Collateral Types
    Route::get('/collateral-types', [CollateralTypeController::class, 'index']);
    Route::post('/collateral-types', [CollateralTypeController::class, 'store']);
    // Must precede /{collateralType}: Laravel matches in registration order, so
    // a wildcard registered first would capture "reorder" as an id.
    Route::post('/collateral-types/reorder', [CollateralTypeController::class, 'reorder']);
    Route::get('/collateral-types/{collateralType}', [CollateralTypeController::class, 'show']);
    Route::put('/collateral-types/{collateralType}', [CollateralTypeController::class, 'update']);
    Route::delete('/collateral-types/{collateralType}', [CollateralTypeController::class, 'destroy']);

    // Share Capital Ledger
    Route::get('/share-capital/ledger', [ShareCapitalLedgerController::class, 'index']);
    Route::post('/share-capital/ledger', [ShareCapitalLedgerController::class, 'store']);

    // Share Capital Pledges
    Route::get('/pledges', [ShareCapitalPledgeController::class, 'index']);
    Route::put('/pledges/{pledge}', [ShareCapitalPledgeController::class, 'update']);
    Route::patch('/pledges/{pledge}/auto-credit', [ShareCapitalPledgeController::class, 'toggleAutoCredit']);
    Route::post('/pledges/{pledge}/entries', [ShareCapitalPledgeController::class, 'manualEntry']);
    Route::post('/pledges/bulk-entries', [ShareCapitalPledgeController::class, 'bulkEntry']);

    // Auto-Credit
    Route::get('/auto-credit/status', [AutoCreditController::class, 'status']);
    Route::post('/auto-credit/process', [AutoCreditController::class, 'process']);

    // Auto-Pay (CBS bulk loan deductions)
    Route::get('/auto-pay/preview', [AutoPayController::class, 'preview']);
    Route::post('/auto-pay/process', [AutoPayController::class, 'process']);

    // GCash Transactions
    Route::prefix('gcash')->group(function () {
        Route::get('/transactions', [GCashTransactionController::class, 'index']);
        Route::post('/transactions', [GCashTransactionController::class, 'store']);
        Route::patch('/transactions/{transaction}/paid', [GCashTransactionController::class, 'markPaid']);
        Route::get('/non-members', [GCashNonMemberController::class, 'index']);
        Route::post('/non-members', [GCashNonMemberController::class, 'store']);
        Route::put('/non-members/{nonMember}', [GCashNonMemberController::class, 'update']);
        Route::delete('/non-members/{nonMember}', [GCashNonMemberController::class, 'destroy']);
        Route::get('/tiers', [GCashTierController::class, 'index']);
        Route::put('/tiers', [GCashTierController::class, 'replace']);
        Route::get('/reports/income', [GCashReportController::class, 'income']);
        Route::get('/reports/pending', [GCashReportController::class, 'pending'])->name('gcash.reports.pending');
    });

    // Settings
    Route::get('/settings/approval-workflow', [ApprovalWorkflowController::class, 'show']);
    Route::put('/settings/approval-workflow', [ApprovalWorkflowController::class, 'update']);
    Route::delete('/settings/approval-workflow', [ApprovalWorkflowController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | CSV migration import
    |--------------------------------------------------------------------------
    |
    | The whole operator journey: opening a run, receiving each file a chunk at
    | a time, reassembling it, then closing the product-mapping gate, watching
    | the run and reading what went wrong. Gated on `imports:process` inside
    | each controller with `$this->authorize()`, like every other endpoint here
    | — this file carries no `permission:` middleware anywhere and this is not
    | the place to start. The limiter checks the same permission, so a caller
    | who will be refused never gets the migration-sized budget.
    |
    | THE NAMES ARE LOAD-BEARING, twice over. `throttle:imports` reads the route
    | name to decide which tier a call belongs to. And
    | `ThrottleRequests::class.':api'` is PREPENDED to the whole api group in
    | bootstrap/app.php, so a route-level throttle STACKS on top of the shared
    | 60/min rather than replacing it, and the lower of the two is what a caller
    | feels — which is why the `api` limiter also raises its ceiling for routes
    | named `imports.*`, the same mechanism `files.*` uses. Without that, an
    | upload hundreds of requests long throttles itself to a crawl, and a status
    | endpoint polled for the length of a long import starts 429-ing the very
    | screen showing its progress. Any import route added later should be named
    | `imports.*` and carry `throttle:imports` for the same reason.
    |
    | `{run}` is a plain id here, NOT a bound CsvImportRun. Bindings are
    | substituted before the controller and before its FormRequests, so a bound
    | model would tell a caller who is about to be refused whether run #N
    | exists — 404 for an unused id, 403 for a real one. Every action resolves
    | the id itself, after `imports:process`. `whereNumber('run')` on all three
    | so a non-numeric id never becomes a route match, matching the constraint
    | the status and mapping routes use.
    */
    Route::prefix('imports')->name('imports.')->middleware('throttle:imports')->group(function () {
        /*
         * Discovery, and the only way to find an open run without a run id.
         * A client normally keeps its id in local storage; a cleared browser, a
         * different device or a different admin picking up somebody's abandoned
         * migration all lose it, and an open run is then invisible AND blocking
         * — `POST /` 409s while one is open, so the operator is told an import
         * is already running with nothing they can see or cancel.
         *
         * Control tier, not `exports`: it is read on mount and polled
         * adjacently, and it is a single indexed lookup.
         */
        Route::get('/', [CsvImportController::class, 'index'])->name('index');

        Route::post('/', [CsvImportController::class, 'store'])->name('store');

        // PUT is the documented verb and works with either body encoding: PHP
        // itself populates $_FILES only for POST, but symfony/http-foundation
        // parses PUT bodies through PHP 8.4's request_parse_body(). POST is
        // accepted at the same URI as the compatibility path for any client
        // that cannot rely on that. See CsvImportController::resolveChunkBytes().
        Route::match(['put', 'post'], '/{run}/files/{kind}/chunks/{index}', [CsvImportController::class, 'uploadChunk'])
            ->whereNumber('run')
            ->whereIn('kind', ['customers', 'loans'])
            ->whereNumber('index')
            ->name('chunk');

        Route::post('/{run}/assemble', [CsvImportController::class, 'assemble'])
            ->whereNumber('run')
            ->name('assemble');

        // The escape hatch for a dead browser tab. Without it, a run left in
        // `uploading` blocks every future import at this cooperative forever,
        // because POST /imports refuses a second run while one is open and
        // nothing in the UI can clear it.
        Route::delete('/{run}', [CsvImportController::class, 'destroy'])
            ->whereNumber('run')
            ->name('destroy');

        // Polled for the whole length of an import, and a fixed number of
        // indexed queries, so it rides the control tier alone.
        Route::get('/{run}', [CsvImportStatusController::class, 'show'])
            ->whereNumber('run')
            ->name('show');

        Route::whereNumber('run')->group(function () {
            /*
             * `throttle:exports` STACKS on the import control tier for the two
             * heaviest reads, and the tighter one binds. Both walk the staged
             * rows in full — the product scan is a GROUP BY over a JSON
             * expression, the CSV streams every reported row — and both are
             * deliberate operator actions rather than polls.
             *
             * GET /errors is deliberately NOT here. It is a screen a human
             * pages through, its summary is computed on page one only, and
             * 5/min would 429 an admin on their sixth click.
             */
            Route::middleware('throttle:exports')->group(function () {
                Route::get('/{run}/product-mapping', [CsvImportMappingController::class, 'show'])->name('product-mapping.show');
                Route::put('/{run}/product-mapping', [CsvImportMappingController::class, 'update'])->name('product-mapping.update');
                Route::get('/{run}/errors.csv', [CsvImportErrorReportController::class, 'export'])->name('errors.export');
            });

            Route::get('/{run}/errors', [CsvImportErrorReportController::class, 'index'])->name('errors.index');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Accounting
    |--------------------------------------------------------------------------
    |
    | The double-entry foundation: the chart of accounts and the posting
    | defaults every automatic entry resolves through. Gated on the
    | `chart_of_accounts:*` and `accounting:settings` permissions inside the
    | controllers with `$this->authorize()`, like every other endpoint in this
    | file — no `permission:` middleware appears here and this is not the place
    | to start.
    |
    | The list endpoint answers with the raw Laravel paginator envelope
    | ({data, links, meta}); `POST /accounts/seed` answers with a flat
    | {data: Account[]} instead, because it returns the whole chart at once and
    | there is nothing to page through.
    */
    Route::prefix('accounting')->group(function () {
        Route::get('/accounts', [AccountingAccountController::class, 'index']);
        Route::post('/accounts', [AccountingAccountController::class, 'store']);

        // MUST precede /accounts/{account}. Laravel matches in registration
        // order, so a wildcard registered first captures "seed" as an id — the
        // same trap /collateral-types/reorder documents above. `whereNumber`
        // on the wildcards is the second lock.
        Route::post('/accounts/seed', [AccountingAccountController::class, 'seed']);

        Route::get('/accounts/{account}', [AccountingAccountController::class, 'show'])->whereNumber('account');
        Route::put('/accounts/{account}', [AccountingAccountController::class, 'update'])->whereNumber('account');
        Route::delete('/accounts/{account}', [AccountingAccountController::class, 'destroy'])->whereNumber('account');

        /*
         * Journals. Posting and reversing are separate VERBS, not a status
         * field on the update, because a posted entry is immutable: `reverse`
         * writes a second, mirrored entry rather than editing the first, and
         * `PUT /journals/{id}` refuses anything that is not a draft.
         *
         * Permissions are `journals:view|create|post|reverse`, checked in the
         * controller and the form requests with `$this->authorize()` /
         * `authorize()`, like every other endpoint in this file. Posting and
         * reversing are separate permissions on purpose — an accounting clerk
         * drafts entries all day and must not be able to put them into the
         * books unreviewed. See the accountant role in the permissions
         * migration, which holds `journals:create` and NOT `journals:post`.
         */
        Route::get('/journals', [AccountingJournalController::class, 'index']);
        Route::post('/journals', [AccountingJournalController::class, 'store']);
        Route::get('/journals/{journal}', [AccountingJournalController::class, 'show'])->whereNumber('journal');
        Route::put('/journals/{journal}', [AccountingJournalController::class, 'update'])->whereNumber('journal');
        Route::post('/journals/{journal}/post', [AccountingJournalController::class, 'post'])->whereNumber('journal');
        Route::post('/journals/{journal}/reverse', [AccountingJournalController::class, 'reverse'])->whereNumber('journal');

        /*
         * Reporting, all gated on `accounting:view`.
         *
         * No balance-sheet or income-statement route, deliberately: both are
         * regroupings of the trial balance and are built client-side from the
         * rows /trial-balance returns, so a figure has exactly one origin.
         */
        Route::get('/general-ledger', [AccountingReportController::class, 'generalLedger']);
        Route::get('/trial-balance', [AccountingReportController::class, 'trialBalance']);
        Route::get('/dashboard', [AccountingReportController::class, 'dashboard']);

        /*
         * ── Accounting reports, part two ──────────────────────────────────
         *
         * Kept as one contiguous block on purpose: several streams are editing
         * this file at once, and a merge conflict over a solid block is
         * mechanical while one over four routes interleaved with other
         * people's is not. Add to the bottom of this block, not into it.
         *
         * The BIR books of account. One path per book so the books screen can
         * pick by tab without a switch statement per call site; both answer the
         * same `{data: AccountingBook}` shape, because `book-report.tsx`
         * renders every book through ONE component keyed by `BookKind`. The
         * cash receipts and cash disbursements books are the other half of the
         * set and are not routed yet — they need the automatic posting engine
         * to tell a receipt from a disbursement by source.
         *
         * Note these are the FIRST accounting routes with a static segment
         * under a sub-prefix. They cannot collide with `/accounts/{account}`
         * (different prefix), but keep books under `/books/` rather than
         * flattening them, or `general-ledger` the book and `general-ledger`
         * the paginated per-account report above would fight for one path.
         */
        Route::get('/books/general-journal', [AccountingBookController::class, 'generalJournal']);
        Route::get('/books/general-ledger', [AccountingBookController::class, 'generalLedger']);

        /*
         * Aged receivables. Gated on `accounting:view` like the reports above,
         * but note it reads the LENDING tables rather than the journals — it is
         * a portfolio measure answering on an accounting screen, and it will
         * not reconcile to Loans Receivable on the trial balance until the
         * automatic posting engine lands.
         */
        Route::get('/loans/aging', [AccountingReportController::class, 'receivableAging']);

        /*
         * The money accounts. `cash_accounts:view`, NOT `chart_of_accounts:view`
         * — a branch manager gets the Cash & Bank screen without the chart, and
         * the permission vocabulary has carried that pair unused since the
         * accounting permissions migration precisely for this endpoint.
         *
         * Lives on AccountingAccountController because it is a filtered read of
         * `accounting_accounts` and reuses that controller's balance attachment
         * verbatim; a separate controller would have meant a second aggregate
         * over the journal lines, and two ways of computing one balance
         * eventually disagree.
         */
        Route::get('/cash-accounts', [AccountingAccountController::class, 'cashAccounts']);

        Route::get('/settings/account-mapping', [AccountingSettingsController::class, 'showAccountMapping']);
        Route::put('/settings/account-mapping', [AccountingSettingsController::class, 'updateAccountMapping']);

        /*
        |----------------------------------------------------------------------
        | Accounting — the write modules
        |----------------------------------------------------------------------
        |
        | Expenses, reconciliation, periods, fund transfer, and the two
        | statements that cannot be regrouped out of a trial balance. Kept as
        | ONE contiguous block because three other streams are editing this file
        | at the same time.
        |
        | Permissions are checked in the controllers and form requests with
        | `$this->authorize()` / `authorize()`, like every other endpoint in
        | this file — no `permission:` middleware appears here.
        |
        | `whereNumber` on every wildcard, and no literal segment is registered
        | after one, so nothing can be captured as an id. `/cash-accounts/
        | transfer` is a literal under a prefix with no wildcard at all.
        */

        /*
         * Expenses and payables. `expenses:view|create|update|pay`.
         *
         * Recording an expense posts its journal in the same transaction, which
         * is why there is no separate "post" verb here and why `PUT` accepts
         * only the descriptive fields — the figures ARE the posted entry, and a
         * posted entry is immutable. Correcting one means reversing the journal
         * and recording it again.
         *
         * `/pay` is a separate permission from `update` on purpose: settling a
         * payable takes money out of a cash account, and that is the step
         * nobody should be able to take on their own paperwork. Same split as
         * `journals:create` versus `journals:post`.
         *
         * The list answers with the raw Laravel paginator envelope and the
         * Expenses screen DRAINS it — it totals the outstanding balance
         * client-side, so a single page would be a headline figure that is
         * simply short.
         */
        Route::get('/expenses', [AccountingExpenseController::class, 'index']);
        Route::post('/expenses', [AccountingExpenseController::class, 'store']);
        Route::get('/expenses/{expense}', [AccountingExpenseController::class, 'show'])->whereNumber('expense');
        Route::put('/expenses/{expense}', [AccountingExpenseController::class, 'update'])->whereNumber('expense');
        Route::post('/expenses/{expense}/pay', [AccountingExpenseController::class, 'pay'])->whereNumber('expense');

        /*
         * Reconciliation, all on `accounting:reconcile` — the same permission
         * the screen's RouteGuard uses, and one the bookkeeper already holds.
         * Reconciling proves the books against an outside record; it never
         * adjusts them, so none of these writes a journal and none of them
         * needs a posting permission.
         */
        Route::get('/reconciliations', [AccountingReconciliationController::class, 'index']);
        Route::post('/reconciliations', [AccountingReconciliationController::class, 'store']);
        Route::get('/reconciliations/{reconciliation}', [AccountingReconciliationController::class, 'show'])
            ->whereNumber('reconciliation');
        Route::post('/reconciliations/{reconciliation}/match', [AccountingReconciliationController::class, 'match'])
            ->whereNumber('reconciliation');

        /*
         * Periods, on `accounting:close`.
         *
         * No create route, and none is missing: the months the books span are a
         * fact about the ledger rather than a decision, so PeriodCalendar
         * provisions them on read. Closing LOCKS — PeriodGuard is consulted
         * inside JournalPoster, the one writer of journals, so a closed month
         * refuses the manual entry screen, an expense, a transfer, a reversal
         * and every automatic posting alike.
         */
        Route::get('/periods', [AccountingPeriodController::class, 'index']);
        Route::post('/periods/{period}/close', [AccountingPeriodController::class, 'close'])->whereNumber('period');
        Route::post('/periods/{period}/reopen', [AccountingPeriodController::class, 'reopen'])->whereNumber('period');

        /*
         * Moving money between the organisation's own accounts. Returns the
         * posted JournalEntry, which is what `accountingService.transfer` is
         * typed to receive.
         *
         * `GET /cash-accounts` is NOT here — it belongs with the chart of
         * accounts, being that list filtered to the rows carrying a `cash_kind`.
         */
        Route::post('/cash-accounts/transfer', [AccountingCashAccountController::class, 'transfer']);

        /*
         * The two statements the client cannot build for itself, on
         * `accounting:view`.
         *
         * Still no balance-sheet or income-statement route, for the same reason
         * given above: both are regroupings of the trial balance and are built
         * from it in `src/lib/accounting/statements.ts`, so a figure has exactly
         * one origin. These two are not regroupings — cash flow needs a
         * classification that lives on the account, and changes in equity needs
         * opening balances as well as the movements between them.
         */
        Route::get('/statements/cash-flow', [AccountingStatementController::class, 'cashFlow']);
        Route::get('/statements/equity-changes', [AccountingStatementController::class, 'equityChanges']);
    });

    // Branding (organization logo + identity printed on reports and documents)
    Route::get('/settings/branding', [BrandingController::class, 'show']);
    Route::put('/settings/branding', [BrandingController::class, 'update']);
    Route::post('/settings/branding/logo', [BrandingController::class, 'uploadLogo']);
    Route::delete('/settings/branding/logo', [BrandingController::class, 'deleteLogo']);
});

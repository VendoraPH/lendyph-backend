<?php

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
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DisclosureController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FeeController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\GCashReportController;
use App\Http\Controllers\Api\GCashTierController;
use App\Http\Controllers\Api\GCashTransactionController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\LoanAdjustmentController;
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
Route::post('/borrowers', [BorrowerController::class, 'store'])
    ->middleware([OptionalSanctumAuth::class, 'throttle:public-registration', CheckTokenExpiry::class, EnsureUserIsActive::class]);

Route::post('/borrowers/{borrower}/photo', [BorrowerController::class, 'uploadPhoto'])
    ->middleware([AllowAuthOrSubmissionToken::class, 'throttle:registration-uploads', CheckTokenExpiry::class, EnsureUserIsActive::class]);

Route::post('/borrowers/{borrower}/valid-ids', [BorrowerController::class, 'uploadValidId'])
    ->middleware([AllowAuthOrSubmissionToken::class, 'throttle:registration-uploads', CheckTokenExpiry::class, EnsureUserIsActive::class]);

// Protected routes
Route::middleware(['auth:sanctum', CheckTokenExpiry::class, EnsureUserIsActive::class])->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/auth/me', [AuthController::class, 'updateMe']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    // Users
    Route::apiResource('users', UserController::class)->except(['destroy']);
    Route::patch('/users/{user}/deactivate', [UserController::class, 'deactivate']);
    Route::patch('/users/{user}/reactivate', [UserController::class, 'reactivate']);
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);

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
    Route::patch('/loans/{loan}/release', [LoanController::class, 'release']);
    Route::patch('/loans/{loan}/void', [LoanController::class, 'void']);
    Route::post('/loans/{loan}/extend', [LoanController::class, 'extend']);
    Route::post('/loans/{loan}/restructure', [LoanController::class, 'restructure']);
    Route::get('/loans/{loan}/ledger-entries', [LoanController::class, 'ledgerEntries']);
    Route::patch('/loans/{loan}/auto-pay', [LoanController::class, 'toggleAutoPay']);
    Route::get('/loans/{loan}/amortization-preview', [LoanController::class, 'amortizationPreview']);
    Route::get('/loans/{loan}/amortization-schedule', [LoanController::class, 'amortizationSchedule']);

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
    | CSV migration import — upload
    |--------------------------------------------------------------------------
    |
    | Opening a run, receiving each file a chunk at a time, and reassembling
    | them. Gated on `imports:process` inside the controller with
    | `$this->authorize()`, like every other endpoint here — this file carries no
    | `permission:` middleware anywhere and this is not the place to start.
    |
    | The names matter twice over. `throttle:imports` reads the route name to
    | decide which tier a call belongs to, and the `api` limiter matches
    | `imports.*` to stop the shared 60/min ceiling from capping an upload that
    | is hundreds of legitimate requests long. Any import route added later —
    | status, mapping, the error report — should be named `imports.*` and
    | carry `throttle:imports` for the same reason.
    */
    Route::prefix('imports')->name('imports.')->middleware('throttle:imports')->group(function () {
        Route::post('/', [CsvImportController::class, 'store'])->name('store');

        // PUT is the documented verb and works with either body encoding: PHP
        // itself populates $_FILES only for POST, but symfony/http-foundation
        // parses PUT bodies through PHP 8.4's request_parse_body(). POST is
        // accepted at the same URI as the compatibility path for any client
        // that cannot rely on that. See CsvImportController::resolveChunkBytes().
        Route::match(['put', 'post'], '/{run}/files/{kind}/chunks/{index}', [CsvImportController::class, 'uploadChunk'])
            ->whereIn('kind', ['customers', 'loans'])
            ->whereNumber('index')
            ->name('chunk');

        Route::post('/{run}/assemble', [CsvImportController::class, 'assemble'])->name('assemble');

        // The escape hatch for a dead browser tab. Without it, a run left in
        // `uploading` blocks every future import at this cooperative forever,
        // because POST /imports refuses a second run while one is open and
        // nothing in the UI can clear it.
        Route::delete('/{run}', [CsvImportController::class, 'destroy'])->name('destroy');

        // GET /imports/{run} (status, with missing_chunks per file), the
        // mapping confirmation and the error report are owned separately; they
        // belong in this group.
    });

    // Branding (organization logo + identity printed on reports and documents)
    Route::get('/settings/branding', [BrandingController::class, 'show']);
    Route::put('/settings/branding', [BrandingController::class, 'update']);
    Route::post('/settings/branding/logo', [BrandingController::class, 'uploadLogo']);
    Route::delete('/settings/branding/logo', [BrandingController::class, 'deleteLogo']);
});

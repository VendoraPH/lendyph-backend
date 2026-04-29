<?php

use App\Http\Controllers\Api\ApprovalWorkflowController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutoCreditController;
use App\Http\Controllers\Api\BorrowerController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CollateralController;
use App\Http\Controllers\Api\CollateralTypeController;
use App\Http\Controllers\Api\CoMakerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DisclosureController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FeeController;
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
use App\Http\Middleware\CheckTokenExpiry;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

// Auth (stricter rate limit — 10/min per IP)
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');

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

    // Borrowers — bulk routes FIRST so `bulk` is not matched as a {borrower} parameter
    Route::patch('/borrowers/bulk-deactivate', [BorrowerController::class, 'bulkDeactivate']);
    Route::delete('/borrowers/bulk', [BorrowerController::class, 'bulkDestroy']);
    Route::apiResource('borrowers', BorrowerController::class);
    Route::patch('/borrowers/{borrower}/deactivate', [BorrowerController::class, 'deactivate']);
    Route::patch('/borrowers/{borrower}/reactivate', [BorrowerController::class, 'reactivate']);
    Route::post('/borrowers/{borrower}/photo', [BorrowerController::class, 'uploadPhoto']);
    Route::delete('/borrowers/{borrower}/photo', [BorrowerController::class, 'deletePhoto']);
    Route::get('/borrowers/{borrower}/valid-ids', [BorrowerController::class, 'listValidIds']);
    Route::post('/borrowers/{borrower}/valid-ids', [BorrowerController::class, 'uploadValidId']);
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

    // Settings
    Route::get('/settings/approval-workflow', [ApprovalWorkflowController::class, 'show']);
    Route::put('/settings/approval-workflow', [ApprovalWorkflowController::class, 'update']);
    Route::delete('/settings/approval-workflow', [ApprovalWorkflowController::class, 'destroy']);
});

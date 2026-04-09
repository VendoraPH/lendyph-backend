<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutoCreditController;
use App\Http\Controllers\Api\BorrowerController;
use App\Http\Controllers\Api\BranchController;
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

// Auth
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware(['auth:sanctum', CheckTokenExpiry::class, EnsureUserIsActive::class])->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    // Users
    Route::apiResource('users', UserController::class)->except(['destroy']);
    Route::patch('/users/{user}/deactivate', [UserController::class, 'deactivate']);
    Route::patch('/users/{user}/reactivate', [UserController::class, 'reactivate']);
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);

    // Branches
    Route::apiResource('branches', BranchController::class)->except(['destroy']);

    // Borrowers
    Route::apiResource('borrowers', BorrowerController::class);
    Route::patch('/borrowers/{borrower}/deactivate', [BorrowerController::class, 'deactivate']);
    Route::patch('/borrowers/{borrower}/reactivate', [BorrowerController::class, 'reactivate']);
    Route::post('/borrowers/{borrower}/photo', [BorrowerController::class, 'uploadPhoto']);
    Route::delete('/borrowers/{borrower}/photo', [BorrowerController::class, 'deletePhoto']);
    Route::post('/borrowers/{borrower}/valid-ids', [BorrowerController::class, 'uploadValidId']);

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
    Route::get('/loans/{loan}/amortization-preview', [LoanController::class, 'amortizationPreview']);
    Route::get('/loans/{loan}/amortization-schedule', [LoanController::class, 'amortizationSchedule']);

    // Repayments
    Route::get('/repayments', [RepaymentController::class, 'listAll']);
    Route::get('/loans/{loan}/repayments', [RepaymentController::class, 'index']);
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
    });

    // Roles (read-only)
    Route::get('/roles', [RoleController::class, 'index']);
    Route::get('/roles/{role}', [RoleController::class, 'show']);

    // Audit Logs (read-only)
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show']);

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
});

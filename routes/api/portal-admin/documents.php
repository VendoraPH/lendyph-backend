<?php

use App\Http\Controllers\Api\PortalAdmin\PortalDocumentShareController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Member Portal, staff side: "Share with member" (slice P2)
|--------------------------------------------------------------------------
|
| Required from routes/api.php INSIDE the staff group. Permissions are
| checked in the controller (member_portal:manage and borrowers:view)
| before the file is looked up. `{borrower}` uses the branch-scoped binding,
| so another branch's member answers 404; the file (`document_id` in the
| body) is looked up among that member's own and their loans' files.
*/
Route::prefix('portal-admin/members/{borrower}/documents')->whereNumber('borrower')->name('portal-admin.members.documents.')->group(function () {
    Route::post('/share', [PortalDocumentShareController::class, 'share'])->name('share');
    Route::post('/unshare', [PortalDocumentShareController::class, 'unshare'])->name('unshare');
});

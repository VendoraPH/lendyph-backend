<?php

namespace App\Http\Controllers\Api\Cic;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Cic\CicAccess;
use Illuminate\Http\Request;

/**
 * The base of every CIC controller. Authorization stays in the controllers
 * (the repo's rule: no route permission middleware): each action calls
 * authorizeCic() with its `cic:*` permission, which also requires
 * `branches:view_all` and answers 403 in words that never name a key.
 *
 * The switch (`CIC_ENABLED`) and Menu Visibility's `cic` key are handled before
 * any controller runs (EnsureCicEnabled, BlockHiddenModules): both answer 404.
 */
abstract class CicController extends Controller
{
    /**
     * 403 unless the signed-in user holds `branches:view_all` and at least one
     * of the permissions (CicAccess::VIEW, ::PREPARE, ::SUBMIT, ::REVIEW,
     * ::SETTINGS).
     *
     * @param  string|list<string>  $permissions
     */
    protected function authorizeCic(Request $request, string|array $permissions): User
    {
        /** @var User $user */
        $user = $request->user();
        app(CicAccess::class)->authorize($user, $permissions);

        return $user;
    }

    /** Whether the signed-in user may see members' personal data (`borrowers:view`). */
    protected function seesPersonalData(Request $request): bool
    {
        return app(CicAccess::class)->seesPersonalData($request->user());
    }
}

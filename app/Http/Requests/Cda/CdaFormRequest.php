<?php

namespace App\Http\Requests\Cda;

use App\Services\Cda\CdaAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A CDA write's request: the caller needs `branches:view_all` and the
 * request's permission (CdaAccess), checked before validation so a caller
 * without them gets 403, never a list of field errors.
 */
abstract class CdaFormRequest extends FormRequest
{
    /** The permission this request needs, or null for the template manager (super_admin). */
    abstract protected function permission(): ?string;

    public function authorize(): bool
    {
        $access = app(CdaAccess::class);
        $permission = $this->permission();

        if ($permission === null) {
            $access->authorizeTemplateManager($this->user());
        } else {
            $access->authorize($this->user(), $permission);
        }

        return true;
    }
}

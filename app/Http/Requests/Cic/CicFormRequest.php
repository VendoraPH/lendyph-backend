<?php

namespace App\Http\Requests\Cic;

use App\Services\Cic\CicAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A CIC write's request: the caller needs `branches:view_all` and the
 * request's `cic:*` permission (CicAccess), checked before validation, so a
 * caller without them gets 403 in words, never a list of field errors.
 */
abstract class CicFormRequest extends FormRequest
{
    /** The permission this request needs (CicAccess::SETTINGS, ::PREPARE …). */
    abstract protected function permission(): string;

    public function authorize(): bool
    {
        app(CicAccess::class)->authorize($this->user(), $this->permission());

        return true;
    }

    /** No control or invisible format characters in a line of text. */
    protected const PLAIN_TEXT = 'not_regex:/[\p{Cc}\p{Cf}]/u';

    /** A reason: text, up to 500 characters, control characters other than line breaks refused. */
    protected static function reasonRules(): array
    {
        return ['required', 'string', 'max:500', 'not_regex:/[^\P{Cc}\n\t]|\p{Cf}/u'];
    }
}

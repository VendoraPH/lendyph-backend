<?php

namespace App\Http\Requests\MenuVisibility;

use App\Services\MenuVisibility\AdministratorOverride;
use App\Services\MenuVisibility\MenuModules;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PUT /menu-visibility (finish run round 6): the modules to hide, as the full
 * list of switched-off keys. Needs `menu_visibility:manage` (403 in words),
 * then an administrator override open on this sign-in (403
 * `override_required`), then known keys only (422).
 */
class UpdateMenuVisibilityRequest extends FormRequest
{
    public const PERMISSION = 'menu_visibility:manage';

    public const NO_ACCESS = "You don't have access to Menu Visibility.";

    public const OVERRIDE_REQUIRED = 'Enter the administrator password to open Menu Visibility.';

    private ?CarbonInterface $overrideExpiresAt = null;

    public function authorize(): bool
    {
        $this->overrideExpiresAt = self::ensureOpen($this, app(AdministratorOverride::class));

        return true;
    }

    /** @throws AuthorizationException */
    public static function ensureCanManage(Request $request): void
    {
        if (! $request->user()?->can(self::PERMISSION)) {
            throw new AuthorizationException(self::NO_ACCESS);
        }
    }

    /**
     * The permission, then an override open on this sign-in's token.
     *
     * @return CarbonInterface when the open override ends
     *
     * @throws AuthorizationException|HttpResponseException
     */
    public static function ensureOpen(Request $request, AdministratorOverride $override): CarbonInterface
    {
        self::ensureCanManage($request);

        return $override->expiresAt($request->user()->currentAccessToken())
            ?? throw new HttpResponseException(response()->json([
                'message' => self::OVERRIDE_REQUIRED,
                'code' => 'override_required',
            ], 403));
    }

    public function overrideExpiresAt(): ?CarbonInterface
    {
        return $this->overrideExpiresAt;
    }

    public function rules(): array
    {
        return [
            'hidden' => ['present', 'array'],
            'hidden.*' => ['bail', 'string', 'distinct', Rule::in(MenuModules::keys())],
        ];
    }

    public function messages(): array
    {
        return [
            'hidden.present' => 'Send the menus to hide as a list.',
            'hidden.array' => 'Send the menus to hide as a list.',
            'hidden.*.string' => "That isn't a menu Menu Visibility can switch.",
            'hidden.*.in' => "That isn't a menu Menu Visibility can switch.",
            'hidden.*.distinct' => 'That menu is listed twice.',
        ];
    }
}

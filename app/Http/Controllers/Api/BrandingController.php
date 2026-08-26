<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandingSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BrandingController extends Controller
{
    #[OA\Get(
        path: '/api/branding/public',
        summary: 'Get public organization branding',
        description: 'Unauthenticated. Returns the cooperative logo URL and organization identity for public pages (login, registration). Every field is null on a fresh deployment. Deliberately public: the sign-in screen names the cooperative before anyone has an account, and the same identity is printed on documents handed to members. Nothing beyond these four fields is exposed here.',
        tags: ['Settings'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Public branding',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'logo_url', type: 'string', nullable: true, example: 'http://localhost/storage/branding/logo.png'),
                                new OA\Property(property: 'organization_name', type: 'string', nullable: true, example: 'Binhs Multi-Purpose Cooperative'),
                                new OA\Property(property: 'organization_address', type: 'string', nullable: true, example: '123 Rizal St., Brgy. Poblacion, Bacolod City'),
                                new OA\Property(property: 'organization_contact', type: 'string', nullable: true, example: '(034) 123-4567 / info@binhscoop.ph', description: 'Served to unauthenticated callers. Free text with no format constraint, so treat any value as world-readable: use an organizational landline and a shared inbox. A named staff member plus a personal mobile number would publish personal information with no access control under RA 10173 (Data Privacy Act).'),
                            ],
                        ),
                    ],
                ),
            ),
        ],
    )]
    public function publicShow(): JsonResponse
    {
        return response()->json([
            'data' => $this->publicBrandingPayload(BrandingSetting::current()),
        ]);
    }

    #[OA\Get(
        path: '/api/branding/logo',
        summary: 'Stream the organization logo',
        description: 'Serves the logo bytes through the API rather than the public storage path. Unauthenticated, like /api/branding/public — the sign-in page renders it before anyone has logged in.',
        tags: ['Settings'],
        responses: [
            new OA\Response(response: 200, description: 'Logo image bytes'),
            new OA\Response(response: 404, description: 'No logo configured'),
        ],
    )]
    public function publicLogo(): StreamedResponse
    {
        $path = BrandingSetting::current()->logo_path;
        $disk = Storage::disk('public');

        abort_if(! $path || ! $disk->exists($path), 404);

        // Deliberately served through PHP rather than letting callers hit
        // /storage/** directly. nginx serves that path off the public/storage
        // symlink via try_files and never reaches PHP, so HandleCors cannot add
        // a header there — which is why the report exporters' cross-origin
        // fetch of the logo silently failed and every PDF/DOCX fell back to a
        // text header. Adding `storage/*` to cors.paths does nothing for the
        // same reason; this route is under `api/*`, which cors.paths covers.
        //
        // Unlike the KYC files in FileController, this is genuinely public
        // branding rendered before login, so it is cacheable and needs no
        // signature.
        return $disk->response($path, null, [
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    #[OA\Get(
        path: '/api/settings/branding',
        summary: 'Get organization branding',
        tags: ['Settings'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Branding settings',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'logo_url', type: 'string', nullable: true),
                                new OA\Property(property: 'organization_name', type: 'string', nullable: true),
                                new OA\Property(property: 'organization_address', type: 'string', nullable: true),
                                new OA\Property(property: 'organization_contact', type: 'string', nullable: true),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden (missing settings:view)'),
        ],
    )]
    public function show(): JsonResponse
    {
        $this->authorize('settings:view');

        return response()->json([
            'data' => $this->brandingPayload(BrandingSetting::current()),
        ]);
    }

    #[OA\Put(
        path: '/api/settings/branding',
        summary: 'Update organization identity',
        description: 'Sets the cooperative name, address and contact printed on reports and legal documents (promissory note, disclosure statement). Only the keys present in the request are written, so a caller may update one field without clearing the others. Blank strings arrive as null via ConvertEmptyStringsToNull, which is what lets the client fall back to the app-level name.',
        tags: ['Settings'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'organization_name', type: 'string', nullable: true, maxLength: 255, example: 'Binhs Multi-Purpose Cooperative'),
                    new OA\Property(property: 'organization_address', type: 'string', nullable: true, maxLength: 500, example: '123 Rizal St., Brgy. Poblacion, Bacolod City'),
                    new OA\Property(property: 'organization_contact', type: 'string', nullable: true, maxLength: 255, example: '(034) 123-4567 / info@binhscoop.ph', description: 'Printed on reports and legal documents, and also served unauthenticated by GET /api/branding/public. Free text with no format constraint — use an organizational landline and a shared inbox, not a named staff member and a personal mobile number, which this endpoint would publish with no access control under RA 10173 (Data Privacy Act).'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated branding settings — identical shape to GET /api/settings/branding',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'logo_url', type: 'string', nullable: true),
                                new OA\Property(property: 'organization_name', type: 'string', nullable: true),
                                new OA\Property(property: 'organization_address', type: 'string', nullable: true),
                                new OA\Property(property: 'organization_contact', type: 'string', nullable: true),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden (missing settings:update)'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(Request $request): JsonResponse
    {
        $this->authorize('settings:update');

        $validated = $request->validate([
            'organization_name' => ['nullable', 'string', 'max:255'],
            'organization_address' => ['nullable', 'string', 'max:500'],
            'organization_contact' => ['nullable', 'string', 'max:255'],
        ]);

        // Only the submitted keys are written — validate() drops absent ones —
        // so a partial payload leaves the untouched fields as they were.
        $setting = BrandingSetting::current();
        $setting->update($validated);

        return response()->json([
            'data' => $this->brandingPayload($setting),
        ]);
    }

    #[OA\Post(
        path: '/api/settings/branding/logo',
        summary: 'Upload organization logo',
        description: 'Replaces the current logo. The previous file is removed only after the new one is stored successfully.',
        tags: ['Settings'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['logo'],
                    properties: [
                        new OA\Property(property: 'logo', type: 'string', format: 'binary', description: 'Image file, max 5 MB'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logo uploaded',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'logo_url', type: 'string', example: 'http://localhost/storage/branding/logo.png'),
                                new OA\Property(property: 'message', type: 'string', example: 'Logo updated successfully.'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden (missing settings:update)'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function uploadLogo(Request $request): JsonResponse
    {
        $this->authorize('settings:update');

        $request->validate([
            'logo' => ['required', 'image', 'max:5120'],
        ]);

        // Store the new logo BEFORE deleting the old one so a failed upload
        // cannot leave the deployment without branding.
        $setting = BrandingSetting::current();
        $oldPath = $setting->logo_path;
        $newPath = $request->file('logo')->store('branding', 'public');
        $setting->update(['logo_path' => $newPath]);

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'data' => [
                'logo_url' => $setting->logo_url,
                'message' => 'Logo updated successfully.',
            ],
        ]);
    }

    #[OA\Delete(
        path: '/api/settings/branding/logo',
        summary: 'Remove organization logo',
        tags: ['Settings'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logo removed',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'logo_url', type: 'string', nullable: true, example: null),
                                new OA\Property(property: 'message', type: 'string', example: 'Logo removed successfully.'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden (missing settings:update)'),
        ],
    )]
    public function deleteLogo(): JsonResponse
    {
        $this->authorize('settings:update');

        $setting = BrandingSetting::current();

        if ($setting->logo_path) {
            Storage::disk('public')->delete($setting->logo_path);
            $setting->update(['logo_path' => null]);
        }

        return response()->json([
            'data' => [
                'logo_url' => null,
                'message' => 'Logo removed successfully.',
            ],
        ]);
    }

    /**
     * The four fields GET /api/branding/public serves to anyone.
     *
     * Adding a key here publishes it to unauthenticated callers on the public
     * internet. There is no second gate. An admin-only branding field — a BIR
     * TIN, a CDA registration number, an SMS sender id, a default notification
     * address — belongs in brandingPayload() below, which publicShow() cannot
     * reach.
     *
     * @return array{logo_url: string|null, organization_name: string|null, organization_address: string|null, organization_contact: string|null}
     */
    private function publicBrandingPayload(BrandingSetting $setting): array
    {
        return [
            'logo_url' => $setting->logo_url,
            'organization_name' => $setting->organization_name,
            'organization_address' => $setting->organization_address,
            'organization_contact' => $setting->organization_contact,
        ];
    }

    /**
     * Everything an authenticated settings reader sees.
     *
     * Backs show() (settings:view) and update() (settings:update). Today it is
     * exactly the public payload — reports and printables build their letterhead
     * from those same fields, so the two reads cannot drift apart. Private keys
     * get appended here, never to publicBrandingPayload().
     *
     * @return array{logo_url: string|null, organization_name: string|null, organization_address: string|null, organization_contact: string|null}
     */
    private function brandingPayload(BrandingSetting $setting): array
    {
        return [
            ...$this->publicBrandingPayload($setting),
            // Admin-only branding fields go here.
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\Cic;

use App\Http\Requests\Cic\RetireCicPublicKeyRequest;
use App\Http\Requests\Cic\StoreCicPublicKeyRequest;
use App\Http\Requests\Cic\VerifyCicPublicKeyRequest;
use App\Models\CicPublicKey;
use App\Services\Cic\CicAccess;
use App\Services\Cic\Settings\CicPublicKeyService;
use App\Services\Cic\Settings\CicSettingsPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** CIC's public keys and their history (prompt §13.1). The armored text is never sent back. */
class CicPublicKeyController extends CicController
{
    #[OA\Get(
        path: '/api/cic/public-keys',
        summary: 'CIC public key history',
        description: 'Needs `cic:settings` and `branches:view_all`. Every key, newest first: {id, fingerprint, fingerprint_display, key_id, algorithm, user_ids, key_created_at, expires_at, expired, status pending|active|retired, uploaded_by, uploaded_at, verified_by, verified_at, retired_by, retired_at, retire_reason, can_verify}. Never the armored text.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The key history'), new OA\Response(response: 403, description: 'No access, in words')],
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $this->authorizeCic($request, CicAccess::SETTINGS);

        $keys = CicPublicKey::query()->with(['uploader', 'verifier', 'retirer'])->orderByDesc('id')->get()
            ->map(fn (CicPublicKey $key) => CicSettingsPresenter::publicKey($key, $user))->values();

        return response()->json(['success' => true, 'data' => $keys]);
    }

    #[OA\Post(
        path: '/api/cic/public-keys',
        summary: 'Upload CIC\'s public key',
        description: 'Needs `cic:settings` and `branches:view_all`. `armored` (the key text) or `file` (the .asc file), at most 64 KB. GnuPG reads it on the server; refused in words (422) when it is a private key, several keys, revoked, expired, not yet valid, can\'t encrypt, isn\'t a key, or the server has no GnuPG. Stored pending until a second person verifies it. Audited.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'The pending key'), new OA\Response(response: 403, description: 'No access, in words'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function store(StoreCicPublicKeyRequest $request, CicPublicKeyService $service): JsonResponse
    {
        $key = $service->upload($request->keyText(), $request->user(), $request->keyField());

        return response()->json([
            'success' => true,
            'message' => 'Key uploaded. Another person with access to CIC settings must now check its fingerprint with CIC and verify it.',
            'data' => CicSettingsPresenter::publicKey($key->refresh(), $request->user()),
        ], 201);
    }

    #[OA\Post(
        path: '/api/cic/public-keys/{key}/verify',
        summary: 'Verify a CIC public key',
        description: 'Needs `cic:settings` and `branches:view_all`, and a different person from the uploader (403 in words). `fingerprint`: the fingerprint checked with CIC, spaces and case ignored; a mismatch is 422. Retires the active key ("Replaced by <fingerprint>") and makes this one active, in one transaction. Audited.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'fingerprint', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'The active key'), new OA\Response(response: 403, description: 'No access, or the uploader'), new OA\Response(response: 409, description: 'A simultaneous change'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function verify(VerifyCicPublicKeyRequest $request, CicPublicKey $key, CicPublicKeyService $service): JsonResponse
    {
        $key = $service->verify($key, (string) $request->validated('fingerprint'), $request->user());

        return response()->json(['success' => true, 'message' => 'Key verified and active. New files are encrypted to it.', 'data' => CicSettingsPresenter::publicKey($key->refresh(), $request->user())]);
    }

    #[OA\Post(
        path: '/api/cic/public-keys/{key}/retire',
        summary: 'Retire a CIC public key',
        description: 'Needs `cic:settings` and `branches:view_all`. `reason` (up to 500 characters). A pending or active key; a retired one is 422. Audited.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'The retired key'), new OA\Response(response: 403, description: 'No access, in words'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function retire(RetireCicPublicKeyRequest $request, CicPublicKey $key, CicPublicKeyService $service): JsonResponse
    {
        $key = $service->retire($key, (string) $request->validated('reason'), $request->user());

        return response()->json(['success' => true, 'message' => 'Key retired.', 'data' => CicSettingsPresenter::publicKey($key->refresh(), $request->user())]);
    }
}

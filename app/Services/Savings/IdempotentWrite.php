<?php

namespace App\Services\Savings;

use App\Models\PortalIdempotencyKey;
use App\Models\SavingsIdempotencyKey;
use App\Services\GeneratedNumberWrite;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The `Idempotency-Key` contract of every savings money post (design §4):
 *
 * 1. The header must be a UUID; otherwise 422 on `idempotency_key`.
 * 2. A key this user already used: with another request (method, path or
 *    canonical body) 422; with the same one, the stored status and body are
 *    answered again with `Idempotent-Replayed: true`, and nothing is posted.
 * 3. A new key: inside GeneratedNumberWrite::run (so a number collision is
 *    retried and a lock wait timeout answers 409), the key row is inserted
 *    FIRST (lock step 1), then the write runs, then its response is stored,
 *    all in one transaction.
 *    - A concurrent duplicate blocks on the unique index until the first
 *      commits, then fails as a duplicate and is answered with the replay.
 *    - A failure (an exception, or a response of 400 or more) stores nothing,
 *      so the same key can be sent again.
 *
 * Usage in a controller:
 *
 *     return $idempotent->run($request, fn () => response()->json([...], 201));
 *
 * **Stores.** The key is kept per caller in a store: `savings` (the default:
 * `savings_idempotency_keys`, keyed on the staff user) or `portal`
 * (`portal_idempotency_keys`, keyed on the signed-in portal user; a member's
 * portal request, portal design §6). Both have the same shape, so a key one
 * caller used never collides with another caller's.
 */
class IdempotentWrite
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAY_HEADER = 'Idempotent-Replayed';

    public const KEY_INDEX = 'savings_idempotency_keys_user_id_key_unique';

    public const STORE_SAVINGS = 'savings';

    public const STORE_PORTAL = 'portal';

    /**
     * Each store's model, owner column and unique index on (owner, key).
     *
     * @var array<string, array{0: class-string<Model>, 1: string, 2: string}>
     */
    private const STORES = [
        self::STORE_SAVINGS => [SavingsIdempotencyKey::class, 'user_id', self::KEY_INDEX],
        self::STORE_PORTAL => [PortalIdempotencyKey::class, 'portal_user_id', 'portal_idempotency_keys_portal_user_id_key_unique'],
    ];

    /**
     * @param  Closure(): JsonResponse  $write
     * @param  string  $store  self::STORE_SAVINGS (staff) or self::STORE_PORTAL (the signed-in member)
     *
     * @throws ValidationException
     */
    public function run(Request $request, Closure $write, string $conflictMessage = GeneratedNumberWrite::SAVINGS_CONFLICT, string $store = self::STORE_SAVINGS): JsonResponse
    {
        if (! isset(self::STORES[$store])) {
            throw new LogicException("\"{$store}\" is not an idempotency store.");
        }

        $key = $this->keyOf($request);
        $userId = (int) $request->user()->getKey();
        $hash = $this->hashOf($request);

        $stored = $this->stored($store, $userId, $key);

        if ($stored !== null) {
            return $this->replay($stored, $hash);
        }

        try {
            return GeneratedNumberWrite::run(
                fn (): JsonResponse => DB::transaction(fn (): JsonResponse => $this->attempt($store, $userId, $key, $hash, $write)),
                $conflictMessage,
            );
        } catch (UniqueConstraintViolationException $e) {
            $stored = $e->index === self::STORES[$store][2] ? $this->stored($store, $userId, $key) : null;

            if ($stored === null) {
                throw $e;
            }

            return $this->replay($stored, $hash);
        } catch (RefusedWrite $refused) {
            return $refused->response;
        }
    }

    private function attempt(string $store, int $userId, string $key, string $hash, Closure $write): JsonResponse
    {
        [$model, $owner] = self::STORES[$store];
        $row = $model::query()->create([
            $owner => $userId,
            'key' => $key,
            'request_hash' => $hash,
        ]);

        $response = $write();

        if (! $response instanceof JsonResponse) {
            throw new LogicException('An idempotent write must answer with JSON.');
        }

        if ($response->getStatusCode() >= 400) {
            throw new RefusedWrite($response);
        }

        $row->forceFill([
            'response_status' => $response->getStatusCode(),
            'response_body' => $response->getData(true),
        ])->save();

        return $response;
    }

    /** @throws ValidationException */
    private function keyOf(Request $request): string
    {
        $key = $request->header(self::HEADER);

        if (! is_string($key) || $key === '') {
            throw ValidationException::withMessages(['idempotency_key' => [
                'Send an Idempotency-Key header (a UUID) with this request, so a retry can never post it twice.',
            ]]);
        }

        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages(['idempotency_key' => [
                'The Idempotency-Key header must be a UUID.',
            ]]);
        }

        return strtolower($key);
    }

    /** The request's fingerprint: method, path and its body in canonical key order. */
    private function hashOf(Request $request): string
    {
        return hash('sha256', $request->method().' '.$request->path().' '.json_encode($this->canonical($request->all()), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->canonical(...), $value);
    }

    private function stored(string $store, int $userId, string $key): SavingsIdempotencyKey|PortalIdempotencyKey|null
    {
        [$model, $owner] = self::STORES[$store];

        return $model::query()->where($owner, $userId)->where('key', $key)->first();
    }

    /** @throws ValidationException */
    private function replay(SavingsIdempotencyKey|PortalIdempotencyKey $stored, string $hash): JsonResponse
    {
        if (! hash_equals($stored->request_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => [
                'This Idempotency-Key was already used for a different request. Send a new key for a new request.',
            ]]);
        }

        return response()->json($stored->response_body, (int) $stored->response_status)
            ->header(self::REPLAY_HEADER, 'true');
    }
}

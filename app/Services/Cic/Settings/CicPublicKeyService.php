<?php

namespace App\Services\Cic\Settings;

use App\Models\CicPublicKey;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * CIC's public keys (prompt §13.1): upload, verification by a second person,
 * and retirement. Nothing is deleted, so the table is the key history.
 *
 * - An upload is inspected by GnuPG (CicKeyInspector) and stored `pending`.
 * - A different `cic:settings` holder verifies it by typing the fingerprint
 *   they checked with CIC (spaces and case ignored). In one transaction the
 *   active key, if any, is retired ("Replaced by <fingerprint>") first, then
 *   this one becomes active. An active key is never silently replaced.
 * - A pending or active key can be retired with a reason.
 *
 * Every action writes an audit row naming the key by its fingerprint.
 */
final class CicPublicKeyService
{
    public const BUSY = 'Someone else changed CIC\'s keys at the same moment. Reload the page and try again.';

    public function __construct(private readonly CicKeyInspector $inspector) {}

    /** @throws ValidationException on a key that can't be used, in words */
    public function upload(string $armored, User $user, string $field = 'armored'): CicPublicKey
    {
        $armored = trim(str_replace("\r\n", "\n", $armored))."\n";

        try {
            $inspected = $this->inspector->inspect($armored);
        } catch (CicKeyRefusedException $e) {
            throw ValidationException::withMessages([$field => [$e->getMessage()]]);
        }

        $existing = CicPublicKey::query()->where('fingerprint', $inspected->fingerprint)->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([$field => [
                "This key was already uploaded ({$this->statusWords($existing->status)}). Each key is uploaded once; its history stays in the list.",
            ]]);
        }

        try {
            return DB::transaction(function () use ($inspected, $armored, $user) {
                $key = new CicPublicKey([
                    'fingerprint' => $inspected->fingerprint,
                    'key_id' => $inspected->keyId,
                    'algorithm' => $inspected->algorithm,
                    'user_ids' => $inspected->userIds,
                    'key_created_at' => $inspected->createdAt,
                    'expires_at' => $inspected->expiresAt,
                    'armored' => $armored,
                ]);
                $key->forceFill(['status' => CicPublicKey::STATUS_PENDING, 'uploaded_by' => $user->id])->save();

                AuditLogService::log('created', $key, null, [
                    'fingerprint' => $key->fingerprint,
                    'key_id' => $key->key_id,
                    'algorithm' => $key->algorithm,
                    'user_ids' => $key->user_ids,
                    'key_created_at' => $key->key_created_at?->toJSON(),
                    'expires_at' => $key->expires_at?->toJSON(),
                    'status' => 'Pending verification',
                ], "CIC public key {$key->fingerprint} uploaded, waiting for a second person to verify its fingerprint with CIC.", $user->id);

                return $key;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([$field => ['This key was already uploaded. Each key is uploaded once; its history stays in the list.']]);
        }
    }

    /** @throws ValidationException|HttpException */
    public function verify(CicPublicKey $key, string $typedFingerprint, User $user): CicPublicKey
    {
        try {
            return DB::transaction(function () use ($key, $typedFingerprint, $user) {
                /** @var CicPublicKey $locked */
                $locked = CicPublicKey::query()->whereKey($key->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== CicPublicKey::STATUS_PENDING) {
                    throw ValidationException::withMessages(['fingerprint' => ["This key is already {$this->statusWords($locked->status)}; only a key waiting for verification can be verified."]]);
                }

                if ($locked->uploaded_by === $user->id) {
                    abort(403, 'A second person verifies a key: you uploaded this one, so someone else with access to CIC settings must check its fingerprint with CIC.');
                }

                if ($locked->isExpired()) {
                    throw ValidationException::withMessages(['fingerprint' => ['This key has expired since it was uploaded, so it can\'t be made active. Upload CIC\'s current key.']]);
                }

                if (self::normalise($typedFingerprint) !== $locked->fingerprint) {
                    throw ValidationException::withMessages(['fingerprint' => ['The fingerprint you entered doesn\'t match this key. Check it with CIC again; if CIC\'s fingerprint is different, don\'t use this key.']]);
                }

                $now = Carbon::now();
                $previous = CicPublicKey::query()->where('status', CicPublicKey::STATUS_ACTIVE)->lockForUpdate()->first();

                if ($previous !== null) {
                    $previous->forceFill([
                        'status' => CicPublicKey::STATUS_RETIRED,
                        'retired_by' => $user->id,
                        'retired_at' => $now,
                        'retire_reason' => "Replaced by {$locked->fingerprint}",
                    ])->save();

                    AuditLogService::log('retired', $previous, ['status' => 'Active'], [
                        'status' => 'Retired',
                        'retire_reason' => $previous->retire_reason,
                    ], "CIC public key {$previous->fingerprint} retired: replaced by {$locked->fingerprint}.", $user->id);
                }

                $locked->forceFill([
                    'status' => CicPublicKey::STATUS_ACTIVE,
                    'verified_by' => $user->id,
                    'verified_at' => $now,
                ])->save();

                AuditLogService::log('verified', $locked, ['status' => 'Pending verification'], [
                    'status' => 'Active',
                    'replaces' => $previous?->fingerprint,
                ], "CIC public key {$locked->fingerprint} verified against CIC's fingerprint and made active".($previous === null ? '.' : ", replacing {$previous->fingerprint}."), $user->id);

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, self::BUSY);
        }
    }

    /** @throws ValidationException */
    public function retire(CicPublicKey $key, string $reason, User $user): CicPublicKey
    {
        return DB::transaction(function () use ($key, $reason, $user) {
            /** @var CicPublicKey $locked */
            $locked = CicPublicKey::query()->whereKey($key->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === CicPublicKey::STATUS_RETIRED) {
                throw ValidationException::withMessages(['reason' => ['This key is already retired.']]);
            }

            $was = $locked->status;
            $locked->forceFill([
                'status' => CicPublicKey::STATUS_RETIRED,
                'retired_by' => $user->id,
                'retired_at' => Carbon::now(),
                'retire_reason' => $reason,
            ])->save();

            AuditLogService::log('retired', $locked, ['status' => ucfirst($this->statusWords($was))], [
                'status' => 'Retired',
                'retire_reason' => $reason,
            ], "CIC public key {$locked->fingerprint} retired".($was === CicPublicKey::STATUS_ACTIVE ? '; no key is active now, so files can\'t be encrypted until another is verified.' : '.'), $user->id);

            return $locked;
        });
    }

    /** A typed fingerprint as stored: no spaces, upper case. */
    public static function normalise(string $fingerprint): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', $fingerprint));
    }

    private function statusWords(string $status): string
    {
        return match ($status) {
            CicPublicKey::STATUS_PENDING => 'waiting for verification',
            CicPublicKey::STATUS_ACTIVE => 'active',
            default => 'retired',
        };
    }
}

<?php

namespace App\Services\Cic;

use Closure;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The CIC screens' cache, guarded (security re-reviews SN1 and SN3):
 *
 * - a cache error never fails a request: the value is worked out uncached,
 *   and the log names only the exception class and SQLSTATE, never SQL or
 *   values;
 * - a value too big for the store (over `cic.cache_max_bytes`, 8 MB by
 *   default, under the database store's 16 MB column) is not cached;
 * - every key starts `cic:`, so the hourly `cic:clear-temp` can delete the
 *   expired rows the database store leaves behind.
 */
final class CicCache
{
    public const PREFIX = 'cic:';

    public const MAX_BYTES = 8 * 1024 * 1024;

    public static function remember(string $key, int $seconds, Closure $compute): mixed
    {
        $hit = self::get($key);

        if ($hit !== null) {
            return $hit;
        }

        $value = $compute();
        self::put($key, $value, $seconds);

        return $value;
    }

    public static function get(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (Throwable $e) {
            self::unavailable($e);

            return null;
        }
    }

    public static function put(string $key, mixed $value, int $seconds): bool
    {
        if (strlen(is_string($value) ? $value : serialize($value)) > self::maxBytes()) {
            return false;
        }

        try {
            return Cache::put($key, $value, $seconds);
        } catch (Throwable $e) {
            self::unavailable($e);

            return false;
        }
    }

    public static function maxBytes(): int
    {
        return (int) config('cic.cache_max_bytes', self::MAX_BYTES);
    }

    /**
     * Deletes the expired `cic:` rows of the database cache store (it removes
     * an expired row only when that key is read again). Other stores expire
     * their own entries. Returns the rows deleted.
     */
    public static function pruneExpired(): int
    {
        $name = (string) config('cache.default');
        $store = Cache::store($name)->getStore();

        if (! $store instanceof DatabaseStore) {
            return 0;
        }

        $config = (array) config("cache.stores.{$name}", []);

        return DB::connection($config['connection'] ?? null)
            ->table($config['table'] ?? 'cache')
            ->where('key', 'like', $store->getPrefix().self::PREFIX.'%')
            ->where('expiration', '<=', now()->getTimestamp())
            ->delete();
    }

    private static function unavailable(Throwable $e): void
    {
        Log::warning('CIC cache unavailable; the value was worked out uncached.', [
            'exception' => $e::class,
            'sqlstate' => $e instanceof QueryException ? ($e->errorInfo[0] ?? null) : null,
        ]);
    }
}

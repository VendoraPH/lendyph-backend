<?php

namespace App\Services\MenuVisibility;

use App\Models\MenuVisibilitySwitch;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Which side-menu modules this deployment hides (finish run round 6), from its
 * own database: what was switched (menu_visibility_switches), the effective
 * list with the submenus of a hidden menu, and saving a new list with one
 * audit row in words.
 *
 * The middleware reads the effective list on most requests, so it is cached in
 * the deployment's own default store, with no expiry:
 * - a request that finds no entry reads the switches and only adds the entry
 *   (Cache::add), so it never overwrites a newer one;
 * - saves run one at a time, under one lock (SAVE_LOCK), and each writes the
 *   entry from the committed switches before it lets the next one in
 *   (Cache::forever). So a save takes effect at once, two saves can't leave
 *   the older list cached, and a request that read the switches before a
 *   commit can't put the old list back.
 */
final class MenuVisibility
{
    public const CACHE_KEY = 'menu-visibility:hidden';

    public const AUDIT_CHANGED = 'menu_visibility_changed';

    public const SAVE_LOCK = 'menu-visibility:save';

    /** How long a save waits for the one in progress before it answers 409. */
    public const SAVE_WAIT_SECONDS = 3;

    public const BUSY = 'Another change to Menu Visibility is being saved. Try again in a moment.';

    /** @return list<string> the hidden modules, submenus of a hidden menu included, in the side menu's order */
    public function hidden(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $hidden = MenuModules::effective($this->switchedOff());
        } catch (QueryException $exception) {
            // A deploy serves the new code for a few seconds before `migrate`
            // creates the table, and nothing can be hidden before it exists.
            if (self::isMissingTable($exception)) {
                return [];
            }

            throw $exception;
        }

        Cache::add(self::CACHE_KEY, $hidden);

        return $hidden;
    }

    /** MySQL's "no such table" (SQLSTATE 42S02). */
    public static function isMissingTable(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? (string) $exception->getCode()) === '42S02';
    }

    /** Whether this request is a hidden module's own API. */
    public function blocks(Request $request): bool
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return false;
        }

        $segments = MenuModules::segmentsOf($route->uri());

        if ($segments === [] || ! in_array($segments[0], MenuModules::blockedFirstSegments(), true)) {
            return false;
        }

        return MenuModules::blockingModule($request->method(), $route->uri(), $this->hidden(), $request->input()) !== null;
    }

    /**
     * Every module with its own switch, for the settings page. A submenu keeps
     * its own switch while its menu is off; the effective list is hidden().
     *
     * @return list<array{key: string, label: string, parent: string|null, hidden: bool}>
     */
    public function modules(): array
    {
        $off = array_flip($this->switchedOff());

        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => MenuModules::label($key),
            'parent' => MenuModules::parentOf($key),
            'hidden' => isset($off[$key]),
        ], MenuModules::keys());
    }

    /**
     * Switch the modules so exactly `$hidden` are off. Only the modules whose
     * switch changes get a row written; a save that changes nothing writes
     * nothing, not even an audit row. Saves take the switches' rows in turn,
     * and a deadlock between two of them is tried again.
     *
     * @param  list<string>  $hidden  known module keys (validated by the caller)
     * @return bool whether anything changed
     */
    public function save(array $hidden, User $by): bool
    {
        $wanted = array_values(array_intersect(MenuModules::keys(), $hidden));

        try {
            return Cache::lock(self::SAVE_LOCK, 30)->block(self::SAVE_WAIT_SECONDS, fn (): bool => $this->saveHoldingTheLock($wanted, $by));
        } catch (LockTimeoutException) {
            abort(409, self::BUSY);
        }
    }

    /** @param  list<string>  $wanted */
    private function saveHoldingTheLock(array $wanted, User $by): bool
    {
        $changed = DB::transaction(function () use ($wanted, $by): bool {
            $rows = MenuVisibilitySwitch::query()->lockForUpdate()->get()->keyBy('module_key');
            $before = array_values(array_filter(MenuModules::keys(), fn (string $key) => (bool) $rows->get($key)?->hidden));

            $nowHidden = array_values(array_diff($wanted, $before));
            $nowShown = array_values(array_diff($before, $wanted));

            if ($nowHidden === [] && $nowShown === []) {
                return false;
            }

            foreach ([...$nowHidden, ...$nowShown] as $key) {
                MenuVisibilitySwitch::query()->updateOrCreate(
                    ['module_key' => $key],
                    ['hidden' => in_array($key, $nowHidden, true), 'updated_by' => $by->id],
                );
            }

            AuditLogService::log(
                self::AUDIT_CHANGED,
                null,
                ['hidden' => $before],
                ['hidden' => $wanted],
                'Menu visibility changed: '.implode(', ', [
                    ...array_map(fn (string $key) => MenuModules::words($key).' hidden', $nowHidden),
                    ...array_map(fn (string $key) => MenuModules::words($key).' shown', $nowShown),
                ]),
                $by->id,
            );

            return true;
        }, attempts: 3);

        if ($changed) {
            Cache::forever(self::CACHE_KEY, MenuModules::effective($this->switchedOff()));
        }

        return $changed;
    }

    /** @return list<string> the modules switched off, in the side menu's order, submenus of a hidden menu not added */
    private function switchedOff(): array
    {
        $off = MenuVisibilitySwitch::query()->where('hidden', true)->pluck('module_key')->all();

        return array_values(array_intersect(MenuModules::keys(), $off));
    }
}

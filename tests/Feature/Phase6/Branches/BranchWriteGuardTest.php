<?php

namespace Tests\Feature\Phase6\Branches;

use App\Services\Branches\BranchWriteGuard;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE2, review of #339: the branch lock answers 409 only while
 * waiting to take it, never for what the guarded write throws, releases it
 * either way, and works on the `database` cache store the deployments use
 * (`.env.example`), not only the test suite's `array` store.
 */
class BranchWriteGuardTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_what_the_write_throws_passes_through_and_the_lock_is_released(): void
    {
        $guard = new BranchWriteGuard(waitSeconds: 1);

        foreach ([new LockTimeoutException('a row lock timed out'), new RuntimeException('the write failed')] as $thrown) {
            try {
                $guard->exclusively($this->branch->id, fn () => throw $thrown);
                $this->fail('Nothing was thrown.');
            } catch (HttpException) {
                $this->fail('The write\'s own exception became the busy 409.');
            } catch (LockTimeoutException|RuntimeException $caught) {
                $this->assertSame($thrown, $caught);
            }
        }

        $this->assertSame('free', $guard->exclusively($this->branch->id, fn () => 'free'));
    }

    public function test_the_lock_holds_on_the_database_cache_store(): void
    {
        config(['cache.default' => 'database']);
        $guard = new BranchWriteGuard(waitSeconds: 1);

        $held = Cache::store('database')->lock("branch-status:{$this->branch->id}", 60);
        $this->assertTrue($held->get());

        try {
            $guard->exclusively($this->branch->id, fn () => $this->fail('The guarded write ran while the lock was held.'));
            $this->fail('The guard did not wait for the lock.');
        } catch (HttpException $busy) {
            $this->assertSame(409, $busy->getStatusCode());
            $this->assertSame(BranchWriteGuard::BUSY, $busy->getMessage());
        } finally {
            $held->release();
        }

        $this->assertSame('done', $guard->exclusively($this->branch->id, fn () => 'done'));
        $this->assertSame(0, DB::table('cache_locks')->count());
    }
}

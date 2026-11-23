<?php

namespace Tests\Feature\Phase6\Branches;

use App\Models\PortalRequest;
use App\Services\Branches\MemberLock;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Portal\Requests\PortalRequestsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE4: a portal request copies the member's branch, so it's
 * written holding the member's branch lock (MemberLock), with the member read
 * afresh under it. A transfer can't move the member around it.
 */
class PortalRequestMemberLockTest extends TestCase
{
    use PortalRequestsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortalRequests();
    }

    public function test_a_portal_request_holds_the_members_branch_lock_while_it_writes(): void
    {
        $held = null;

        DB::listen(function (QueryExecuted $query) use (&$held): void {
            if ($held !== null || ! str_starts_with(strtolower($query->sql), 'insert into `portal_requests`')) {
                return;
            }

            $probe = Cache::lock(MemberLock::key($this->member->id), 60);
            $held = ! $probe->get();

            if (! $held) {
                $probe->release();
            }
        });

        $this->submit(['type' => PortalRequest::TYPE_PROFILE_UPDATE, 'details' => ['city' => 'Quezon City'], 'remarks' => null])->assertCreated();

        $this->assertTrue($held === true, "The member's branch lock wasn't held when the portal request was written.");
    }
}

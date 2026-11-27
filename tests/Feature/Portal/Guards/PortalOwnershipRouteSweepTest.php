<?php

namespace Tests\Feature\Portal\Guards;

use App\Models\Branch;
use App\Models\Document;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\PortalAnnouncement;
use App\Models\PortalLoginEvent;
use App\Models\PortalRequest;
use App\Models\PortalUser;
use App\Models\Repayment;
use App\Models\SavingsAccount;
use App\Models\TimeDeposit;
use App\Notifications\PortalNotice;
use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Every portal route with an `{id}` answers 404 to a signed-in member asking
 * for another member's record (portal design §8), never 403 and never the
 * record. Built from the live route list: the group is the second part of
 * the route name (`portal.<group>.<action>`), and a group with no fixture
 * below fails the test, so a slice that adds a group adds its fixture here.
 *
 * A route must find the record before it validates the body: the sweep sends
 * an empty body and still expects 404.
 */
class PortalOwnershipRouteSweepTest extends TestCase
{
    use PortalTestHelpers, SetupLendyPH;

    public function test_every_portal_id_route_answers_404_for_another_members_record(): void
    {
        $this->seedAndLogin();
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);

        $me = $this->portalMember();
        $other = $this->portalMember();
        $token = $this->portalToken($me);
        $fixtures = $this->fixtures();

        $swept = [];
        $unmapped = [];
        $leaks = [];
        $built = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'api/portal/') || ! in_array('id', $route->parameterNames(), true)) {
                continue;
            }

            $group = explode('.', (string) $route->getName())[1] ?? '';

            if (! isset($fixtures[$group])) {
                $unmapped[] = "/{$route->uri()} (group `{$group}`)";

                continue;
            }

            $built[$group] ??= $fixtures[$group]($other, $me);
            $uri = '/'.str_replace('{id}', (string) $built[$group], $route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $status = $this->portalJson($method, $uri, [], $token)->status();
                $swept[] = "{$method} {$uri}";

                if ($status !== 404) {
                    $leaks[] = "{$method} /{$route->uri()} answered {$status}";
                }
            }
        }

        $this->assertSame([], $unmapped, "These portal routes' groups have no fixture in this test:\n  ".implode("\n  ", $unmapped));
        $this->assertNotEmpty($swept, 'The sweep found no portal {id} routes.');
        $this->assertSame([], $leaks, "These portal routes reach another member's record:\n  ".implode("\n  ", $leaks));
    }

    /**
     * Each fixture builds one record of its group that is NOT the signed-in
     * member's, and returns its id. Built here even before the routes exist,
     * so every group's fixture is checked by the test below.
     */
    public function test_every_fixture_builds_a_record_of_another_member(): void
    {
        $this->seedAndLogin();
        $me = $this->portalMember();
        $other = $this->portalMember();

        foreach ($this->fixtures() as $group => $build) {
            $id = $build($other, $me);
            $this->assertNotEmpty($id, "The `{$group}` fixture returned no id.");
        }
    }

    /** @return array<string, Closure(PortalUser, PortalUser): (int|string)> */
    private function fixtures(): array
    {
        $loan = fn (PortalUser $other): Loan => Loan::factory()->create([
            'branch_id' => $other->borrower->branch_id,
            'borrower_id' => $other->borrower_id,
            'loan_product_id' => LoanProduct::factory(),
            'created_by' => $this->admin->id,
            'status' => 'ongoing',
        ]);

        return [
            'sessions' => fn (PortalUser $other) => (int) $other->createToken('Other device', [PortalUser::ABILITY], now()->addHour())->accessToken->getKey(),
            'logins' => fn (PortalUser $other) => (int) PortalLoginEvent::factory()->create(['portal_user_id' => $other->id])->getKey(),
            'loans' => fn (PortalUser $other) => (int) $loan($other)->getKey(),
            'payments' => fn (PortalUser $other) => (int) Repayment::factory()->create(['loan_id' => $loan($other)->id, 'received_by' => $this->admin->id])->getKey(),
            'savings' => fn (PortalUser $other) => (int) SavingsAccount::factory()->create(['borrower_id' => $other->borrower_id])->getKey(),
            'time-deposits' => fn (PortalUser $other) => (int) TimeDeposit::factory()->create(['borrower_id' => $other->borrower_id])->getKey(),
            'documents' => fn (PortalUser $other) => (int) Document::create([
                'documentable_type' => $other->borrower->getMorphClass(),
                'documentable_id' => $other->borrower_id,
                'type' => 'other',
                'file_path' => 'documents/other/borrower/sweep/statement.pdf',
                'original_filename' => 'statement.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 10,
                'portal_shared_at' => now(),
                'portal_shared_by' => $this->admin->id,
            ])->getKey(),
            // Notification ids are UUIDs.
            'notifications' => function (PortalUser $other): string {
                $other->notify(new PortalNotice('security', 'Sweep notice', 'Not yours.'));

                return (string) $other->notifications()->latest('created_at')->firstOrFail()->getKey();
            },
            // An announcement outside the signed-in member's audience: another
            // branch only.
            'announcements' => fn (PortalUser $other) => (int) PortalAnnouncement::factory()->create([
                'audience' => PortalAnnouncement::AUDIENCE_BRANCH,
                'branch_ids' => [Branch::factory()->create()->id],
            ])->getKey(),
            'requests' => fn (PortalUser $other) => (int) PortalRequest::factory()->create(['portal_user_id' => $other->id])->getKey(),
        ];
    }
}

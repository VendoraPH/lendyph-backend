<?php

namespace Tests\Feature\ReceiptShareLinks;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\ReceiptShareLink;
use App\Models\Repayment;
use App\Models\Role;
use App\Models\User;
use App\Services\ReceiptShareLinks\ReceiptShareLinks;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Receipt share links, staff side (finish run round 2, decision 6): staff with
 * `payments:view` and the payment's branch make, read and revoke a receipt's
 * link. The token is 32 random bytes shown once; only its SHA-256 is stored.
 * One working link per receipt; 30 days; every route 404 while the switch is
 * off; making and revoking are audited.
 */
class ReceiptShareLinkStaffTest extends TestCase
{
    use SetupLendyPH;

    private Loan $loan;

    private Repayment $repayment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        config(['receipt_share_links.enabled' => true]);

        $this->loan = $this->createReleasedLoan();
        $this->repayment = Repayment::factory()->create([
            'loan_id' => $this->loan->id,
            'method' => 'gcash',
            'reference_number' => 'GC-9876543210',
            'received_by' => $this->admin->id,
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/repayments/{$this->repayment->id}/share-link{$suffix}";
    }

    public function test_making_a_link_answers_a_43_character_token_once_and_stores_only_its_sha256(): void
    {
        $this->freezeSecond();

        $response = $this->postJson($this->url())->assertCreated()
            ->assertJsonPath('data.link.status', 'active')
            ->assertJsonPath('data.link.status_label', 'Active')
            ->assertJsonPath('data.link.created_by', $this->admin->full_name)
            ->assertJsonPath('data.link.expires_at', now()->addDays(30)->toDateTimeString());
        $token = $response->json('data.token');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        $this->assertSame(32, strlen(base64_decode(strtr($token, '-_', '+/'), true)));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $row = (array) DB::table('receipt_share_links')->sole();
        $this->assertSame(hash('sha256', $token), $row['token_hash']);
        $this->assertSame((int) $this->repayment->id, (int) $row['repayment_id']);
        $this->assertSame((int) $this->admin->id, (int) $row['created_by']);
        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString($token, (string) $value, "{$column} holds the token");
        }

        $status = $this->getJson($this->url())->assertOk()->assertJsonPath('data.link.status', 'active');
        $this->assertStringNotContainsString($token, $status->getContent());
        $this->assertArrayNotHasKey('token', $status->json('data'));
        $this->assertArrayNotHasKey('token_hash', $status->json('data.link'));
        $this->assertStringNotContainsString($row['token_hash'], $status->getContent());
    }

    public function test_every_link_gets_its_own_token(): void
    {
        $second = Repayment::factory()->create(['loan_id' => $this->loan->id, 'received_by' => $this->admin->id]);

        $first = $this->postJson($this->url())->assertCreated()->json('data.token');
        $other = $this->postJson("/api/repayments/{$second->id}/share-link")->assertCreated()->json('data.token');

        $this->assertNotSame($first, $other);
    }

    public function test_a_receipt_has_one_working_link_at_a_time(): void
    {
        $this->postJson($this->url())->assertCreated();

        $this->postJson($this->url())->assertStatus(409)
            ->assertJsonPath('message', ReceiptShareLinks::ALREADY_ACTIVE);
        $this->assertSame(1, ReceiptShareLink::query()->count());

        $this->patchJson($this->url('/revoke'))->assertOk();
        $this->postJson($this->url())->assertCreated();

        $this->travel(30)->days();
        $this->travel(1)->seconds();
        $this->postJson($this->url())->assertCreated();

        $this->assertSame(1, ReceiptShareLink::query()->where('repayment_id', $this->repayment->id)->active()->count());
        $this->assertSame(3, ReceiptShareLink::query()->where('repayment_id', $this->repayment->id)->count());
    }

    public function test_the_status_reads_none_active_expired_and_revoked_and_never_the_token(): void
    {
        $this->getJson($this->url())->assertOk()->assertExactJson(['data' => ['link' => null]]);

        $this->freezeSecond();
        $token = $this->postJson($this->url())->json('data.token');
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.link.status', 'active')
            ->assertJsonPath('data.link.revoked_at', null);

        $this->travel(30)->days();
        $this->getJson($this->url())->assertJsonPath('data.link.status', 'expired')
            ->assertJsonPath('data.link.status_label', 'Expired');
        $this->getJson("/api/public/receipts/{$token}")->assertNotFound();

        $this->postJson($this->url())->assertCreated();
        $this->patchJson($this->url('/revoke'))->assertOk()
            ->assertJsonPath('data.link.status', 'revoked')
            ->assertJsonPath('data.link.revoked_by', $this->admin->full_name)
            ->assertJsonPath('data.link.revoked_at', now()->toDateTimeString());

        // Revoked stays revoked once its 30 days run out too.
        $this->travel(31)->days();
        $this->getJson($this->url())->assertJsonPath('data.link.status', 'revoked')
            ->assertJsonPath('data.link.status_label', 'Revoked');
    }

    public function test_revoking_stops_the_link_at_once_and_needs_a_working_link(): void
    {
        $this->patchJson($this->url('/revoke'))->assertStatus(409)
            ->assertJsonPath('message', ReceiptShareLinks::NONE_ACTIVE);

        $token = $this->postJson($this->url())->json('data.token');
        $this->getJson("/api/public/receipts/{$token}")->assertOk();

        $this->patchJson($this->url('/revoke'))->assertOk();
        $this->getJson("/api/public/receipts/{$token}")->assertNotFound();

        $this->patchJson($this->url('/revoke'))->assertStatus(409);
    }

    public function test_making_and_revoking_are_audited_on_the_payment_without_the_token(): void
    {
        $token = $this->postJson($this->url())->json('data.token');
        $link = ReceiptShareLink::query()->sole();
        $this->patchJson($this->url('/revoke'))->assertOk();

        $rows = AuditLog::query()->where('auditable_type', Repayment::class)->where('auditable_id', $this->repayment->id)
            ->whereIn('action', ['receipt_share_link_created', 'receipt_share_link_revoked'])->orderBy('id')->get();

        $this->assertSame(['receipt_share_link_created', 'receipt_share_link_revoked'], $rows->pluck('action')->all());
        $this->assertSame([(int) $this->admin->id, (int) $this->admin->id], $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame($link->id, $rows[0]->new_values['share_link_id']);
        $this->assertSame('revoked', $rows[1]->new_values['status']);
        $this->assertSame((int) $this->loan->branch_id, (int) $rows[0]->branch_id);
        $this->assertStringContainsString($this->repayment->receipt_number, (string) $rows[0]->description);

        foreach ($rows as $row) {
            $this->assertStringNotContainsString($token, json_encode($row->getAttributes()));
            $this->assertStringNotContainsString($link->token_hash, json_encode($row->getAttributes()));
        }
    }

    public function test_every_route_needs_payments_view(): void
    {
        $without = $this->staffWith(['loans:view', 'borrowers:view'], $this->branch);
        $this->actingAs($without);

        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url())->assertForbidden();
        $this->patchJson($this->url('/revoke'))->assertForbidden();
        $this->assertSame(0, ReceiptShareLink::query()->count());

        $this->actingAs($this->staffWith(['payments:view'], $this->branch));

        $this->postJson($this->url())->assertCreated();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.link.status', 'active');
        $this->patchJson($this->url('/revoke'))->assertOk();
    }

    public function test_another_branchs_receipt_answers_404(): void
    {
        $elsewhere = Branch::factory()->create();
        $this->actingAs($this->staffWith(['payments:view', 'loans:view'], $elsewhere));

        $this->getJson($this->url())->assertNotFound();
        $this->postJson($this->url())->assertNotFound();
        $this->patchJson($this->url('/revoke'))->assertNotFound();
        $this->assertSame(0, ReceiptShareLink::query()->count());
    }

    public function test_every_route_answers_the_same_404_while_the_switch_is_off(): void
    {
        $this->postJson($this->url())->assertCreated();
        config(['receipt_share_links.enabled' => false]);

        foreach ([['GET', ''], ['POST', ''], ['PATCH', '/revoke']] as [$method, $suffix]) {
            $this->json($method, $this->url($suffix))->assertNotFound()
                ->assertExactJson(['message' => ReceiptShareLinks::NOT_FOUND]);
        }

        $this->assertSame(1, ReceiptShareLink::query()->count());
        $this->assertSame(1, ReceiptShareLink::query()->active()->count());
    }

    public function test_the_switch_is_off_by_default(): void
    {
        $this->assertFalse((require base_path('config/receipt_share_links.php'))['enabled']);
        $this->assertStringContainsString("RECEIPT_SHARE_LINKS_ENABLED=false\n", file_get_contents(base_path('.env.example')));
    }

    public function test_a_guest_gets_401_on_the_staff_routes(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson($this->url())->assertUnauthorized();
        $this->postJson($this->url())->assertUnauthorized();
        $this->patchJson($this->url('/revoke'))->assertUnauthorized();
    }

    /** @param  list<string>  $permissions */
    private function staffWith(array $permissions, Branch $branch): User
    {
        $user = User::factory()->inBranches($branch)->create();
        $role = Role::firstOrCreate(['name' => 'share_links_'.md5(implode(',', $permissions)), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $user;
    }
}

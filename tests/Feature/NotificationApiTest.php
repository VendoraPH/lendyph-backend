<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\StaffNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET/POST /api/notifications: the signed-in user's own notifications only,
 * in the shapes the header bell reads (lead's contract, 2026-11-03):
 * a raw paginator `{data, links, meta}` of `{id, type, title, body, url,
 * read_at, created_at, data}`, `{data: {count}}` for the unread count, and a
 * 404 for anyone else's id.
 */
class NotificationApiTest extends TestCase
{
    use SetupLendyPH;

    private User $me;

    private User $someoneElse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->me = User::factory()->create();
        $this->me->assignRole('loan_officer');
        $this->someoneElse = User::factory()->create();
        $this->someoneElse->assignRole('loan_officer');
    }

    public function test_the_list_is_a_raw_paginator_of_the_callers_own_notifications_newest_first(): void
    {
        $old = $this->notify($this->me, 'Loan approved', now()->subHour());
        $new = $this->notify($this->me, 'Loan released', now());
        $this->notify($this->someoneElse, 'Not mine', now());

        $response = $this->actingAs($this->me)->getJson('/api/notifications')->assertOk();

        $response->assertJsonStructure([
            'data' => [['id', 'type', 'title', 'body', 'url', 'read_at', 'created_at', 'data']],
            'links',
            'meta' => ['current_page', 'per_page', 'total'],
        ]);
        $response->assertJsonMissingPath('success');
        $this->assertSame([$new->id, $old->id], $response->json('data.*.id'));
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(20, $response->json('meta.per_page'));

        $first = $response->json('data.0');
        $this->assertTrue(Str::isUuid($first['id']));
        $this->assertSame('loan_released', $first['type']);
        $this->assertSame('Loan released', $first['title']);
        $this->assertSame('LN-000001 for Juan Dela Cruz: ₱1,500.00 released.', $first['body']);
        $this->assertSame('/loans/7', $first['url']);
        $this->assertNull($first['read_at']);
        $this->assertNotNull($first['created_at']);
        $this->assertSame(['loan_id' => 7], $first['data']);
    }

    public function test_the_unread_filter_and_per_page(): void
    {
        $read = $this->notify($this->me, 'Read one', now()->subMinutes(3));
        $read->markAsRead();
        $this->notify($this->me, 'Unread one', now()->subMinutes(2));
        $this->notify($this->me, 'Unread two', now()->subMinute());

        $unread = $this->actingAs($this->me)->getJson('/api/notifications?unread=1')->assertOk();
        $this->assertSame(['Unread two', 'Unread one'], $unread->json('data.*.title'));

        $paged = $this->getJson('/api/notifications?per_page=1')->assertOk();
        $this->assertCount(1, $paged->json('data'));
        $this->assertSame(3, $paged->json('meta.total'));

        $this->getJson('/api/notifications?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/notifications?per_page=0')->assertUnprocessable();
        $this->getJson('/api/notifications?unread=maybe')->assertUnprocessable();
    }

    public function test_the_unread_count_counts_only_the_callers_unread(): void
    {
        $this->notify($this->me, 'One', now());
        $this->notify($this->me, 'Two', now())->markAsRead();
        $this->notify($this->someoneElse, 'Theirs', now());

        $this->actingAs($this->me)->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertExactJson(['data' => ['count' => 1]]);
    }

    public function test_marking_one_read_and_another_users_id_is_a_404(): void
    {
        $mine = $this->notify($this->me, 'Mine', now());
        $theirs = $this->notify($this->someoneElse, 'Theirs', now());

        $this->actingAs($this->me)->postJson("/api/notifications/{$mine->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $mine->id)
            ->assertJsonPath('data.title', 'Mine');
        $readAt = $mine->fresh()->read_at;
        $this->assertNotNull($readAt);

        // Idempotent: a second mark keeps the first read time.
        $this->travel(5)->minutes();
        $this->postJson("/api/notifications/{$mine->id}/read")->assertOk();
        $this->assertEquals($readAt, $mine->fresh()->read_at);

        $this->postJson("/api/notifications/{$theirs->id}/read")->assertNotFound();
        $this->assertNull($theirs->fresh()->read_at);
        $this->postJson('/api/notifications/'.Str::uuid().'/read')->assertNotFound();
        $this->postJson('/api/notifications/not-a-uuid/read')->assertNotFound();
    }

    public function test_mark_all_read_marks_only_the_callers_own(): void
    {
        $this->notify($this->me, 'One', now());
        $this->notify($this->me, 'Two', now());
        $theirs = $this->notify($this->someoneElse, 'Theirs', now());

        $this->actingAs($this->me)->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_read', 2);

        $this->getJson('/api/notifications/unread-count')->assertExactJson(['data' => ['count' => 0]]);
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_every_endpoint_needs_a_signed_in_user(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->getJson('/api/notifications/unread-count')->assertUnauthorized();
        $this->postJson('/api/notifications/read-all')->assertUnauthorized();
        $this->postJson('/api/notifications/'.Str::uuid().'/read')->assertUnauthorized();
    }

    private function notify(User $user, string $title, \DateTimeInterface $at): DatabaseNotification
    {
        $this->travelTo($at);
        $user->notify(new StaffNotification(
            StaffNotification::LOAN_RELEASED,
            $title,
            'LN-000001 for Juan Dela Cruz: ₱1,500.00 released.',
            '/loans/7',
            ['loan_id' => 7],
        ));
        $this->travelBack();

        return $user->notifications()->get()->firstOrFail(fn (DatabaseNotification $n) => $n->data['title'] === $title);
    }
}

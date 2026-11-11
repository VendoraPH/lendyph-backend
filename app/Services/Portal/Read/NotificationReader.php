<?php

namespace App\Services\Portal\Read;

use App\Models\PortalUser;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The member's in-app notices (portal design §1, §7), database channel only.
 * A notice's link is sent only when it is a `/member/...` path, so a stored
 * link can never send the member off the portal.
 */
final class NotificationReader
{
    public function __construct(private readonly AnnouncementAudience $announcements) {}

    /** @return array{id: string, category: string|null, title: string|null, body: string|null, created_at: mixed, read_at: mixed, link: string|null} */
    public function present(DatabaseNotification $notice): array
    {
        $data = is_array($notice->data) ? $notice->data : [];

        return [
            'id' => (string) $notice->getKey(),
            'category' => isset($data['category']) ? (string) $data['category'] : null,
            'title' => isset($data['title']) ? (string) $data['title'] : null,
            'body' => isset($data['body']) ? (string) $data['body'] : null,
            'created_at' => $notice->created_at,
            'read_at' => $notice->read_at,
            'link' => self::safeLink($data['link'] ?? null),
        ];
    }

    /** @return array{notifications: int, announcements: int, total: int} */
    public function unreadCount(PortalUser $portalUser): array
    {
        $notices = $portalUser->unreadNotifications()->count();
        $announcements = $this->announcements->unreadBy($portalUser)->count();

        return ['notifications' => $notices, 'announcements' => $announcements, 'total' => $notices + $announcements];
    }

    public static function safeLink(mixed $link): ?string
    {
        return is_string($link) && preg_match('#^/member(/[A-Za-z0-9/_\-?=&.]*)?$#', $link) === 1 && ! str_contains($link, '..')
            ? $link
            : null;
    }
}

<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * An in-app notice to a member (portal design §7), database channel only.
 * Bodies carry no internal ids; `link` is a `/member/...` path or null.
 */
class PortalNotice extends Notification
{
    public function __construct(
        public readonly string $category,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $link = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'portal_'.$this->category;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
        ];
    }
}

<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class SocialActivityNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, scalar>  $titleParams
     */
    public function __construct(
        private readonly string $eventKey,
        private readonly string $titleVi,
        private readonly string $titleEn,
        private readonly string $url,
        private readonly ?string $actorName = null,
        private readonly ?string $actorUsername = null,
        private readonly array $meta = [],
        private readonly ?string $titleKey = null,
        private readonly array $titleParams = [],
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->eventKey,
            'title_key' => $this->titleKey,
            'title_params' => $this->titleParams,
            'title_vi' => $this->titleVi,
            'title_en' => $this->titleEn,
            'url' => $this->url,
            'actor_name' => $this->actorName,
            'actor_username' => $this->actorUsername,
            'meta' => $this->meta,
        ];
    }
}

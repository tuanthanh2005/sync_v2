<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SecurityAlertNotification extends Notification
{
    /**
     * @param  array<string, scalar>  $params
     */
    public function __construct(
        private readonly string $alertKey,
        private readonly string $titleKey,
        private readonly string $bodyKey,
        private readonly array $params = [],
        private readonly bool $sendEmail = true,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($this->sendEmail) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = (string) config('app.name', 'SyncViet');
        $securityUrl = rtrim((string) config('app.url', 'https://syncviet.com'), '/').'/settings/security';

        return (new MailMessage)
            ->subject("[{$appName}] ".__($this->titleKey, $this->params))
            ->line(__($this->bodyKey, $this->params))
            ->action(__('Review account security'), $securityUrl)
            ->line(__('If you did not perform this action, please change your password immediately and review your active sessions.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => "security.{$this->alertKey}",
            'title_key' => $this->titleKey,
            'title_params' => $this->params,
            'title_en' => __($this->titleKey, $this->params, 'en'),
            'title_vi' => __($this->titleKey, $this->params, 'vi'),
            'url' => '/settings/security',
            'actor_name' => (string) config('app.name', 'SyncViet'),
            'actor_username' => 'security',
        ];
    }
}

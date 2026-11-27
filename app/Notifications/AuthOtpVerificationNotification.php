<?php

namespace App\Notifications;

use App\Models\AuthVerificationChallenge;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AuthOtpVerificationNotification extends Notification
{
    public function __construct(
        private readonly string $otpCode,
        private readonly string $confirmationUrl,
        private readonly int $expireMinutes,
        private readonly string $purpose = AuthVerificationChallenge::PURPOSE_REGISTRATION,
        private readonly string $recipientName = '',
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = (string) config('app.name', 'SyncViet');

        $subject = match ($this->purpose) {
            AuthVerificationChallenge::PURPOSE_CHANGE_EMAIL => __(':app — Confirm your new email address', ['app' => $appName]),
            default => __(':app — Verify your email address', ['app' => $appName]),
        };

        $intro = match ($this->purpose) {
            AuthVerificationChallenge::PURPOSE_CHANGE_EMAIL => __('Enter the 6-digit verification code below to confirm your new email address:'),
            default => __('Enter the 6-digit verification code below to verify your email address and activate your account:'),
        };

        $greeting = $this->recipientName !== ''
            ? __('Hello :name,', ['name' => $this->recipientName])
            : __('Hello,');

        return (new MailMessage)
            ->subject($subject)
            ->greeting($greeting)
            ->line($intro)
            ->line("**{$this->otpCode}**")
            ->line(__('This verification code expires in :minutes minutes and can only be used once.', ['minutes' => $this->expireMinutes]))
            ->action(__('Verify Email Address'), $this->confirmationUrl)
            ->line(__('If you did not request this verification, you can safely ignore this email.'));
    }
}

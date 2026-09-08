<?php

namespace App\Notifications;

use App\Services\EmailOtpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailLoginCode extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 3;

    /** @var array<int, int> */
    public $backoff = [5, 30, 120];

    /** @var int */
    public $timeout = 30;

    /**
     * Create a new notification instance.
     */
    public function __construct(public readonly string $code)
    {
        $this->onQueue('mail');
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your ADVS sign-in code')
            ->greeting('Sign-in verification')
            ->line("Your one-time sign-in code is: {$this->code}")
            ->line('This code expires in '.EmailOtpService::EXPIRES_IN_MINUTES.' minutes and can be used only once.')
            ->line('If you did not try to sign in, change your password and contact an administrator.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}

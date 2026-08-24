<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendEmailVerificationNotification implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $tries = 3;

    /** @var array<int, int> */
    public $backoff = [5, 30, 120];

    /** @var int */
    public $timeout = 30;

    public function __construct(public User $user)
    {
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        $this->user->notifyNow(new VerifyEmail);
    }
}

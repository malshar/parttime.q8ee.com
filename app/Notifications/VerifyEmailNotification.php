<?php

namespace App\Notifications;

use App\Mail\VerifyEmailMail;
use App\Notifications\Channels\MailableChannel;
use Illuminate\Auth\Notifications\VerifyEmail;

class VerifyEmailNotification extends VerifyEmail
{
    public function via($notifiable): array
    {
        return [MailableChannel::class];
    }

    public function toMail($notifiable): VerifyEmailMail
    {
        return (new VerifyEmailMail($this->verificationUrl($notifiable)))->to($notifiable->email);
    }
}

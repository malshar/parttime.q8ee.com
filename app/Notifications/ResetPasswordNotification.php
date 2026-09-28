<?php

namespace App\Notifications;

use App\Mail\ResetPasswordMail;
use App\Notifications\Channels\MailableChannel;
use Illuminate\Auth\Notifications\ResetPassword;

class ResetPasswordNotification extends ResetPassword
{
    public function via($notifiable): array
    {
        return [MailableChannel::class];
    }

    public function toMail($notifiable): ResetPasswordMail
    {
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);

        return (new ResetPasswordMail($url))->to($notifiable->email);
    }
}

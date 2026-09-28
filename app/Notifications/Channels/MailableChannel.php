<?php

namespace App\Notifications\Channels;

use Illuminate\Contracts\Mail\Mailable as MailableContract;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers a notification whose toMail() returns a plain Mailable.
 *
 * Laravel's built-in "mail" channel unwraps a Mailable into raw view
 * content before handing it to the mailer, so Mail::fake()'s
 * assertSent(SomeMailable::class) never sees it (MailFake only records
 * calls that pass the Mailable object itself, e.g. Mail::send($mailable)).
 * This channel sends the Mailable via Mail::send() so it stays visible
 * to Mail::fake() in tests while behaving identically in production.
 */
class MailableChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        $mailable = $notification->toMail($notifiable);

        if ($mailable instanceof MailableContract) {
            Mail::send($mailable);
        }
    }
}

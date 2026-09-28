<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ResetPasswordMail extends Mailable
{
    public function __construct(public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('app.mail.reset_subject', [], 'ar'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.reset-password');
    }
}

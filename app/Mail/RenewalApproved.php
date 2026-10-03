<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\CommitteeApproval;
use App\Models\Instructor;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RenewalApproved extends Mailable
{
    public function __construct(public Instructor $instructor, public CommitteeApproval $approval, public Application $application) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('app.mail.renewal_approved_subject', [], 'ar'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.renewal-approved', with: [
            'name' => $this->instructor->full_name,
            'year' => $this->approval->academic_year,
            'url' => route('instructor.applications.show', $this->application),
            'items' => ChecklistItem::where('renews_each_term', true)->orderBy('sort_order')->pluck('label_ar')->all(),
        ]);
    }
}

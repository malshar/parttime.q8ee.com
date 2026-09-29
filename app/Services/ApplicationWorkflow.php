<?php

namespace App\Services;

use App\Exceptions\TermClosedException;
use App\Mail\ApplicationApproved;
use App\Mail\ApplicationRejected;
use App\Mail\ApplicationSubmitted;
use App\Mail\DocumentsRejected;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\ChecklistItem;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ApplicationWorkflow
{
    public function __construct(private ChecklistResolver $resolver) {}

    public function start(Instructor $instructor, Term $term): Application
    {
        if (! $term->isOpen()) {
            throw new TermClosedException;
        }

        return Application::firstOrCreate(
            ['term_id' => $term->id, 'instructor_id' => $instructor->id],
            ['status' => Application::STATUS_DRAFT],
        );
    }

    /**
     * @return array<string, array{item: ChecklistItem, document: ?Document, state: string}>
     */
    public function checklist(Application $application): array
    {
        $plan = $this->resolver->for($application->instructor);
        $docs = $application->latestDocuments();
        $out = [];
        foreach ($plan->required as $item) {
            $doc = $docs->get($item->code);
            $out[$item->code] = ['item' => $item, 'document' => $doc, 'state' => $doc?->status ?? 'missing'];
        }

        return $out;
    }

    public function plan(Application $application): ChecklistPlan
    {
        return $this->resolver->for($application->instructor);
    }

    public function allRequiredAccepted(Application $application): bool
    {
        foreach ($this->checklist($application) as $row) {
            if ($row['state'] !== 'accepted') {
                return false;
            }
        }

        return true;
    }

    public function allRequiredUploaded(Application $application): bool
    {
        foreach ($this->checklist($application) as $row) {
            if ($row['state'] === 'missing' || $row['state'] === 'rejected') {
                return false;
            }
        }

        return true;
    }

    public function submit(Application $application): void
    {
        if (! $application->isEditable()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if (! $this->allRequiredUploaded($application)) {
            throw new \DomainException(__('app.applications.submit_blocked'));
        }
        $application->update(['status' => Application::STATUS_SUBMITTED, 'submitted_at' => now()]);
        $this->notifyAdmin($application);
    }

    public function afterUpload(Application $application): void
    {
        if ($application->status === Application::STATUS_INCOMPLETE && $this->allRequiredUploaded($application)) {
            $application->update(['status' => Application::STATUS_SUBMITTED, 'submitted_at' => now()]);
            $this->notifyAdmin($application);
        }
    }

    public function withdraw(Application $application): void
    {
        if ($application->isFinal()) {
            throw new \DomainException('final');
        }
        $application->update(['status' => Application::STATUS_WITHDRAWN, 'decided_at' => now()]);
    }

    public function markUnderReview(Application $application): void
    {
        if ($application->status === Application::STATUS_SUBMITTED) {
            $application->update(['status' => Application::STATUS_UNDER_REVIEW, 'reviewed_at' => now()]);
        }
    }

    public function reviewDocument(Document $document, User $admin, string $status, ?string $reason): void
    {
        if ($document->application->isFinal()) {
            throw new \DomainException(__('app.review.already_final'));
        }
        if (! $document->application->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if (! $document->application->latestDocuments()->get($document->checklistItem->code)?->is($document)) {
            throw new \DomainException(__('app.review.superseded_version'));
        }

        $document->update([
            'status' => $status,
            'rejection_reason' => $status === Document::STATUS_REJECTED ? $reason : null,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);
        AuditLog::record($admin->id, 'review_document_'.$status, $document);

        $application = $document->application->fresh();
        if ($status === Document::STATUS_REJECTED && ! $application->isFinal()) {
            $application->update(['status' => Application::STATUS_INCOMPLETE, 'complete_at' => null]);
        }
    }

    /** @return array<int, array{item: ChecklistItem, document: ?Document, state: string}> */
    public function pendingRejectionNotices(Application $application): array
    {
        return array_values(array_filter(
            $this->checklist($application),
            fn ($row) => $row['state'] === 'rejected' && $row['document']?->notified_at === null,
        ));
    }

    public function notifyRejections(Application $application, User $admin): int
    {
        if (! $application->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if ($this->pendingRejectionNotices($application) === []) {
            throw new \DomainException(__('app.review.nothing_to_notify'));
        }
        $rejected = array_values(array_filter($this->checklist($application), fn ($row) => $row['state'] === 'rejected'));
        foreach ($rejected as $row) {
            $row['document']->update(['notified_at' => now()]);
        }
        AuditLog::record($admin->id, 'notify_rejections', $application);
        $this->safeSend($application->instructor->user->email, new DocumentsRejected($application, $rejected));

        return count($rejected);
    }

    public function markComplete(Application $application, User $admin): void
    {
        if (! in_array($application->status, [Application::STATUS_UNDER_REVIEW, Application::STATUS_INCOMPLETE], true)) {
            throw new \DomainException(__('app.review.complete_wrong_status'));
        }
        if (! $application->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if (! $this->allRequiredAccepted($application)) {
            throw new \DomainException(__('app.review.complete_blocked'));
        }
        $application->update(['status' => Application::STATUS_COMPLETE, 'complete_at' => now()]);
        AuditLog::record($admin->id, 'mark_complete', $application);
    }

    public function committeeDecision(Application $application, User $admin, string $outcome, string $metOn, string $reference, ?string $note): void
    {
        if ($application->isFinal()) {
            throw new \DomainException(__('app.review.already_final'));
        }
        if ($application->status !== Application::STATUS_COMPLETE) {
            throw new \DomainException(__('app.review.committee_wrong_status'));
        }
        if (! $application->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }

        $approved = $outcome === 'approved';
        $application->update([
            'status' => $approved ? Application::STATUS_APPROVED : Application::STATUS_REJECTED,
            'decided_at' => now(),
            'committee_outcome' => $outcome,
            'committee_met_on' => $metOn,
            'committee_reference' => $reference,
            'committee_note' => $note,
            'rejection_reason' => $approved ? null : $note,
        ]);
        AuditLog::record($admin->id, 'committee_decision', $application);
        $this->safeSend(
            $application->instructor->user->email,
            $approved ? new ApplicationApproved($application) : new ApplicationRejected($application),
        );
    }

    private function notifyAdmin(Application $application): void
    {
        if ($to = config('mail.admin_notify')) {
            $this->safeSend($to, new ApplicationSubmitted($application));

            return;
        }

        Log::warning('ADMIN_NOTIFY_EMAIL is not set; admin was not notified of application submission', ['application_id' => $application->id]);
    }

    /**
     * Mail is sent synchronously after the state change has been saved; a mail
     * failure (SMTP down, bad credentials) must not turn a completed action into a 500.
     */
    private function safeSend(string $to, Mailable $mail): void
    {
        try {
            Mail::to($to)->send($mail);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

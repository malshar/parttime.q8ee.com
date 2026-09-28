<?php

namespace App\Services;

use App\Exceptions\TermClosedException;
use App\Mail\ApplicationSubmitted;
use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;
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

    private function notifyAdmin(Application $application): void
    {
        if ($to = config('mail.admin_notify')) {
            Mail::to($to)->send(new ApplicationSubmitted($application));
        }
    }
}

<?php

namespace App\Services;

use App\Exceptions\TermClosedException;
use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\Document;
use App\Models\Instructor;
use App\Models\Term;

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

    public function afterUpload(Application $application): void {}
}

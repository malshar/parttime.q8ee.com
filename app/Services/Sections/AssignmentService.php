<?php

namespace App\Services\Sections;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Support\ArabicNameNormaliser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    public function assign(Section $section, Application $application, User $admin): Assignment
    {
        if ($application->status !== Application::STATUS_APPROVED) {
            throw new \DomainException(__('app.assignments.not_approved'));
        }
        if ($application->term_id !== $section->term_id) {
            throw new \DomainException(__('app.assignments.other_term'));
        }
        if (! $section->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        if ($section->assignment()->exists()) {
            throw new \DomainException(__('app.assignments.already_assigned'));
        }

        return DB::transaction(function () use ($section, $application, $admin) {
            try {
                $a = Assignment::create(['application_id' => $application->id, 'section_id' => $section->id, 'created_by' => $admin->id]);
            } catch (UniqueConstraintViolationException) {
                throw new \DomainException(__('app.assignments.already_assigned'));
            }
            $this->recomputeHours($application);
            AuditLog::record($admin->id, 'assign_section', $section);

            return $a;
        });
    }

    public function unassign(Section $section, User $admin): void
    {
        if (! $section->term->isOpen()) {
            throw new \DomainException(__('app.applications.term_closed'));
        }
        $assignment = $section->assignment;
        if (! $assignment) {
            throw new \DomainException(__('app.assignments.not_assigned'));
        }
        DB::transaction(function () use ($assignment, $section, $admin) {
            $application = $assignment->application;
            $assignment->delete();
            $this->recomputeHours($application);
            AuditLog::record($admin->id, 'unassign_section', $section);
        });
    }

    public function recomputeHours(Application $application): void
    {
        $minutes = 0;
        foreach ($application->sections()->with('meetings')->get() as $s) {
            $minutes += $s->weeklyMinutes();
        }
        $application->update(['weekly_minutes' => $minutes, 'weekly_hours_decimal' => round($minutes / 60, 1)]);
    }

    /** @return array<int, int> section_id => application_id (unique name matches on unassigned sections only) */
    public function suggestionsFor(Term $term): array
    {
        $byName = [];
        foreach ($term->applications()->where('status', Application::STATUS_APPROVED)->with('instructor')->get() as $app) {
            $byName[ArabicNameNormaliser::normalise($app->instructor->full_name)][] = $app->id;
        }
        $out = [];
        foreach ($term->sections()->doesntHave('assignment')->whereNotNull('scheduled_instructor')->get() as $section) {
            $ids = $byName[ArabicNameNormaliser::normalise($section->scheduled_instructor)] ?? [];
            if (count($ids) === 1) {
                $out[$section->id] = $ids[0];
            }
        }

        return $out;
    }
}

<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\Document;
use App\Models\User;
use App\Services\ChecklistResolver;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $user->isAdmin() || $this->owns($user, $document->application);
    }

    /** Called as: $user->can('create', [Document::class, $application, $item]) */
    public function create(User $user, Application $application, ChecklistItem $item): bool
    {
        if (! $this->owns($user, $application)) {
            return false;
        }
        $plan = app(ChecklistResolver::class)->for($application->instructor);
        if (! $plan->isUploadable($item->code)) {
            return false;
        }
        if ($application->isEditable()) {
            return true;
        }

        // Spec 5b §5: after approval only stage-2 (and optional) items may still be uploaded.
        return $application->acceptsStageTwoUploads() && ($item->isStageTwo() || $item->optional);
    }

    public function review(User $user, Document $document): bool
    {
        return $user->isAdmin();
    }

    private function owns(User $user, Application $application): bool
    {
        return $user->instructor && $user->instructor->id === $application->instructor_id;
    }
}

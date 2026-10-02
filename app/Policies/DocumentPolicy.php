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
        return $this->owns($user, $application)
            && $application->isEditable()
            && app(ChecklistResolver::class)->for($application->instructor)->isUploadable($item->code);
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

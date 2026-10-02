<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\User;
use App\Services\ChecklistResolver;

class ApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Application $application): bool
    {
        return $user->isAdmin() || $this->owns($user, $application);
    }

    public function update(User $user, Application $application): bool
    {
        return $this->owns($user, $application) && $application->isEditable();
    }

    public function review(User $user, Application $application): bool
    {
        return $user->isAdmin();
    }

    /** Called as: $user->can('requestExemption', [$application, $item]) */
    public function requestExemption(User $user, Application $application, ChecklistItem $item): bool
    {
        return $this->owns($user, $application) && $application->isEditable()
            && $item->exemptable && $item->stage === ChecklistItem::STAGE_COMMITTEE
            && app(ChecklistResolver::class)->for($application->instructor)->isRequired($item->code);
    }

    private function owns(User $user, Application $application): bool
    {
        return $user->instructor && $user->instructor->id === $application->instructor_id;
    }
}

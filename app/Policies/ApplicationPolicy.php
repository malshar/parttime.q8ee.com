<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;

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

    private function owns(User $user, Application $application): bool
    {
        return $user->instructor && $user->instructor->id === $application->instructor_id;
    }
}

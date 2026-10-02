<?php

namespace App\Http\Requests;

use App\Models\Instructor;

class AdminProfileRequest extends ProfileRequest
{
    protected function ownerUserId(): ?int
    {
        return $this->route('application')?->instructor?->user_id;
    }

    protected function editedInstructor(): ?Instructor
    {
        return $this->route('application')?->instructor;
    }
}

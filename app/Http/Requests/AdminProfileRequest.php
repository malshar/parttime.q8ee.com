<?php

namespace App\Http\Requests;

class AdminProfileRequest extends ProfileRequest
{
    protected function ownerUserId(): ?int
    {
        return $this->route('application')?->instructor?->user_id;
    }
}

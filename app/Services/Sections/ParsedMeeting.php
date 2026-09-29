<?php

namespace App\Services\Sections;

final class ParsedMeeting
{
    public function __construct(
        public readonly int $dayOfWeek,
        public readonly string $type,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly int $minutes,
        public readonly string $activityAr,
        public readonly ?string $building = null,
        public readonly ?string $room = null,
    ) {}

    public function key(): string
    {
        return $this->dayOfWeek.'|'.$this->startsAt.'|'.$this->type;
    }
}

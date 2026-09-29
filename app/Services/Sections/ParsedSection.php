<?php

namespace App\Services\Sections;

final class ParsedSection
{
    /** @param ParsedMeeting[] $meetings */
    public function __construct(
        public readonly string $courseCode,
        public readonly string $courseName,
        public readonly string $sectionNumber,
        public ?string $referenceNumber = null,
        public ?string $scheduledInstructor = null,
        public ?int $seatsCapacity = null,
        public ?int $seatsRegistered = null,
        public ?int $seatsRemaining = null,
        public array $meetings = [],
    ) {}

    public function key(): string
    {
        return $this->courseCode.'|'.$this->sectionNumber;
    }

    /** @return array{theory:int, practical:int, field:int} */
    public function minutesByType(): array
    {
        $out = ['theory' => 0, 'practical' => 0, 'field' => 0];
        foreach ($this->meetings as $m) {
            $out[$m->type] += $m->minutes;
        }

        return $out;
    }
}

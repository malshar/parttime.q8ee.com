<?php

namespace App\Services\Sections;

final class ParsedTimetable
{
    /** @param array<string, ParsedSection> $sections */
    public function __construct(
        public array $sections = [],
        public array $warnings = [],
        public array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}

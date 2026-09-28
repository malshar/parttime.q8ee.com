<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistItem extends Model
{
    public const CODES = ['schedule', 'assignment_letter', 'attestation', 'civil_id', 'degree', 'equivalency',
        'social_insurance', 'experience', 'salary_cert', 'iban', 'employer_approval', 'undertaking'];

    protected $fillable = ['code', 'label_ar', 'note_ar', 'sort_order', 'provided_by', 'condition', 'renews_each_term'];

    protected function casts(): array
    {
        return ['renews_each_term' => 'boolean'];
    }

    public function isDepartment(): bool
    {
        return $this->provided_by === 'department';
    }

    public function appliesTo(Instructor $i): bool
    {
        return match ($this->condition) {
            'always' => true,
            'foreign_degree' => $i->isForeignDegree(),
            'private_sector' => $i->isPrivateSector(),
            'bachelor_only' => $i->isBachelorOnly(),
            default => false,
        };
    }
}

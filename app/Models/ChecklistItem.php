<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistItem extends Model
{
    public const CODES = ['schedule', 'assignment_letter', 'attestation', 'civil_id', 'degree', 'equivalency',
        'social_insurance', 'experience', 'salary_cert', 'iban', 'employer_approval', 'undertaking'];

    /**
     * Profile fields each item certifies (spec §4.2 rule 4b): an earlier copy stops being on file once
     * any of these changed after it was accepted. Items not listed have no profile dependency.
     */
    public const PROFILE_FIELDS = [
        'civil_id' => ['civil_id', 'civil_id_expires_on'],
        'degree' => ['highest_degree', 'degree_title', 'degree_country', 'degree_obtained_on'],
        'equivalency' => ['degree_country', 'degree_title', 'degree_obtained_on'],
        'experience' => ['experience_years', 'highest_degree'],
        'social_insurance' => ['employer', 'employer_sector'],
        'iban' => ['iban', 'bank_name', 'bank_branch'],
    ];

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

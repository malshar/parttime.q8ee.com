<?php

namespace Database\Factories;

use App\Models\Instructor;
use Illuminate\Database\Eloquent\Factories\Factory;

class InstructorFactory extends Factory
{
    protected $model = Instructor::class;

    public function definition(): array
    {
        $serial = str_pad((string) $this->faker->unique()->numberBetween(0, 9999), 4, '0', STR_PAD_LEFT);
        $first11 = '2900101'.$serial;
        $w = [2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 3];
        $sum = 0;
        foreach (str_split($first11) as $i => $d) {
            $sum += (int) $d * $w[$i];
        }
        $check = 11 - ($sum % 11);
        if ($check >= 10) {                       // skip serials that yield an invalid check digit
            $first11 = '2900101'.str_pad((string) (((int) $serial + 1) % 10000), 4, '0', STR_PAD_LEFT);
            $sum = 0;
            foreach (str_split($first11) as $i => $d) {
                $sum += (int) $d * $w[$i];
            }
            $check = 11 - ($sum % 11);
        }

        return [
            'full_name' => $this->faker->name(),
            'civil_id' => $first11.$check,
            'civil_id_expires_on' => now()->addYears(2)->toDateString(),
            'nationality' => 'كويتي', 'mobile' => '99'.$this->faker->numerify('######'),
            'employer' => 'وزارة الكهرباء والماء', 'employer_sector' => 'government',
            'job_title' => 'مهندس', 'highest_degree' => 'master', 'degree_title' => 'ماجستير هندسة كهربائية',
            'degree_country' => 'KW', 'degree_obtained_on' => '2018-06-01',
            'bank_name' => 'بنك الكويت الوطني', 'bank_branch' => 'الرميثية',
            'iban' => 'KW81CBKU0000000000001234560101', 'basic_salary' => '1200', 'total_salary' => '1650',
        ];
    }

    public function foreignDegree(): static
    {
        return $this->state(fn () => ['degree_country' => 'GB']);
    }

    public function privateSector(): static
    {
        return $this->state(fn () => ['employer_sector' => 'private', 'employer' => 'شركة خاصة']);
    }

    public function bachelor(int $years = 12): static
    {
        return $this->state(fn () => ['highest_degree' => 'bachelor', 'experience_years' => $years]);
    }
}

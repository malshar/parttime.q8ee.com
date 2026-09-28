<?php

namespace Tests\Feature\Instructor;

use App\Models\Instructor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Unit\CivilIdRuleTest;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public static function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'محمد أحمد علي الفهد',
            'civil_id' => CivilIdRuleTest::withCheckDigit('29001011234'),
            'civil_id_expires_on' => '2028-01-01',
            'nationality' => 'كويتي',
            'mobile' => '99001122',
            'employer' => 'وزارة الكهرباء والماء',
            'employer_sector' => 'government',
            'job_title' => 'مهندس كهربائي',
            'highest_degree' => 'master',
            'degree_title' => 'ماجستير هندسة كهربائية',
            'degree_country' => 'KW',
            'degree_obtained_on' => '2018-06-01',
            'bank_name' => 'بنك الكويت الوطني',
            'bank_branch' => 'الرميثية',
            'iban' => 'KW81CBKU0000000000001234560101',
            'basic_salary' => '1200',
            'total_salary' => '1650',
        ], $overrides);
    }

    public function test_instructor_creates_profile_with_encrypted_and_hashed_fields(): void
    {
        $user = User::factory()->instructor()->create();

        $this->actingAs($user)->put(route('instructor.profile.update'), self::payload())
            ->assertRedirect(route('instructor.home'));

        $instructor = $user->fresh()->instructor;
        $this->assertSame(self::payload()['civil_id'], $instructor->civil_id);
        $this->assertSame('KW81CBKU0000000000001234560101', $instructor->iban);
        $raw = DB::table('instructors')->where('id', $instructor->id)->first();
        $this->assertNotSame(self::payload()['civil_id'], $raw->civil_id);
        $this->assertNotSame('KW81CBKU0000000000001234560101', $raw->iban);
        $this->assertSame(Instructor::hashCivilId(self::payload()['civil_id']), $raw->civil_id_hash);
        $this->assertSame('290*****'.substr(self::payload()['civil_id'], -3), $instructor->maskedCivilId());
    }

    public function test_civil_id_must_be_unique_across_instructors(): void
    {
        Instructor::factory()->for(User::factory()->instructor())->create(['civil_id' => self::payload()['civil_id']]);
        $user = User::factory()->instructor()->create();

        $this->actingAs($user)->from(route('instructor.profile.edit'))
            ->put(route('instructor.profile.update'), self::payload())
            ->assertSessionHasErrors('civil_id');
    }

    public function test_experience_years_required_for_bachelor(): void
    {
        $user = User::factory()->instructor()->create();

        $this->actingAs($user)->put(route('instructor.profile.update'), self::payload(['highest_degree' => 'bachelor']))
            ->assertSessionHasErrors('experience_years');

        $this->actingAs($user)->put(route('instructor.profile.update'), self::payload(['highest_degree' => 'bachelor', 'experience_years' => 12]))
            ->assertSessionHasNoErrors();
    }

    public function test_unverified_user_cannot_edit_profile(): void
    {
        $user = User::factory()->instructor()->unverified()->create();

        $this->actingAs($user)->get(route('instructor.profile.edit'))->assertRedirect(route('verification.notice'));
    }

    public function test_sensitive_fields_are_not_flashed_on_validation_failure(): void
    {
        $user = User::factory()->instructor()->create();

        $this->actingAs($user)
            ->put(route('instructor.profile.update'), self::payload(['mobile' => 'not-a-number']))
            ->assertSessionHasErrors('mobile');

        $oldInput = session('_old_input', []);
        $this->assertArrayHasKey('mobile', $oldInput);
        $this->assertArrayNotHasKey('civil_id', $oldInput);
        $this->assertArrayNotHasKey('iban', $oldInput);
        $this->assertArrayNotHasKey('basic_salary', $oldInput);
        $this->assertArrayNotHasKey('total_salary', $oldInput);
    }
}

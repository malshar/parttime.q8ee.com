<?php

namespace Tests\Feature\Admin;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Instructor;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\ChecklistItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChecklistItemSeeder::class);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_sections_are_ordered_by_reference_number_with_missing_last_and_show_it(): void
    {
        $term = Term::factory()->open()->create();
        $b = Section::factory()->for($term)->create(['course_code' => '7230999', 'reference_number' => '20002']);
        $a = Section::factory()->for($term)->create(['course_code' => '7230001', 'reference_number' => '20001']);
        $none = Section::factory()->for($term)->create(['course_code' => '7230000', 'reference_number' => null]);

        $html = $this->actingAs($this->admin)->get(route('admin.sections.index', ['term' => $term->id]))->assertOk()->getContent();

        $this->assertLessThan(strpos($html, '20002'), strpos($html, '20001'));
        $this->assertLessThan(strpos($html, '7230000'), strpos($html, '20002'));
        $this->assertStringContainsString(__('app.sections.reference'), $html);
    }

    public function test_filters_by_reference_course_name_and_instructor_on_both_pages(): void
    {
        $term = Term::factory()->open()->create();
        $s1 = Section::factory()->for($term)->create(['course_code' => '7230101', 'course_name_ar' => 'الدوائر الكهربائية', 'reference_number' => '30001', 'scheduled_instructor' => 'سعد فهد']);
        $s2 = Section::factory()->for($term)->create(['course_code' => '7240202', 'course_name_ar' => 'الآلات الكهربائية', 'reference_number' => '30002']);
        $app = Application::factory()->approved()->for($term)->for(Instructor::factory()->for(User::factory()->instructor())->create(['full_name' => 'نورة علي']))->create();
        Assignment::factory()->for($app)->for($s2)->create();

        foreach (['admin.sections.index', 'admin.assignments.index'] as $route) {
            $this->actingAs($this->admin)->get(route($route, ['term' => $term->id, 'reference' => '30001']))->assertSee('7230101')->assertDontSee('7240202');
            $this->actingAs($this->admin)->get(route($route, ['term' => $term->id, 'course' => '724']))->assertSee('7240202')->assertDontSee('7230101');
            $this->actingAs($this->admin)->get(route($route, ['term' => $term->id, 'name' => 'الآلات']))->assertSee('7240202')->assertDontSee('7230101');
            $this->actingAs($this->admin)->get(route($route, ['term' => $term->id, 'instructor' => 'سعد']))->assertSee('7230101')->assertDontSee('7240202');
            $this->actingAs($this->admin)->get(route($route, ['term' => $term->id, 'instructor' => 'نورة']))->assertSee('7240202')->assertDontSee('7230101');
            $this->actingAs($this->admin)->get(route($route, ['term' => $term->id, 'reference' => '99999']))->assertOk()->assertSee(__('app.sections.no_matches'))->assertSee('value="99999"', false);
        }
    }
}

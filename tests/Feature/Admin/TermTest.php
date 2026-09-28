<?php

namespace Tests\Feature\Admin;

use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_admin_creates_term_with_holidays(): void
    {
        $this->actingAs($this->admin())->post(route('admin.terms.store'), [
            'academic_year' => '2026-2027', 'type' => 'first',
            'teaching_starts_on' => '2026-09-13', 'teaching_ends_on' => '2026-12-24',
            'holidays' => "2026-09-24|اليوم الوطني\n2026-11-15|عطلة تجريبية",
        ])->assertRedirect(route('admin.terms.index'));

        $term = Term::firstOrFail();
        $this->assertSame('open', $term->status);
        $this->assertCount(2, $term->holidays);
        $this->assertSame('اليوم الوطني', $term->holidays->firstWhere('date', '2026-09-24')->name);
    }

    public function test_only_one_open_term_at_a_time(): void
    {
        Term::factory()->open()->create(['academic_year' => '2025-2026', 'type' => 'summer']);

        $this->actingAs($this->admin())->from(route('admin.terms.create'))->post(route('admin.terms.store'), [
            'academic_year' => '2026-2027', 'type' => 'first',
            'teaching_starts_on' => '2026-09-13', 'teaching_ends_on' => '2026-12-24',
        ])->assertRedirect(route('admin.terms.create'))->assertSessionHasErrors('academic_year');
    }

    public function test_duplicate_year_and_type_rejected(): void
    {
        Term::factory()->create(['academic_year' => '2026-2027', 'type' => 'first', 'status' => 'closed']);

        $this->actingAs($this->admin())->post(route('admin.terms.store'), [
            'academic_year' => '2026-2027', 'type' => 'first',
            'teaching_starts_on' => '2026-09-13', 'teaching_ends_on' => '2026-12-24',
        ])->assertSessionHasErrors('type');
    }

    public function test_close_term_and_current_helper(): void
    {
        $term = Term::factory()->open()->create();
        $this->assertTrue($term->is(Term::current()));

        $this->actingAs($this->admin())->post(route('admin.terms.close', $term))->assertRedirect();

        $this->assertSame('closed', $term->fresh()->status);
        $this->assertNull(Term::current());
    }

    public function test_instructor_cannot_manage_terms(): void
    {
        $this->actingAs(User::factory()->instructor()->create())->get(route('admin.terms.index'))->assertForbidden();
    }
}

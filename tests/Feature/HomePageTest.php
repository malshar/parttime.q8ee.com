<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomePageTest extends TestCase
{
    public function test_home_renders_arabic_rtl_with_site_name(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('نظام المنتدبين');
        $response->assertSee('قسم تكنولوجيا الهندسة الكهربائية');
    }

    public function test_lang_query_switches_to_english(): void
    {
        $response = $this->get('/?lang=en');

        $response->assertOk();
        $response->assertSee('dir="ltr"', false);
        $response->assertSee('Part-time Instructors System');
    }
}

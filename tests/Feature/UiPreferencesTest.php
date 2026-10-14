<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UiPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_light_theme_and_expanded_sidebar_are_the_default(): void
    {
        $this->get(route('partlists.index'))
            ->assertOk()
            ->assertSee('data-theme="light"', false)
            ->assertSee('data-sidebar-collapsed="false"', false);
    }

    public function test_dark_theme_from_cookie_is_rendered_on_every_page(): void
    {
        foreach (['partlists.index', 'andon.index'] as $route) {
            $this->withUnencryptedCookie('theme', 'dark')
                ->get(route($route))
                ->assertOk()
                ->assertSee('data-theme="dark"', false);
        }
    }

    public function test_collapsed_sidebar_from_cookie_is_rendered(): void
    {
        $this->withUnencryptedCookie('sidebar_collapsed', 'true')
            ->get(route('partlists.index'))
            ->assertSee('data-sidebar-collapsed="true"', false);
    }

    public function test_unknown_cookie_values_fall_back_to_defaults(): void
    {
        $this->withUnencryptedCookie('theme', '<script>')
            ->withUnencryptedCookie('sidebar_collapsed', 'maybe')
            ->get(route('partlists.index'))
            ->assertSee('data-theme="light"', false)
            ->assertSee('data-sidebar-collapsed="false"', false);
    }
}

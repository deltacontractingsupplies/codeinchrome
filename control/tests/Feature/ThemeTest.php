<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Tests\TestCase;

/**
 * Light and dark themes: every page shell sets the theme BEFORE its styles
 * load (no flash), from our own origin (the CSP allows no inline script), and
 * offers the switch.
 */
class ThemeTest extends TestCase
{
    private function assertThemed(string $html): void
    {
        $theme = strpos($html, '<script src="/theme.js"></script>');
        $styles = strpos($html, '<link rel="stylesheet"') ?: strpos($html, '/build/');
        $this->assertNotFalse($theme, 'The page does not load /theme.js.');
        $this->assertLessThan($styles, $theme, '/theme.js must run before the styles load, or light-mode visitors see a dark flash.');
        $this->assertStringContainsString('data-theme-toggle', $html);
    }

    public function test_the_site_pages_set_the_theme_before_paint_and_offer_the_switch(): void
    {
        foreach (['/', '/pricing', '/login'] as $page) {
            $this->assertThemed($this->get($page)->assertOk()->getContent());
        }
    }

    public function test_the_editor_does_too(): void
    {
        $user = User::factory()->create();
        $site = Site::create(['user_id' => $user->id, 'site_id' => 'themed', 'domain' => 'themed.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '512m']);

        $this->assertThemed($this->actingAs($user)->get(route('sites.edit', $site))->assertOk()->getContent());
    }

    public function test_the_theme_script_is_served_from_our_origin(): void
    {
        $this->assertFileExists(public_path('theme.js'));
        $this->assertStringContainsString("script-src 'self'", $this->get('/')->headers->get('Content-Security-Policy'));
    }
}

<?php

namespace Tests\Feature;

use App\Auth\LoginLink;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class LoginLinkTest extends TestCase
{
    public function test_a_link_signs_in_once_and_is_audited(): void
    {
        $user = User::factory()->create();
        $url = LoginLink::issue($user);

        $this->get($url)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.login_link']);

        auth()->logout();
        $this->get($url)->assertNotFound();
        $this->assertGuest();
    }

    public function test_an_expired_or_made_up_link_does_nothing(): void
    {
        $user = User::factory()->create();
        $url = LoginLink::issue($user, 1);
        $this->travel(2)->minutes();
        $this->get($url)->assertNotFound();
        $this->get(route('login.link', str_repeat('a', 64)))->assertNotFound();
        $this->get(route('login.link', 'short'))->assertNotFound();
        $this->assertGuest();
    }

    public function test_a_two_factor_account_can_never_get_or_use_a_link(): void
    {
        $user = User::factory()->create();
        $url = LoginLink::issue($user);
        // Two-factor turned on after the link was printed: the link dies.
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->get($url)->assertNotFound();
        $this->assertGuest();

        $this->assertSame(1, Artisan::call('user:login-link', ['email' => $user->email]));
    }

    public function test_only_the_command_line_issues_links(): void
    {
        $user = User::factory()->create();
        $this->assertSame(0, Artisan::call('user:login-link', ['email' => $user->email]));
        $this->assertStringContainsString('/login/link/', Artisan::output());
        // No web route issues one.
        foreach (app('router')->getRoutes() as $route) {
            $this->assertStringNotContainsString('LoginLink@issue', (string) $route->getActionName());
        }
    }
}

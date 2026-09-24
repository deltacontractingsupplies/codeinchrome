<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Tests\TestCase;

/**
 * Blade that was not compiled reaches the page as text: "used@if (...)" was
 * shown on every dashboard, because a directive glued to the word before it is
 * not a directive. Every page a customer sees is checked for leftovers.
 */
class NoRawTemplateTest extends TestCase
{
    private function assertCompiled(string $html, string $page): void
    {
        foreach (['@if', '@endif', '@foreach', '@php', '@include', '{{', '}}', '{!!'] as $raw) {
            // Pages that SHOW Blade (the home page's code sample) escape it; a
            // raw token outside the code sample is a template bug.
            $outside = preg_replace('#<(pre|code)\b.*?</\1>#s', '', $html);
            $this->assertStringNotContainsString($raw, $outside, "$page shows raw template text: $raw");
        }
    }

    public function test_no_customer_page_shows_uncompiled_blade(): void
    {
        foreach (['/', '/pricing', '/login', '/register', '/terms', '/privacy', '/refunds'] as $path) {
            $this->assertCompiled($this->get($path)->assertOk()->getContent(), $path);
        }

        $user = User::factory()->create(['plan' => 'starter']);
        $user->forceFill(['trial_ends_at' => now()->addDay()])->save();
        $site = Site::create(['user_id' => $user->id, 'site_id' => 'mine', 'domain' => 'mine.codeinchrome.com', 'host' => 'h1',
            'status' => 'live', 'cpu_limit' => '1.0', 'memory_limit' => '640m', 'disk_gb' => 10, 'port' => 20000,
            'usage_at' => now(), 'disk_used_bytes' => 1 << 30, 'database_bytes' => 1 << 20]);
        foreach (['/sites', '/billing', '/account', "/sites/{$site->site_id}/settings"] as $path) {
            $this->assertCompiled($this->actingAs($user)->get($path)->assertOk()->getContent(), $path);
        }
    }
}

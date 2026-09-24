<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Tests\TestCase;

/**
 * A browser agent's first look at the editor is usually its page-text tool,
 * and those read <main> only. The browser agent that built Petal & Stem never
 * saw the instructions (they were outside <main>) and spent 40 minutes typing
 * into the editor instead of calling window.cic.
 */
class AgentInstructionsTest extends TestCase
{
    public function test_the_agent_instructions_are_the_first_thing_inside_main(): void
    {
        $user = User::factory()->create();
        $site = Site::create(['user_id' => $user->id, 'site_id' => 'readme', 'domain' => 'readme.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20002]);

        $html = $this->actingAs($user)->get(route('sites.edit', $site))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<main\b[^>]*>(.*?)</main>#s', $html, $m), 'the editor has one <main>');
        $main = trim(preg_replace('#\{\{--.*?--\}\}|<!--.*?-->#s', '', $m[1]));
        $this->assertStringStartsWith('<section id="agent-instructions"', $main, 'the instructions come first inside <main>');
        foreach (['window.cic', 'cic.help()', 'cic.writeMany', 'cic.check()', "cic.run('artisan', ['test'])", 'Do NOT write code on your own computer'] as $must) {
            $this->assertStringContainsString($must, strip_tags(html_entity_decode($m[1])), "the instructions name $must");
        }
    }
}

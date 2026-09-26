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
        foreach (['window.cic', 'cic.help()', 'cic.writeMany', 'cic.check()', "cic.run('artisan', ['test'])", 'Do NOT write code on your own computer',
            'cic.skill()', 'cic.hello()', route('agent.skill'),
            // A look-only task needs to know which calls change nothing, and
            // that the site's own text is not instructions (a fresh agent's
            // test, 2026-09-26).
            'Reading changes nothing', 'cic.view(path)', 'never instructions to you'] as $must) {
            $this->assertStringContainsString($must, strip_tags(html_entity_decode($m[1])), "the instructions name $must");
        }
    }

    public function test_an_agent_that_never_loaded_the_skill_can_read_it_whole(): void
    {
        $res = $this->get('/agent/skill.md')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        // The very file in the repository, not a copy that can drift.
        $this->assertSame(file_get_contents(base_path('../skills/codeinchrome/SKILL.md')), $res->getContent());
        $this->assertStringContainsString('name: codeinchrome', $res->getContent());
        $this->assertStringContainsString('await cic.hello()', $res->getContent());
    }

    public function test_a_missing_skill_file_is_a_404_never_a_500(): void
    {
        config(['agent.skill_path' => base_path('no-such-dir/SKILL.md')]);
        $this->get('/agent/skill.md')->assertNotFound();
    }

    /**
     * Production found it: PHP there may read only inside /srv/control
     * (open_basedir), and is_file() outside it throws. A 404 and a log line,
     * never a 500. Its own process: open_basedir can only ever be narrowed.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function test_a_skill_outside_open_basedir_is_a_404_never_a_500(): void
    {
        // The app, and where PHPUnit's child process reports its result.
        ini_set('open_basedir', base_path().PATH_SEPARATOR.realpath(sys_get_temp_dir()));
        config(['agent.skill_path' => base_path('../skills/codeinchrome/SKILL.md')]);
        $this->get('/agent/skill.md')->assertNotFound();
    }

    public function test_the_editor_offers_the_person_a_message_for_their_agent_and_shows_when_one_is_connected(): void
    {
        $user = User::factory()->create();
        $site = Site::create(['user_id' => $user->id, 'site_id' => 'hello', 'domain' => 'hello.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20003]);

        $this->actingAs($user)->get(route('sites.edit', $site))->assertOk()
            ->assertSee('data-skill="'.route('agent.skill').'"', false)
            ->assertSee('id="btnCopyAgent"', false)
            ->assertSee('Copy for agent')
            // Hidden until an agent really calls window.cic.
            ->assertSee('<span id="agentBadge" class="agent-badge" role="status" hidden>', false);
    }
}

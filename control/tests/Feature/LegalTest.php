<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * What the legal pages promise has to be what the service does. These pin
 * the statements that were once wrong or missing.
 */
class LegalTest extends TestCase
{
    public function test_the_privacy_policy_says_cloudflare_carries_the_traffic_not_only_dns(): void
    {
        // Every site and this app are behind Cloudflare's proxy: it sees visitors' IPs and the pages.
        $privacy = preg_replace('/\s+/', ' ', strip_tags($this->get(route('privacy'))->assertOk()->getContent()));
        $this->assertStringContainsString('all web traffic passes through it', $privacy);
        $this->assertStringContainsString("including visitors' IP addresses", $privacy);
        $this->assertStringNotContainsString('DNS for codeinchrome.com and your site addresses.', $privacy);
    }

    public function test_the_ai_agent_is_said_to_be_a_separate_service_everywhere_it_matters(): void
    {
        $privacy = preg_replace('/\s+/', ' ', strip_tags($this->get(route('privacy'))->assertOk()->getContent()));
        $this->assertStringContainsString("We do not send your code, your sites' data or anything else to an AI provider", $privacy);
        $this->assertStringContainsString("under that provider's own terms and privacy policy", $privacy);
        $refunds = preg_replace('/\s+/', ' ', strip_tags($this->get(route('refunds'))->assertOk()->getContent()));
        $this->assertStringContainsString('not to us, so we cannot refund it', $refunds);
        $this->get(route('terms'))->assertOk()
            ->assertSee('is not part of our service')
            ->assertSee('paid for')
            ->assertSee('separately');
    }

    public function test_the_terms_state_the_free_site_idle_pause_and_first_week_noindex(): void
    {
        $this->get(route('terms'))->assertOk()
            ->assertSee('for its first 7 days', false)
            ->assertSee('no visitors and no edits for 30 days', false)
            ->assertSee('at least 3 days before', false)
            ->assertSee('comes back with one click', false);
    }

    public function test_the_pages_say_when_they_last_changed(): void
    {
        $this->get(route('terms'))->assertSee('Last updated '.config('legal.updated'));
    }

    public function test_security_txt_says_how_to_report_a_vulnerability(): void
    {
        $body = $this->get('/.well-known/security.txt')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')->getContent();

        $this->assertStringContainsString('Contact: mailto:'.config('legal.support_email'), $body);
        $this->assertSame(1, preg_match('/^Expires: (\S+)$/m', $body, $m));
        $expires = \Illuminate\Support\Carbon::parse($m[1]);
        $this->assertTrue($expires->isFuture() && $expires->lessThanOrEqualTo(now()->addYear()), 'in the future, at most a year ahead');
        // The policy is SECURITY.md on GitHub (scope, safe harbour), not the terms.
        $this->assertStringContainsString("Policy: https://github.com/deltacontractingsupplies/codeinchrome/security/policy\n", $body);
    }

    public function test_every_public_page_links_the_public_source_code(): void
    {
        foreach (['/', '/pricing', '/terms', '/privacy', '/refunds', '/login', '/register'] as $page) {
            $this->get($page)->assertOk()
                ->assertSee('href="https://github.com/deltacontractingsupplies/codeinchrome"', false);
        }
    }
}

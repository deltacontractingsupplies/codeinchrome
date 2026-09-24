<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The owner hears of every new site and domain (owner's request, 2026-09-25).
 */
class OwnerNotifierTest extends TestCase
{
    private int $port = 22000;

    private function site(string $id, string $email): Site
    {
        $user = User::factory()->create(['email' => $email, 'plan' => 'free']);

        return Site::create(['user_id' => $user->id, 'site_id' => $id, 'domain' => "$id.codeinchrome.com",
            'host' => 'h1', 'status' => 'provisioning', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => $this->port++]);
    }

    private function sent(): array
    {
        return array_map(fn ($e) => $e->message, $this->messages);
    }

    private array $messages = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['fleet.owner_notify_email' => 'owner@example.com', 'fleet.mail_enabled' => true]);
        Event::listen(MessageSent::class, fn ($e) => $this->messages[] = $e);
    }

    public function test_a_new_site_is_mailed_to_the_owner_with_its_address_and_account(): void
    {
        $this->site('bakery', 'baker@gmail.com');

        $this->assertCount(1, $this->messages);
        $m = $this->messages[0]->message;
        $this->assertSame('owner@example.com', $m->getTo()[0]->getAddress());
        $this->assertSame('[codeinchrome] New site: bakery.codeinchrome.com', $m->getSubject());
        $body = $m->getTextBody();
        $this->assertStringContainsString('Site: https://bakery.codeinchrome.com', $body);
        $this->assertStringContainsString('Account: baker@gmail.com', $body);
        $this->assertStringContainsString('Plan: free', $body);
    }

    public function test_a_new_custom_domain_is_mailed_too(): void
    {
        $site = $this->site('bakery', 'baker@gmail.com');
        $this->messages = [];
        $site->domains()->create(['domain' => 'bakery.example', 'token' => str_repeat('a', 40)]);
        // Created directly here: the controller is what calls newDomain.
        \App\Fleet\OwnerNotifier::newDomain($site->domains()->first());

        $this->assertCount(1, $this->messages);
        $this->assertSame('[codeinchrome] New domain: bakery.example on bakery.codeinchrome.com', $this->messages[0]->message->getSubject());
    }

    public function test_the_platforms_own_test_sites_and_an_unset_address_send_nothing(): void
    {
        $this->site('e2e-run', 'ed-abc@codeinchrome.test');
        $this->assertCount(0, $this->messages, 'the e2e suite would flood the owner');

        config(['fleet.owner_notify_email' => null]);
        $this->site('quiet', 'someone@gmail.com');
        $this->assertCount(0, $this->messages);
    }

    public function test_a_mail_that_cannot_go_out_never_stops_the_site(): void
    {
        config(['mail.default' => 'broken', 'mail.mailers.broken' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);
        $site = $this->site('resilient', 'someone@gmail.com');
        $this->assertNotNull($site->id, 'the site must be created even when the mail fails');
    }
}

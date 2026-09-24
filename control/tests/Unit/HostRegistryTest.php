<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The fleet's hosts come from one registry string, never a list in code, so a
 * new server is added by registering it - nothing in the application changes.
 */
class HostRegistryTest extends TestCase
{
    private function hosts(string $registry, string $states = ''): array
    {
        putenv("CIC_HOSTS=$registry");
        putenv("CIC_HOST_STATES=$states");
        $_ENV['CIC_HOSTS'] = $_SERVER['CIC_HOSTS'] = $registry;
        $_ENV['CIC_HOST_STATES'] = $_SERVER['CIC_HOST_STATES'] = $states;
        try {
            return (require base_path('config/fleet.php'))['hosts'];
        } finally {
            putenv('CIC_HOSTS');
            putenv('CIC_HOST_STATES');
            unset($_ENV['CIC_HOSTS'], $_SERVER['CIC_HOSTS'], $_ENV['CIC_HOST_STATES'], $_SERVER['CIC_HOST_STATES']);
        }
    }

    public function test_a_new_host_is_one_more_entry_with_its_tunnel_port_derived(): void
    {
        $hosts = $this->hosts('h1:203.0.113.105 h3:203.0.113.102 h12:10.1.2.3', 'h3:draining');

        $this->assertSame(['h1', 'h3', 'h12'], array_keys($hosts));
        $this->assertSame(['ip' => '10.1.2.3', 'tunnel_port' => 9452, 'capacity' => 60, 'state' => 'active'], $hosts['h12']);
        $this->assertSame('draining', $hosts['h3']['state']);
    }

    public function test_malformed_entries_are_left_out_not_guessed(): void
    {
        $hosts = $this->hosts('h1:203.0.113.105 web:1.2.3.4 h2:not-an-ip h5 h7:10.0.0.7');

        $this->assertSame(['h1', 'h7'], array_keys($hosts));
    }
}

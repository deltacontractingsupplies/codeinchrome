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
        // Put back what was there (phpunit.xml sets both), never just unset:
        // an unset left every later test with no fleet at all - hidden on a
        // machine where config/fleet.php falls back to infra/hosts.local.env.
        $saved = [];
        foreach (['CIC_HOSTS' => $registry, 'CIC_HOST_STATES' => $states] as $key => $value) {
            $saved[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv("$key=$value");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        try {
            return (require base_path('config/fleet.php'))['hosts'];
        } finally {
            foreach ($saved as $key => [$env, $e, $s]) {
                putenv($env === false ? $key : "$key=$env");
                if ($e === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $e;
                }
                if ($s === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $s;
                }
            }
        }
    }

    public function test_a_new_host_is_one_more_entry_with_its_tunnel_port_derived(): void
    {
        $hosts = $this->hosts('h1:203.0.113.10 h3:203.0.113.30 h12:10.1.2.3', 'h3:draining');

        $this->assertSame(['h1', 'h3', 'h12'], array_keys($hosts));
        $this->assertSame(['ip' => '10.1.2.3', 'tunnel_port' => 9452, 'capacity' => 60, 'state' => 'active'], $hosts['h12']);
        $this->assertSame('draining', $hosts['h3']['state']);
    }

    public function test_malformed_entries_are_left_out_not_guessed(): void
    {
        $hosts = $this->hosts('h1:203.0.113.10 web:1.2.3.4 h2:not-an-ip h5 h7:10.0.0.7');

        $this->assertSame(['h1', 'h7'], array_keys($hosts));
    }
}

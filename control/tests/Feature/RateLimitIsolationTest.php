<?php

namespace Tests\Feature;

use Tests\TestCase;

class RateLimitIsolationTest extends TestCase
{
    public function test_failed_logins_do_not_lock_out_registration(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->post('/login', ['email' => "x$i@example.com", 'password' => 'wrong']);
        }
        $this->assertNotSame(429, $this->post('/register', ['name' => 'a', 'email' => 'n@example.com',
            'password' => 'x', 'password_confirmation' => 'x'])->status());
    }

    public function test_failing_one_address_does_not_lock_out_another(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->post('/login', ['email' => 'victim@example.com', 'password' => 'wrong']);
        }
        $this->assertSame(429, $this->post('/login', ['email' => 'victim@example.com', 'password' => 'wrong'])->status());
        $this->assertNotSame(429, $this->post('/login', ['email' => 'someone-else@example.com', 'password' => 'wrong'])->status());
    }

    public function test_one_ip_cannot_spray_unlimited_addresses(): void
    {
        $codes = [];
        for ($i = 0; $i < 35; $i++) {
            $codes[] = $this->post('/login', ['email' => "spray$i@example.com", 'password' => 'wrong'])->status();
        }
        $this->assertContains(429, $codes, 'Thirty-five different addresses from one IP in a minute were never throttled.');
    }
}

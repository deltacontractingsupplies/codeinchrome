<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AbuseNamesAndMailTest extends TestCase
{
    public function test_names_phishing_kits_use_are_refused_and_ordinary_ones_are_not(): void
    {
        foreach (['paypal-login', 'secure-paypal', 'apple-id-verify', 'my-microsoft-account', 'netflix-billing',
            'chase-bank-alert', 'dhl-parcel', 'google-drive-share', 'metamask-wallet', 'codeinchrome-admin'] as $bad) {
            $this->assertNotNull(Site::validId($bad), "$bad was allowed");
        }
        foreach (['pineapple-shop', 'foodbank', 'barber-b', 'ups-and-downs-cafe', 'shop', 'larashop', 'visage-salon',
            'steamed-buns', 'bankside-bakery', 'grapes-and-apples'] as $ok) {
            $this->assertNull(Site::validId($ok), "$ok was refused: ".Site::validId($ok));
        }
        $this->assertStringContainsString('phishing sites use', Site::validId('paypal-login'));
    }

    public function test_reset_mail_to_one_address_is_capped_whoever_asks(): void
    {
        config(['fleet.mail_enabled' => true]);
        User::factory()->create(['email' => 'victim@gmail.com']);
        $sent = 0;
        Event::listen(MessageSent::class, function () use (&$sent) { $sent++; });

        for ($i = 0; $i < 6; $i++) {
            // A different address each time, and past Laravel's one-a-minute
            // broker throttle: only the per-address cap can stop this.
            $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.$i"])
                ->post('/forgot-password', ['email' => 'victim@gmail.com'])->assertSessionHas('status');
            $this->travel(2)->minutes();
        }
        $this->assertSame(3, $sent, 'at most 3 an hour to one address');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class StoreTest extends TestCase
{
    private function product(array $attributes = []): Product
    {
        return Product::create($attributes + [
            'slug' => 'test-roast', 'name' => 'Test Roast', 'origin' => 'Colombia', 'roast' => 'medium',
            'notes' => 'cocoa, cherry', 'description' => 'A coffee for tests.', 'price_cents' => 1800,
            'stock' => 5, 'hue' => 30, 'featured' => true,
        ]);
    }

    private const CUSTOMER = ['name' => 'Ada Test', 'email' => 'ada@example.test', 'phone' => '+1 555 0100', 'address' => '1 Test Street'];

    public function test_the_catalogue_and_a_product_page_answer(): void
    {
        $this->product();

        $this->get('/')->assertOk()->assertSee('Test Roast');
        $this->get('/coffee/test-roast')->assertOk()->assertSee('A coffee for tests.');
        $this->get('/coffee/no-such-coffee')->assertNotFound();
    }

    public function test_checkout_places_a_cash_order_and_takes_the_stock(): void
    {
        $product = $this->product();

        $this->post('/bag/test-roast')->assertRedirect('/bag');
        $this->post('/checkout', self::CUSTOMER)->assertRedirect('/checkout/done');

        $order = Order::sole();
        $this->assertSame('cash', $order->payment_method);
        $this->assertSame('placed', $order->status);
        $this->assertSame(1, $order->items()->sum('quantity'));
        $this->assertSame(4, $product->fresh()->stock);
        $this->get('/checkout/done')->assertOk()->assertSee($order->reference);
    }

    public function test_a_bad_phone_is_refused_and_nothing_is_taken(): void
    {
        $product = $this->product();

        $this->post('/bag/test-roast');
        $this->post('/checkout', ['phone' => 'call me'] + self::CUSTOMER)->assertSessionHasErrors('phone');

        $this->assertSame(0, Order::count());
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_an_empty_bag_cannot_check_out(): void
    {
        $this->post('/checkout', self::CUSTOMER)->assertRedirect('/')->assertSessionHas('error');
        $this->assertSame(0, Order::count());
    }

    public function test_what_sold_out_meanwhile_is_refused_and_no_stock_goes_below_zero(): void
    {
        $product = $this->product(['stock' => 1]);

        $this->post('/bag/test-roast');
        $product->update(['stock' => 0]); // someone else bought the last bag

        $this->post('/checkout', self::CUSTOMER)->assertSessionHas('error');
        $this->assertSame(0, Order::count());
        $this->assertSame(0, $product->fresh()->stock);
    }

    public function test_the_admin_needs_an_admin_sign_in(): void
    {
        // Guests go through Laravel's /login, which sends them on to the admin's sign-in.
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/login')->assertRedirect('/admin/login');

        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/admin')->assertRedirect('/admin/login');

        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();
        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_the_public_demo_login_can_look_but_never_change_anything(): void
    {
        $product = $this->product();
        $demo = User::factory()->create()->forceFill(['is_admin' => true, 'is_demo' => true]);
        $demo->save();

        $this->actingAs($demo)->get('/admin/products/test-roast')->assertOk();
        $this->actingAs($demo)->put('/admin/products/test-roast', ['name' => 'Changed', 'price_cents' => 100, 'stock' => 1, 'notes' => 'x'])
            ->assertSessionHas('error');

        $this->assertSame('Test Roast', $product->fresh()->name);
        $this->assertSame(5, $product->fresh()->stock);
    }
}

<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ShopSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['yirgacheffe-kochere', 'Yirgacheffe Kochere', 'Ethiopia', 'light', 'Jasmine, bergamot, lemon curd', 'Washed heirloom varieties from smallholders around Kochere, dried slowly on raised beds. Floral and bright, with a tea-like finish.', 2100, 40, '#d8b24a', true],
            ['huila-el-mirador', 'Huila El Mirador', 'Colombia', 'medium', 'Red apple, panela, cocoa', 'Caturra and Castillo from a single ridge-top farm at 1,800 m. Sweet and round - the one to drink every morning.', 1800, 55, '#b5553c', true],
            ['sumatra-kerinci', 'Sumatra Kerinci', 'Indonesia', 'dark', 'Cedar, dark chocolate, clove', 'Wet-hulled on the slopes of Mount Kerinci. Heavy body, low acidity, and a long, spiced finish that stands up to milk.', 1900, 30, '#3f4a36', true],
            ['nyeri-gatomboya', 'Nyeri Gatomboya', 'Kenya', 'light', 'Blackcurrant, grapefruit, cane sugar', 'SL28 and SL34 processed at the Gatomboya factory. Juicy and intense - taste it as it cools.', 2300, 20, '#8c2f4f', false],
            ['cerrado-house', 'Cerrado House Blend', 'Brazil', 'medium', 'Hazelnut, milk chocolate, caramel', 'Natural-process beans from the Cerrado Mineiro, roasted for espresso. Forgiving, sweet, and very good in a flat white.', 1500, 80, '#7a5230', true],
            ['antigua-san-sebastian', 'Antigua San Sebastián', 'Guatemala', 'medium', 'Brown sugar, orange peel, almond', 'Bourbon grown in volcanic soil under shade trees. Balanced and clean with a gentle citrus lift.', 1900, 35, '#c07a3a', false],
            ['tarrazu-la-pastora', 'Tarrazú La Pastora', 'Costa Rica', 'light', 'Honey, peach, white grape', 'Honey-processed, so some of the fruit dries on the bean. Silky, sweet, and very easy to like.', 2000, 25, '#e0a458', false],
            ['decaf-swiss-water', 'Swiss Water Decaf', 'Peru', 'medium', 'Toffee, walnut, red fruit', 'Decaffeinated with water alone - no solvents - from organic Cajamarca beans. All the flavour, none of the 3 a.m.', 1700, 45, '#5d7a8c', false],
            ['espresso-no-7', 'Espresso No. 7', 'Blend', 'dark', 'Molasses, black cherry, cocoa nib', 'Brazil, Sumatra and a little Ethiopia for lift. Built for espresso machines and moka pots.', 1600, 70, '#2f2a28', true],
            ['geisha-hacienda', 'Geisha, Hacienda La Esmeralda', 'Panama', 'light', 'Jasmine, papaya, honeysuckle', 'A micro-lot of the variety that made Panama famous. Rare and delicate - brew it as a pour-over and take your time.', 4800, 8, '#9b7fb6', false],
            ['cold-brew-pack', 'Cold Brew Pack', 'Blend', 'medium', 'Chocolate, cherry, malt', 'Coarse-ground and measured for a litre jar. Steep overnight in the fridge, strain, and it keeps for a week.', 1400, 60, '#43698a', false],
            ['sampler', 'The Sampler', 'Four origins', 'mixed', 'One of everything we love', 'Four 100 g bags: Ethiopia, Colombia, Kenya and Sumatra. The best way to find your favourite.', 2600, 15, '#6c8f5e', true],
        ];
        foreach ($products as [$slug, $name, $origin, $roast, $notes, $description, $price, $stock, $hue, $featured]) {
            Product::updateOrCreate(['slug' => $slug], compact('name', 'origin', 'roast', 'notes', 'description', 'stock', 'hue', 'featured') + ['price_cents' => $price]);
        }

        // The published demo login. Its password comes from the environment so
        // the one written on codeinchrome.com is the one that works - and the
        // account can change nothing (DemoReadOnly).
        User::updateOrCreate(['email' => 'demo@emberandoak.test'], [
            'name' => 'Demo visitor',
            'password' => Hash::make(env('SHOP_DEMO_PASSWORD', 'read-only-demo')),
            'is_admin' => true,
            'is_demo' => true,
        ]);
    }
}

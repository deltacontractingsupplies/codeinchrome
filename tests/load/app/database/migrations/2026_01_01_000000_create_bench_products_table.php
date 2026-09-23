<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A small shop catalogue: 200 products in 8 categories.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bench_products', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('category')->index();
            $t->text('description');
            $t->unsignedInteger('price_cents');
            $t->unsignedInteger('stock');
            $t->timestamps();
        });
        $rows = [];
        for ($i = 1; $i <= 200; $i++) {
            $rows[] = [
                'name' => "Product $i", 'category' => 'cat-' . ($i % 8),
                'description' => str_repeat("A well made thing, number $i. ", 6),
                'price_cents' => 500 + ($i * 137) % 20000, 'stock' => $i % 50,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('bench_products')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('bench_products');
    }
};

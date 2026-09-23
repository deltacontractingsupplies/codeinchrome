<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('origin');
            $table->string('roast');           // light, medium, dark
            $table->string('notes');           // tasting notes
            $table->text('description');
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('stock');
            $table->string('hue', 7);          // the bag's colour
            $table->boolean('featured')->default(false);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('email')->nullable();
            $table->unsignedInteger('total_cents');
            $table->string('status')->default('pending'); // pending, paid, cancelled
            $table->string('stripe_session_id')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('price_cents');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            // The published demo login: may look at everything, change nothing.
            $table->boolean('is_demo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['is_admin', 'is_demo']));
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('products');
    }
};

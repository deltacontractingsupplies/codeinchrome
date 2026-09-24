<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('name')->nullable()->after('email');
            $table->string('phone', 40)->nullable()->after('name');
            $table->text('address')->nullable()->after('phone');
            // Cash on delivery: the order is "placed" and paid when it arrives.
            $table->string('payment_method', 20)->default('cash')->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['name', 'phone', 'address', 'payment_method']));
    }
};

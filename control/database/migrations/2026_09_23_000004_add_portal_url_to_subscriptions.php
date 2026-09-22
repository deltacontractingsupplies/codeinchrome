<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Lemon Squeezy's signed customer-portal link, from the webhook.
            // Where a customer changes card, plan or cancels - we never see
            // or store payment details ourselves.
            $table->text('portal_url')->nullable()->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('portal_url'));
    }
};

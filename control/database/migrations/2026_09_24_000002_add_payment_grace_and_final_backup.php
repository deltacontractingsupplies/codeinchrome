<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // When a payment first failed (past_due / unpaid). The customer
            // keeps the paid plan for the grace period after it; cleared when
            // a payment goes through.
            $table->timestamp('payment_failed_at')->nullable()->after('ends_at');
            $table->timestamp('payment_warned_at')->nullable()->after('payment_failed_at');
        });
        Schema::table('sites', function (Blueprint $table) {
            // A paying customer's site is never deleted without a backup taken
            // for the purpose and confirmed: the snapshot id, once it exists.
            $table->string('final_backup', 64)->nullable()->after('limits_pending');
            $table->timestamp('final_backup_started_at')->nullable()->after('final_backup');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn(['payment_failed_at', 'payment_warned_at']));
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn(['final_backup', 'final_backup_started_at']));
    }
};

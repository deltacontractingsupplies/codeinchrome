<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Set at sign-up. NULL means no trial clock: accounts that existed
            // before trials did keep what they have (nothing is deleted
            // retroactively), and so do paying customers.
            $table->timestamp('trial_ends_at')->nullable()->after('plan');
            $table->timestamp('trial_warned_at')->nullable()->after('trial_ends_at');
            // When the account's sites were paused for want of a plan; they
            // are deleted a grace period after this.
            $table->timestamp('suspended_at')->nullable()->after('trial_warned_at');
            // Set by fleet:sync-usage while the plan's storage is exceeded.
            $table->timestamp('storage_over_at')->nullable()->after('suspended_at');
            $table->index(['plan', 'trial_ends_at']);
        });

        // Pro and Studio are no longer sold. Anyone holding one keeps a paid
        // plan rather than silently falling to free.
        DB::table('users')->whereIn('plan', ['pro', 'studio'])->update(['plan' => 'starter']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['plan', 'trial_ends_at']);
            $table->dropColumn(['trial_ends_at', 'trial_warned_at', 'suspended_at', 'storage_over_at']);
        });
    }
};

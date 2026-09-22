<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedInteger('disk_gb')->default(1)->after('memory_limit');

            // Measured by fleet:sync-usage, never computed. usage_at says how
            // old the numbers are, so the dashboard can say so too.
            $table->unsignedBigInteger('disk_used_bytes')->nullable()->after('disk_gb');
            $table->unsignedBigInteger('disk_size_bytes')->nullable()->after('disk_used_bytes');
            $table->unsignedBigInteger('database_bytes')->nullable()->after('disk_size_bytes');
            $table->timestamp('usage_at')->nullable()->after('database_bytes');

            // Set when a plan change could not be applied to the host - the
            // host was down, say. fleet:apply-limits retries until it clears,
            // so a paid upgrade is never silently left unapplied.
            $table->boolean('limits_pending')->default(false)->after('usage_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['disk_gb', 'disk_used_bytes', 'disk_size_bytes', 'database_bytes', 'usage_at', 'limits_pending']);
        });
    }
};

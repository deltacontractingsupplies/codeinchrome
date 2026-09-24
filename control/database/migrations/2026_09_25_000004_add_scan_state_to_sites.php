<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * When a site last passed each check: the malware scan (abuse:scan) and the
 * link check (abuse:links). Explore lists only sites that passed both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('scanned_clean_at')->nullable();
            $table->timestamp('links_clean_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['scanned_clean_at', 'links_clean_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The site's CPU weight when its host is busy (owner, 2026-09-26: every site
 * bursts into idle CPU; a paid one wins when the host is full). Null: never
 * applied - fleet:apply-limits applies its plan's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedInteger('cpu_weight')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('cpu_weight');
        });
    }
};

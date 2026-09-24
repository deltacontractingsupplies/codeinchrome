<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // A disk is full when it runs out of inodes (files) as much as of
            // bytes; measured by fleet:sync-usage, watched by monitoring.
            $table->unsignedBigInteger('inodes_used')->nullable()->after('database_bytes');
            $table->unsignedBigInteger('inodes_total')->nullable()->after('inodes_used');
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn(['inodes_used', 'inodes_total']));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // The newest COMPLETE backup (files and database) the backup server
            // holds; fleet:sync-backups records it, monitoring alerts when it is old.
            $table->timestamp('last_backup_at')->nullable()->after('usage_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('last_backup_at'));
    }
};

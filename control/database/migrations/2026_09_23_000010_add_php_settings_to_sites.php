<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // The owner's PHP settings as last applied: memoryMB,
            // maxExecutionSeconds, uploadMB. Null means the image's defaults.
            $table->json('php_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('php_settings'));
    }
};

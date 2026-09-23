<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What a site runs beside its web server: a queue worker, the scheduler,
// Laravel Reverb. The host holds the truth; this is the record of what the
// customer asked for.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $t) {
            $t->boolean('queue')->default(false);
            $t->boolean('scheduler')->default(false);
            $t->boolean('reverb')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $t) => $t->dropColumn(['queue', 'scheduler', 'reverb']));
    }
};

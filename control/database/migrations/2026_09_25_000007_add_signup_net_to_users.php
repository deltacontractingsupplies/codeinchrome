<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The network a sign-up came from, as a keyed hash (App\Auth\ClientNet::signal),
 * never the address: a banned person back from the same home or office is
 * held for review before they can create a site (the second security audit,
 * 2026-09-25: only the canonical email linked them before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signup_net', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['signup_net']);
            $table->dropColumn('signup_net');
        });
    }
};

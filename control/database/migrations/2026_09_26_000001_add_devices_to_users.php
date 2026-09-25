<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The browser an account was made in, and last signed in from (App\Auth\Device): keyed hashes. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signup_device', 64)->nullable()->index();
            $table->string('last_device', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['signup_device']);
            $table->dropIndex(['last_device']);
            $table->dropColumn(['signup_device', 'last_device']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The one-time code that confirms an email address. Stored as a hash only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('email_code_hash')->nullable();
            $t->timestamp('email_code_expires_at')->nullable();
            $t->unsignedTinyInteger('email_code_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['email_code_hash', 'email_code_expires_at', 'email_code_attempts']);
        });
    }
};

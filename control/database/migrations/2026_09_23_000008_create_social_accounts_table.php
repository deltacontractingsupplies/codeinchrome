<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A Google or Apple identity linked to an account. The provider's id - not the
// email, which can change - is what signs the person in.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('provider', 20);
            $t->string('provider_user_id');
            $t->string('email')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'provider_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};

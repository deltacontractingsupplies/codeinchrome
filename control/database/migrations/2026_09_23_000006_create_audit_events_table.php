<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            // The account the event belongs to, and who did it. Usually the
            // same; the actor is null for the system (a billing webhook).
            // Not foreign keys with cascade: an account's history must not
            // vanish because a row it points at was deleted.
            $table->unsignedBigInteger('account_id')->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            // The site's NAME, not its id: it stays readable after the site
            // itself is gone, which is when it is most likely to be needed.
            $table->string('site')->nullable()->index();
            $table->string('action', 64)->index();
            $table->json('detail')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};

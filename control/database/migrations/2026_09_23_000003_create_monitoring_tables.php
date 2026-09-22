<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The latest state of every monitored thing, one row each. Checks are
        // not kept forever: what matters is the current state, the streak that
        // decides whether it is an outage, and the incidents.
        Schema::create('monitors', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();       // "host:h1", "site:shop", "host:h1:disk"
            $table->string('label');
            $table->boolean('up')->default(true);
            $table->unsignedInteger('fail_streak')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('detail')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('monitor_key')->index();
            $table->string('label');
            $table->text('detail');
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->boolean('alerted')->default(false);
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('monitors');
    }
};

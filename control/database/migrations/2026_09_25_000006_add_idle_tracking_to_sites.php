<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A free site nobody has visited or worked on for 30 days is paused, and its
 * owner can bring it back with one click (owner's decision, 2026-09-25;
 * sites:idle). paused_reason says which pause a site is in, so that one
 * click can undo an idle pause and never a trial, CPU or abuse one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('last_worked_at')->nullable();
            $table->timestamp('last_visit_at')->nullable();
            $table->timestamp('idle_warned_at')->nullable();
            $table->string('paused_reason', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['last_worked_at', 'last_visit_at', 'idle_warned_at', 'paused_reason']);
        });
    }
};

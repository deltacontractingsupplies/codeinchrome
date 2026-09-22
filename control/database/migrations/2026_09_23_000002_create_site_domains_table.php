<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // Unique across the whole platform: one domain, one site. A second
            // claim on a verified domain is refused outright.
            $table->string('domain')->unique();
            // What the customer must publish as a TXT record. Random per
            // claim, so a record left behind by a previous owner proves
            // nothing about the new one.
            $table->string('token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->text('last_check')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_domains');
    }
};

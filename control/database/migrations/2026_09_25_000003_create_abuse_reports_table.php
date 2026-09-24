<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Reports from anyone about a hosted site (App\Http\Controllers\AbuseReportController). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abuse_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('url', 2048);
            $table->string('reason', 40);
            $table->text('details')->nullable();
            $table->string('reporter_email')->nullable();
            // A keyed hash, not the address: enough to spot one person
            // flooding reports, without keeping visitors' IPs.
            $table->string('reporter_hash', 64)->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abuse_reports');
    }
};

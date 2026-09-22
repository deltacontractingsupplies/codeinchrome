<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('plan')->default('free')->after('email');
            $table->string('ls_customer_id')->nullable()->index()->after('plan');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ls_subscription_id')->unique();
            $table->string('ls_variant_id')->nullable();
            $table->string('plan');
            // Lemon Squeezy's own vocabulary, stored verbatim rather than
            // mapped to a boolean. "active", "past_due", "cancelled",
            // "expired" and "on_trial" are not two states, and squashing them
            // into one is how a past_due account silently keeps full service.
            $table->string('status');
            $table->timestamp('renews_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The site id is also a container name, a directory name and a DNS
            // label. Unique across the WHOLE fleet, not per user: two customers
            // cannot both own `shop`, because the subdomain is shared space.
            $table->string('site_id')->unique();
            $table->string('domain')->unique();
            $table->string('host');
            $table->unsignedInteger('port')->nullable();
            // provisioning | live | failed | deleting. Never a boolean: a site
            // that failed halfway is neither live nor absent, and the operator
            // needs to see which.
            $table->string('status')->default('provisioning');
            $table->text('last_error')->nullable();
            $table->string('cpu_limit');
            $table->string('memory_limit');
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            // Lemon Squeezy can deliver the same event more than once. Storing
            // the delivery id UNIQUE makes replay a no-op rather than a second
            // plan upgrade.
            $table->string('event_id')->unique();
            $table->string('event_name');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('sites');
        Schema::dropIfExists('subscriptions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['plan', 'ls_customer_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->ulid();
            $table->ulidMorphs('subscribable'); // subscribable_id + subscribable_type
            $table->foreignUlid('plan_id')->constrained()->cascadeOnDelete();
            $table->string('gateway')->index(); // paypal, xendit, google_play, apple
            $table->string('gateway_id')->nullable()->index(); // remote subscription ID
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable()->index();
            $table->timestamp('trial_ends_at')->nullable()->index();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['subscribable_id', 'subscribable_type', 'gateway_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
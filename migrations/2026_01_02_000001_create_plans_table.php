<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Imannms000\LaravelUnifiedSubscriptions\Enums\BillingInterval;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->ulid('id');
            $table->string('name');
            $table->string('slug')->unique(); // premium-monthly
            $table->string('tier')->index(); // premium
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('interval')->default(BillingInterval::MONTH->value);
            $table->unsignedInteger('interval_count')->default(1);
            $table->unsignedInteger('trial_days')->nullable();
            $table->unsignedInteger('grace_days')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['slug', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
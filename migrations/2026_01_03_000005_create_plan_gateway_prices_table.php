<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_gateway_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('plan_id')->constrained()->cascadeOnDelete();
            $table->string('gateway'); // paypal, xendit, google_play, apple
            // Core identifier (most important)
            $table->string('gateway_plan_id')->nullable();
            // Optional but very useful for Google & Apple
            $table->string('gateway_offer_id')->nullable();
            // Optional top-level (mostly for Google)
            $table->string('gateway_product_id')->nullable();
            // Optional: human-readable name from gateway (for admin UI)
            $table->string('gateway_plan_name')->nullable();
            $table->decimal('price', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->timestamps();

            // Indexes
            $table->index('gateway');                          // single column, already exists or add if missing
            
            // Single-column indexes (good for individual filters)
            $table->index('gateway_plan_id');
            $table->index('gateway_offer_id');
            $table->index('gateway_product_id');

            // Composite indexes (most important for real queries)
            $table->index(['gateway', 'gateway_plan_id'], 'idx_gateway_plan');           // Primary lookup: gateway + base plan
            $table->index(['gateway', 'gateway_plan_id', 'gateway_offer_id'], 'idx_gateway_plan_offer'); // Full match for Google/Apple
            
            // Optional: if you often filter by product too
            $table->index(['gateway', 'gateway_product_id'], 'idx_gateway_product');

            $table->unique(['plan_id', 'gateway', 'gateway_plan_id']);
            $table->index(['gateway', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_gateway_prices');
    }
};
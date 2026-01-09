<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_gateway_prices', function (Blueprint $table) {
            $table->ulid('id');
            $table->foreignUlid('plan_id')->constrained()->cascadeOnDelete();
            $table->string('gateway'); // paypal, xendit, google_play, apple
            $table->decimal('price', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->timestamps();

            $table->unique(['plan_id', 'gateway']);
            $table->index(['gateway', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_gateway_prices');
    }
};
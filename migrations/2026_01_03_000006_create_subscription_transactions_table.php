<?php

// database/migrations/2026_01_03_000006_create_subscription_transactions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('gateway');
            $table->string('gateway_transaction_id')->nullable()->index(); // e.g., PayPal order ID, Xendit invoice ID
            $table->string('type'); // payment, renewal, refund, failed, setup
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('status')->default('completed'); // completed, failed, refunded, pending
            $table->json('metadata')->nullable(); // raw gateway response snippet
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['subscription_id', 'type']);
            $table->index(['gateway', 'gateway_transaction_id']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_transactions');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_usage', function (Blueprint $table) {
            $table->ulid('id');
            $table->foreignUlid('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('feature_slug')->index();
            $table->unsignedBigInteger('used');
            $table->timestamp('used_at')->useCurrent();
            $table->timestamps();

            $table->index(['subscription_id', 'feature_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_usage');
    }
};
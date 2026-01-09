<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_features', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('plan_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->index();
            $table->string('name');
            $table->unsignedBigInteger('value'); // e.g., 100 API calls
            $table->boolean('resettable')->default(true);
            $table->timestamps();

            $table->unique(['plan_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};
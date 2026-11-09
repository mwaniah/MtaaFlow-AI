<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->text('description')->nullable();
            $table->integer('quantity')->default(0);
            $table->integer('low_stock_threshold')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['user_id', 'sku']);
            $table->index(['user_id', 'name', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

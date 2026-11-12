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
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('subtotal', 16, 4)->default(0)->change();
            $table->decimal('total', 16, 4)->default(0)->change();
            $table->uuid('submission_id')->nullable();
            $table->unique(['user_id', 'submission_id']);
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('unit_price', 16, 4)->change();
            $table->decimal('amount', 16, 4)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Precision cannot safely be reduced. Use a forward migration to preserve financial data.');
    }
};

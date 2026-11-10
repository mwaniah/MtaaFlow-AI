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
        Schema::table('payments', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->uuid('paypal_capture_request_id')->nullable()->unique();
            $table->text('paypal_approval_url')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Payment idempotency data cannot be discarded. Use a forward migration to preserve payment records.');
    }
};

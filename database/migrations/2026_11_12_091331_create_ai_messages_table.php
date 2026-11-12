<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('conversation_id')
                ->constrained('ai_conversations')
                ->cascadeOnDelete();

            $table->enum('role', ['user', 'assistant']);
            $table->longText('content');
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};

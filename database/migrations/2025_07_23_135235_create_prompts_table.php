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
        Schema::create('prompts', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_key');
            $table->string('ai_provider', 20); // openai, claude, cloud_ai
            $table->string('model', 100);
            $table->longText('prompt');
            $table->longText('response')->nullable();
            $table->json('parameters')->nullable(); // temperature, max_tokens, etc.
            $table->integer('tokens_used')->nullable();
            $table->decimal('cost', 10, 6)->nullable();
            $table->integer('response_time_ms')->nullable();
            $table->string('status', 20)->default('pending'); // pending, completed, failed
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['ticket_key', 'created_at']);
            $table->index(['ai_provider', 'status']);
            $table->index('created_at');
            
            // Foreign key
            $table->foreign('ticket_key')->references('key')->on('tickets')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prompts');
    }
};

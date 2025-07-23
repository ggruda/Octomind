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
        Schema::create('retry_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_key');
            $table->string('operation', 50); // jira_fetch, ai_generation, code_execution, pr_creation
            $table->integer('attempt_number');
            $table->integer('max_attempts');
            $table->string('status', 20); // pending, retrying, success, failed
            $table->text('error_message')->nullable();
            $table->json('context')->nullable();
            $table->integer('delay_seconds')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['ticket_key', 'operation']);
            $table->index(['status', 'next_attempt_at']);
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
        Schema::dropIfExists('retry_attempts');
    }
};

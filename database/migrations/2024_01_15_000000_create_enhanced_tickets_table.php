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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            
            // Jira-Informationen
            $table->string('jira_key')->unique();
            $table->string('summary');
            $table->text('description')->nullable();
            $table->string('jira_status');
            $table->string('priority')->default('Medium');
            $table->string('assignee')->nullable();
            $table->string('reporter');
            $table->string('repository_url')->nullable();
            $table->json('labels')->nullable();
            $table->timestamp('jira_created_at')->nullable();
            $table->timestamp('jira_updated_at')->nullable();
            $table->timestamp('fetched_at')->nullable();
            
            // Verarbeitungs-Status
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed'])->default('pending');
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processing_completed_at')->nullable();
            $table->integer('processing_duration_seconds')->nullable();
            
            // Git/GitHub-Informationen
            $table->string('branch_name')->nullable();
            $table->string('pr_url')->nullable();
            $table->integer('pr_number')->nullable();
            $table->string('commit_hash')->nullable();
            
            // AI-Informationen
            $table->string('ai_provider_used')->nullable();
            $table->float('complexity_score')->nullable();
            $table->json('required_skills')->nullable();
            
            // Fehlerbehandlung
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            
            // Standard Laravel Timestamps
            $table->timestamps();
            $table->softDeletes();
            
            // Indizes für Performance
            $table->index(['status', 'created_at']);
            $table->index(['jira_status']);
            $table->index(['processing_started_at']);
            $table->index(['complexity_score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
}; 
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
        Schema::create('project_repositories', function (Blueprint $table) {
            $table->id();
            
            // Foreign Keys
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('repository_id');
            
            // Projekt-spezifische Repository-Konfiguration
            $table->boolean('is_default')->default(false); // Standard-Repository für das Projekt
            $table->integer('priority')->default(1); // Priorität bei mehreren Repositories
            $table->string('branch_strategy')->default('feature'); // feature, hotfix, release
            $table->string('custom_branch_prefix')->nullable(); // Überschreibt Repository-Default
            
            // Ticket-Routing-Regeln
            $table->json('ticket_routing_rules')->nullable(); // Regeln wann welches Repository verwendet wird
            $table->json('component_mapping')->nullable(); // Jira-Komponenten zu Repository-Mapping
            $table->json('label_mapping')->nullable(); // Label-basiertes Routing
            
            // Statistiken
            $table->integer('tickets_processed')->default(0);
            $table->timestamp('last_ticket_processed_at')->nullable();
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Constraints
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->foreign('repository_id')->references('id')->on('repositories')->onDelete('cascade');
            
            // Unique constraint - ein Repository kann nur einmal pro Projekt verknüpft sein
            $table->unique(['project_id', 'repository_id']);
            
            // Indexes
            $table->index(['project_id', 'is_active']);
            $table->index(['repository_id', 'is_active']);
            $table->index(['is_default', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_repositories');
    }
}; 
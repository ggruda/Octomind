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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            
            // Jira-Projekt-Informationen
            $table->string('jira_key')->unique(); // z.B. "PROJ", "DEV", "SUPPORT"
            $table->string('name'); // Projekt-Name
            $table->text('description')->nullable();
            $table->string('jira_base_url'); // https://company.atlassian.net
            $table->string('project_type')->default('software'); // software, service_desk, etc.
            $table->string('project_category')->nullable();
            
            // Konfiguration
            $table->boolean('bot_enabled')->default(true);
            $table->string('required_label')->default('ai-bot'); // Label das Tickets haben müssen
            $table->boolean('require_unassigned')->default(true);
            $table->json('allowed_statuses')->default('["Open", "In Progress", "To Do"]');
            $table->integer('fetch_interval')->default(300); // Sekunden zwischen Ticket-Abrufen
            
            // Repository-Verknüpfung
            $table->unsignedBigInteger('default_repository_id')->nullable();
            
            // Metadaten
            $table->json('custom_fields_mapping')->nullable(); // Mapping von Jira Custom Fields
            $table->json('webhook_config')->nullable(); // Webhook-Konfiguration
            $table->timestamp('last_sync_at')->nullable();
            $table->integer('total_tickets_processed')->default(0);
            $table->integer('successful_tickets')->default(0);
            $table->integer('failed_tickets')->default(0);
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['jira_key', 'is_active']);
            $table->index(['bot_enabled', 'is_active']);
            $table->index('last_sync_at');
            
            // Foreign Key wird später hinzugefügt nach repositories-Tabelle
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
}; 
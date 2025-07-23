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
        Schema::create('ticket_todos', function (Blueprint $table) {
            $table->id();
            
            // Verknüpfung zum Hauptticket
            $table->foreignId('ticket_id')->constrained('tickets')->onDelete('cascade');
            
            // TODO-Informationen
            $table->string('title');
            $table->text('description');
            $table->integer('priority')->default(3); // 1-5 (1 = höchste Priorität)
            $table->decimal('estimated_hours', 5, 2)->default(2.0);
            $table->string('category')->default('backend'); // backend, frontend, database, testing, deployment, planning
            
            // Status und Fortschritt
            $table->enum('status', ['pending', 'in_progress', 'completed', 'blocked', 'cancelled'])->default('pending');
            $table->integer('order_index')->default(1); // Reihenfolge der Abarbeitung
            $table->decimal('actual_hours', 5, 2)->nullable(); // Tatsächlich verbrauchte Zeit
            
            // Abhängigkeiten und Kriterien (JSON)
            $table->json('dependencies')->nullable(); // Array von TODO-IDs oder -Titeln
            $table->json('acceptance_criteria')->nullable(); // Array von Akzeptanzkriterien
            
            // AI-Informationen
            $table->boolean('ai_generated')->default(false);
            $table->string('ai_provider_used')->nullable();
            $table->decimal('ai_confidence', 3, 2)->nullable(); // 0.00-1.00
            
            // Verarbeitungs-Zeitstempel
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('processing_duration_seconds')->nullable();
            
            // Git/GitHub-Informationen für dieses TODO
            $table->string('branch_name')->nullable();
            $table->string('commit_hash')->nullable();
            $table->text('code_changes')->nullable(); // Kurze Beschreibung der Änderungen
            
            // Fehlerbehandlung
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            
            $table->timestamps();
            
            // Indices für Performance
            $table->index(['ticket_id', 'status']);
            $table->index(['ticket_id', 'order_index']);
            $table->index(['status', 'priority']);
            $table->index(['category', 'status']);
            $table->index('ai_generated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_todos');
    }
}; 
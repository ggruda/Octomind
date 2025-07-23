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
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('repository_id')->constrained('repositories')->onDelete('cascade');
            
            // Pivot-spezifische Felder
            $table->boolean('is_default')->default(false);
            $table->integer('priority')->default(1);
            $table->boolean('bot_enabled')->default(true);
            
            $table->timestamps();
            
            // Eindeutige Kombination
            $table->unique(['project_id', 'repository_id']);
            
            // Indizes für Performance
            $table->index(['project_id', 'bot_enabled']);
            $table->index(['repository_id', 'is_default']);
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
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
        Schema::table('tickets', function (Blueprint $table) {
            // Neue Spalten für Projekt- und Repository-Referenzen
            $table->unsignedBigInteger('project_id')->nullable()->after('jira_key');
            $table->unsignedBigInteger('repository_id')->nullable()->after('project_id');
            
            // Repository-URL wird optional (wird aus DB-Referenz geholt)
            $table->string('repository_url')->nullable()->change();
            
            // Foreign Keys
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('set null');
            $table->foreign('repository_id')->references('id')->on('repositories')->onDelete('set null');
            
            // Indexes
            $table->index(['project_id', 'status']);
            $table->index(['repository_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Foreign Keys entfernen
            $table->dropForeign(['project_id']);
            $table->dropForeign(['repository_id']);
            
            // Spalten entfernen
            $table->dropColumn(['project_id', 'repository_id']);
            
            // Repository-URL wieder required machen
            $table->string('repository_url')->nullable(false)->change();
        });
    }
}; 
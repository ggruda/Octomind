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
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            
            // Repository-Identifikation
            $table->string('name'); // Repository-Name (z.B. "frontend", "backend")
            $table->string('full_name')->unique(); // owner/repo (z.B. "company/frontend")
            $table->string('owner'); // GitHub/GitLab User/Organization
            $table->text('description')->nullable();
            
            // Git-URLs
            $table->string('clone_url'); // HTTPS-URL für Klonen
            $table->string('ssh_url'); // SSH-URL für Bot-Operationen
            $table->string('web_url'); // Browser-URL
            
            // Provider-Informationen
            $table->enum('provider', ['github', 'gitlab', 'bitbucket', 'azure_devops'])->default('github');
            $table->string('provider_id')->nullable(); // Repository-ID beim Provider
            $table->string('default_branch')->default('main');
            
            // Repository-Konfiguration
            $table->boolean('is_private')->default(true);
            $table->boolean('bot_enabled')->default(true);
            $table->json('allowed_file_extensions')->default('["php", "js", "ts", "vue", "blade.php", "json", "yaml", "yml", "md"]');
            $table->json('forbidden_paths')->default('[".env", ".git", "vendor", "node_modules"]');
            $table->integer('max_file_size')->default(1048576); // 1MB in Bytes
            
            // Framework-Erkennung
            $table->string('framework_type')->nullable(); // laravel, nodejs, python, etc.
            $table->json('framework_config')->nullable(); // Framework-spezifische Konfiguration
            $table->string('package_manager')->nullable(); // composer, npm, pip, etc.
            
            // Branch-Management
            $table->string('branch_prefix')->default('octomind'); // Prefix für Bot-Branches
            $table->boolean('create_draft_prs')->default(true);
            $table->boolean('auto_merge_enabled')->default(false);
            $table->json('pr_template')->nullable(); // PR-Template-Konfiguration
            
            // SSH-Key-Management
            $table->boolean('ssh_key_deployed')->default(false);
            $table->string('deploy_key_fingerprint')->nullable();
            $table->timestamp('ssh_key_deployed_at')->nullable();
            
            // Statistiken
            $table->integer('total_commits')->default(0);
            $table->integer('total_prs_created')->default(0);
            $table->integer('total_prs_merged')->default(0);
            $table->timestamp('last_commit_at')->nullable();
            $table->timestamp('last_pr_created_at')->nullable();
            
            // Workspace-Management
            $table->string('local_path')->nullable(); // Pfad im Bot-Storage
            $table->timestamp('last_cloned_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('current_commit_hash')->nullable();
            
            // Status und Metadaten
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->json('webhook_config')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['owner', 'name']);
            $table->index(['provider', 'is_active']);
            $table->index(['bot_enabled', 'is_active']);
            $table->index('framework_type');
            $table->index('last_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
}; 
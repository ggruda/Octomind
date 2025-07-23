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
            $table->string('key')->unique(); // Jira ticket key (e.g., PROJ-123)
            $table->string('project_key', 50);
            $table->string('summary');
            $table->longText('description');
            $table->string('status', 50)->default('pending');
            $table->string('assignee')->nullable();
            $table->string('linked_repository')->nullable();
            $table->json('labels')->nullable();
            $table->json('components')->nullable();
            $table->string('priority', 20)->nullable();
            $table->string('issue_type', 50)->nullable();
            $table->json('custom_fields')->nullable();
            $table->json('attachments')->nullable();
            $table->json('comments')->nullable();
            $table->timestamp('jira_created_at')->nullable();
            $table->timestamp('jira_updated_at')->nullable();
            $table->timestamp('last_processed_at')->nullable();
            $table->integer('retry_count')->default(0);
            $table->text('error_message')->nullable();
            $table->string('pr_url')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['project_key', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('linked_repository');
            $table->index('last_processed_at');
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

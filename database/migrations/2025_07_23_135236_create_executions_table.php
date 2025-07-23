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
        Schema::create('executions', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_key');
            $table->string('repository');
            $table->string('branch_name');
            $table->string('action', 50); // create_file, edit_file, delete_file, run_command
            $table->string('file_path')->nullable();
            $table->longText('code_before')->nullable();
            $table->longText('code_after')->nullable();
            $table->text('command')->nullable();
            $table->longText('command_output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('status', 20)->default('pending'); // pending, executing, completed, failed
            $table->text('error_message')->nullable();
            $table->integer('execution_time_ms')->nullable();
            $table->boolean('simulation_mode')->default(false);
            $table->timestamps();
            
            // Indexes
            $table->index(['ticket_key', 'created_at']);
            $table->index(['repository', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('simulation_mode');
            
            // Foreign key
            $table->foreign('ticket_key')->references('key')->on('tickets')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('executions');
    }
};

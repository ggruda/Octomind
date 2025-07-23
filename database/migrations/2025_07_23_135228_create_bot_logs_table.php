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
        Schema::create('bot_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 20)->index();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();
            
            // Indexes for better performance
            $table->index(['level', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_logs');
    }
};

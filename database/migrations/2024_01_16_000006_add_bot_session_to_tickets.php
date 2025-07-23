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
            // Bot-Session-Verknüpfung
            $table->foreignId('bot_session_id')->nullable()->after('repository_id')
                  ->constrained('bot_sessions')->onDelete('set null');
            
            // Stunden-Tracking pro Ticket
            $table->decimal('hours_consumed', 8, 4)->nullable()->after('processing_duration_seconds');
            $table->decimal('hourly_rate', 8, 2)->nullable(); // Falls unterschiedliche Raten
            $table->decimal('cost_calculated', 8, 2)->nullable(); // Berechnete Kosten
            
            // Billing-Status
            $table->enum('billing_status', ['pending', 'calculated', 'billed'])->default('pending');
            $table->timestamp('billed_at')->nullable();
            
            // Index für Performance
            $table->index(['bot_session_id', 'billing_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['bot_session_id']);
            $table->dropColumn([
                'bot_session_id',
                'hours_consumed',
                'hourly_rate',
                'cost_calculated',
                'billing_status',
                'billed_at'
            ]);
        });
    }
}; 
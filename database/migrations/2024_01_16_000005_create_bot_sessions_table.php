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
        Schema::create('bot_sessions', function (Blueprint $table) {
            $table->id();
            
            // Session-Identifikation
            $table->string('session_id')->unique();
            $table->string('customer_email');
            $table->string('customer_name')->nullable();
            
            // Stunden-Management
            $table->decimal('purchased_hours', 8, 2); // Gekaufte Stunden
            $table->decimal('consumed_hours', 8, 2)->default(0); // Verbrauchte Stunden
            $table->decimal('remaining_hours', 8, 2); // Verbleibende Stunden
            
            // Session-Status
            $table->enum('status', ['active', 'paused', 'expired', 'cancelled'])->default('active');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            
            // Ticket-Verarbeitung
            $table->integer('tickets_processed')->default(0);
            $table->integer('tickets_successful')->default(0);
            $table->integer('tickets_failed')->default(0);
            
            // Benachrichtigungen
            $table->boolean('warning_75_sent')->default(false); // 75% verbraucht
            $table->boolean('warning_90_sent')->default(false); // 90% verbraucht
            $table->boolean('expiry_notification_sent')->default(false);
            
            // Konfiguration
            $table->json('bot_config')->nullable(); // Bot-spezifische Einstellungen
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indizes
            $table->index(['status', 'remaining_hours']);
            $table->index(['customer_email', 'status']);
            $table->index('last_activity_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_sessions');
    }
}; 
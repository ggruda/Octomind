<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Octomind Bot - Ticket Loading alle 2 Minuten
        $schedule->command('octomind:load-tickets')
                 ->everyTwoMinutes()
                 ->withoutOverlapping(5) // Max 5 Min Overlap-Schutz
                 ->runInBackground()
                 ->onFailure(function () {
                     \Log::error('Octomind Ticket-Loading fehlgeschlagen');
                 })
                 ->when(function () {
                     // Nur ausführen wenn aktive Bot-Sessions mit verbleibenden Stunden existieren
                     return \App\Models\BotSession::active()
                                                  ->where('remaining_hours', '>', 0)
                                                  ->exists();
                 });

        // Session-Cleanup - Abgelaufene Sessions bereinigen (täglich)
        $schedule->command('octomind:cleanup-sessions')
                 ->daily()
                 ->at('02:00')
                 ->withoutOverlapping()
                 ->onFailure(function () {
                     \Log::error('Octomind Session-Cleanup fehlgeschlagen');
                 });

        // Repository-Cleanup - Alte Workspaces bereinigen (wöchentlich)
        $schedule->command('octomind:cleanup-repositories')
                 ->weekly()
                 ->sundays()
                 ->at('03:00')
                 ->withoutOverlapping()
                 ->onFailure(function () {
                     \Log::error('Octomind Repository-Cleanup fehlgeschlagen');
                 });

        // Health-Check - Bot-Status prüfen (alle 5 Minuten)
        $schedule->command('octomind:health-check')
                 ->everyFiveMinutes()
                 ->withoutOverlapping(2)
                 ->runInBackground()
                 ->onFailure(function () {
                     \Log::error('Octomind Health-Check fehlgeschlagen');
                 });

        // Warnung-Emails prüfen (alle 10 Minuten)
        $schedule->command('octomind:check-warnings')
                 ->everyTenMinutes()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onFailure(function () {
                     \Log::error('Octomind Warning-Check fehlgeschlagen');
                 })
                 ->when(function () {
                     // Nur wenn aktive Sessions mit verbleibenden Stunden existieren
                     return \App\Models\BotSession::active()
                                                  ->where('remaining_hours', '>', 0)
                                                  ->exists();
                 });

        // Performance-Metriken sammeln (stündlich)
        $schedule->command('octomind:collect-metrics')
                 ->hourly()
                 ->withoutOverlapping()
                 ->onFailure(function () {
                     \Log::error('Octomind Metrics-Collection fehlgeschlagen');
                 });

        // Stunden-Ablauf prüfen und Sessions deaktivieren (alle 5 Minuten)
        $schedule->command('octomind:check-session-expiry')
                 ->everyFiveMinutes()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->onFailure(function () {
                     \Log::error('Octomind Session-Expiry-Check fehlgeschlagen');
                 });
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
} 
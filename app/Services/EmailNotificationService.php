<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use App\Services\LogService;
use App\Services\ConfigService;
use Exception;

class EmailNotificationService
{
    private LogService $logger;
    private ConfigService $config;

    public function __construct()
    {
        $this->logger = new LogService();
        $this->config = ConfigService::getInstance();
    }

    /**
     * Sendet Warnung bei Stunden-Verbrauch (75% oder 90%)
     */
    public function sendWarningEmail(string $customerEmail, int $percentage, array $sessionReport): bool
    {
        try {
            $subject = "⚠️ Octomind Bot - {$percentage}% Ihrer Stunden verbraucht";
            
            $content = $this->buildWarningEmailContent($percentage, $sessionReport);
            
            $this->sendEmail($customerEmail, $subject, $content);
            
            $this->logger->info("Warnung-Email ({$percentage}%) gesendet", [
                'customer_email' => $customerEmail,
                'session_id' => $sessionReport['session_id']
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->logger->error("Fehler beim Senden der Warnung-Email ({$percentage}%)", [
                'customer_email' => $customerEmail,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Sendet Email bei Stunden-Ablauf an Kunden
     */
    public function sendExpiryEmail(string $customerEmail, array $sessionReport): bool
    {
        try {
            $subject = "🛑 Octomind Bot - Ihre Stunden sind aufgebraucht";
            
            $content = $this->buildExpiryEmailContent($sessionReport);
            
            $this->sendEmail($customerEmail, $subject, $content);
            
            $this->logger->info('Expiry-Email an Kunden gesendet', [
                'customer_email' => $customerEmail,
                'session_id' => $sessionReport['session_id']
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->logger->error('Fehler beim Senden der Expiry-Email an Kunden', [
                'customer_email' => $customerEmail,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Sendet interne Benachrichtigung bei Stunden-Ablauf
     */
    public function sendInternalExpiryNotification(string $internalEmail, array $sessionReport): bool
    {
        try {
            $subject = "🔔 Octomind Bot - Kunden-Session abgelaufen: {$sessionReport['customer']['email']}";
            
            $content = $this->buildInternalExpiryContent($sessionReport);
            
            $this->sendEmail($internalEmail, $subject, $content);
            
            $this->logger->info('Interne Expiry-Benachrichtigung gesendet', [
                'internal_email' => $internalEmail,
                'customer_email' => $sessionReport['customer']['email'],
                'session_id' => $sessionReport['session_id']
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->logger->error('Fehler beim Senden der internen Expiry-Benachrichtigung', [
                'internal_email' => $internalEmail,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Erstellt Warnung-Email-Inhalt
     */
    private function buildWarningEmailContent(int $percentage, array $sessionReport): string
    {
        $customer = $sessionReport['customer'];
        $hours = $sessionReport['hours'];
        $tickets = $sessionReport['tickets'];
        $performance = $sessionReport['performance'];

        $greeting = $customer['name'] ? "Hallo {$customer['name']}" : "Hallo";

        $content = "{$greeting},\n\n";
        $content .= "Ihr Octomind Bot hat bereits {$percentage}% Ihrer gekauften Stunden verbraucht.\n\n";
        
        $content .= "📊 **Aktuelle Statistiken:**\n";
        $content .= "• Gekaufte Stunden: {$hours['purchased']} Stunden\n";
        $content .= "• Verbrauchte Stunden: {$hours['consumed']} Stunden\n";
        $content .= "• Verbleibende Stunden: {$hours['remaining']} Stunden\n";
        $content .= "• Verbrauch: {$hours['consumption_percentage']}%\n\n";
        
        $content .= "🎫 **Ticket-Performance:**\n";
        $content .= "• Verarbeitete Tickets: {$tickets['total_processed']}\n";
        $content .= "• Erfolgreich: {$tickets['successful']}\n";
        $content .= "• Fehlgeschlagen: {$tickets['failed']}\n";
        $content .= "• Erfolgsrate: {$tickets['success_rate']}%\n\n";
        
        if ($performance['avg_hours_per_ticket'] > 0) {
            $content .= "⏱️ **Geschätzte Restkapazität:**\n";
            $content .= "• Durchschnitt pro Ticket: {$performance['avg_hours_per_ticket']} Stunden\n";
            $content .= "• Geschätzte verbleibende Tickets: {$performance['estimated_remaining_tickets']}\n\n";
        }
        
        if ($percentage >= 90) {
            $content .= "⚠️ **Wichtiger Hinweis:**\n";
            $content .= "Sie haben nur noch wenige Stunden übrig. Bitte kontaktieren Sie uns, wenn Sie weitere Stunden benötigen.\n\n";
        }
        
        $content .= "Sie können jederzeit weitere Stunden buchen oder Ihre Session pausieren.\n\n";
        $content .= "Bei Fragen stehen wir Ihnen gerne zur Verfügung.\n\n";
        $content .= "Beste Grüße,\n";
        $content .= "Ihr Octomind Team";

        return $content;
    }

    /**
     * Erstellt Expiry-Email-Inhalt für Kunden
     */
    private function buildExpiryEmailContent(array $sessionReport): string
    {
        $customer = $sessionReport['customer'];
        $hours = $sessionReport['hours'];
        $tickets = $sessionReport['tickets'];
        $status = $sessionReport['status'];

        $greeting = $customer['name'] ? "Hallo {$customer['name']}" : "Hallo";

        $content = "{$greeting},\n\n";
        $content .= "Ihr Octomind Bot hat alle gekauften Stunden aufgebraucht und wurde automatisch deaktiviert.\n\n";
        
        $content .= "📊 **Finale Session-Statistiken:**\n";
        $content .= "• Session-ID: {$sessionReport['session_id']}\n";
        $content .= "• Gekaufte Stunden: {$hours['purchased']} Stunden\n";
        $content .= "• Verbrauchte Stunden: {$hours['consumed']} Stunden\n";
        $content .= "• Session-Dauer: " . $this->formatSessionDuration($status['started_at'], $status['expired_at']) . "\n\n";
        
        $content .= "🎫 **Ticket-Zusammenfassung:**\n";
        $content .= "• Insgesamt verarbeitet: {$tickets['total_processed']} Tickets\n";
        $content .= "• Erfolgreich abgeschlossen: {$tickets['successful']} Tickets\n";
        $content .= "• Fehlgeschlagen: {$tickets['failed']} Tickets\n";
        $content .= "• Erfolgsrate: {$tickets['success_rate']}%\n\n";
        
        if ($tickets['total_processed'] > 0) {
            $avgTime = round($hours['consumed'] / $tickets['total_processed'], 2);
            $content .= "⏱️ **Performance:**\n";
            $content .= "• Durchschnittliche Zeit pro Ticket: {$avgTime} Stunden\n\n";
        }
        
        $content .= "🔄 **Nächste Schritte:**\n";
        $content .= "• Möchten Sie weitere Stunden buchen? Kontaktieren Sie uns!\n";
        $content .= "• Alle Ihre Tickets und Pull Requests bleiben verfügbar\n";
        $content .= "• Sie können jederzeit eine neue Session starten\n\n";
        
        $content .= "Vielen Dank, dass Sie Octomind verwenden!\n\n";
        $content .= "Bei Fragen oder für eine neue Buchung stehen wir Ihnen gerne zur Verfügung.\n\n";
        $content .= "Beste Grüße,\n";
        $content .= "Ihr Octomind Team\n\n";
        $content .= "---\n";
        $content .= "Support: support@octomind.com\n";
        $content .= "Website: https://octomind.com";

        return $content;
    }

    /**
     * Erstellt interne Expiry-Benachrichtigung
     */
    private function buildInternalExpiryContent(array $sessionReport): string
    {
        $customer = $sessionReport['customer'];
        $hours = $sessionReport['hours'];
        $tickets = $sessionReport['tickets'];
        $performance = $sessionReport['performance'];
        $status = $sessionReport['status'];

        $content = "🔔 **Kunden-Session abgelaufen**\n\n";
        
        $content .= "👤 **Kunde:**\n";
        $content .= "• Email: {$customer['email']}\n";
        $content .= "• Name: " . ($customer['name'] ?? 'Nicht angegeben') . "\n";
        $content .= "• Session-ID: {$sessionReport['session_id']}\n\n";
        
        $content .= "⏰ **Session-Details:**\n";
        $content .= "• Gestartet: {$status['started_at']}\n";
        $content .= "• Beendet: {$status['expired_at']}\n";
        $content .= "• Dauer: " . $this->formatSessionDuration($status['started_at'], $status['expired_at']) . "\n\n";
        
        $content .= "💰 **Stunden-Verbrauch:**\n";
        $content .= "• Gekauft: {$hours['purchased']} Stunden\n";
        $content .= "• Verbraucht: {$hours['consumed']} Stunden\n";
        $content .= "• Verbrauchsrate: {$hours['consumption_percentage']}%\n\n";
        
        $content .= "🎫 **Ticket-Performance:**\n";
        $content .= "• Verarbeitet: {$tickets['total_processed']} Tickets\n";
        $content .= "• Erfolgreich: {$tickets['successful']} ({$tickets['success_rate']}%)\n";
        $content .= "• Fehlgeschlagen: {$tickets['failed']}\n";
        $content .= "• Ø Zeit/Ticket: {$performance['avg_hours_per_ticket']} Stunden\n\n";
        
        $content .= "📈 **Empfohlene Aktionen:**\n";
        
        if ($tickets['success_rate'] < 80) {
            $content .= "⚠️ Niedrige Erfolgsrate - Bot-Konfiguration prüfen\n";
        }
        
        if ($performance['avg_hours_per_ticket'] > 1) {
            $content .= "⚠️ Hoher Zeitverbrauch pro Ticket - Optimierung prüfen\n";
        }
        
        if ($hours['purchased'] >= 10) {
            $content .= "💎 Premium-Kunde - Follow-up für weitere Buchung\n";
        }
        
        $content .= "📧 Kunden-Email bereits versendet\n\n";
        
        $content .= "---\n";
        $content .= "Octomind Bot Management System";

        return $content;
    }

    /**
     * Sendet Email über Laravel Mail
     */
    private function sendEmail(string $to, string $subject, string $content): void
    {
        Mail::raw($content, function ($message) use ($to, $subject) {
            $message->to($to)
                   ->subject($subject)
                   ->from(
                       $this->config->get('email.from_address', 'noreply@octomind.com'),
                       $this->config->get('email.from_name', 'Octomind Bot')
                   );
        });
    }

    /**
     * Formatiert Session-Dauer
     */
    private function formatSessionDuration(?string $startedAt, ?string $expiredAt): string
    {
        if (!$startedAt || !$expiredAt) {
            return 'Unbekannt';
        }

        try {
            $start = new \DateTime($startedAt);
            $end = new \DateTime($expiredAt);
            $interval = $start->diff($end);

            $parts = [];
            
            if ($interval->d > 0) {
                $parts[] = $interval->d . ' Tag' . ($interval->d > 1 ? 'e' : '');
            }
            
            if ($interval->h > 0) {
                $parts[] = $interval->h . ' Stunde' . ($interval->h > 1 ? 'n' : '');
            }
            
            if ($interval->i > 0) {
                $parts[] = $interval->i . ' Minute' . ($interval->i > 1 ? 'n' : '');
            }

            return empty($parts) ? 'Weniger als 1 Minute' : implode(', ', $parts);

        } catch (Exception $e) {
            return 'Unbekannt';
        }
    }

    /**
     * Sendet Test-Email
     */
    public function sendTestEmail(string $to, string $type = 'warning'): bool
    {
        try {
            $mockReport = [
                'session_id' => 'session_test_' . time(),
                'customer' => [
                    'email' => $to,
                    'name' => 'Test User'
                ],
                'hours' => [
                    'purchased' => 10.0,
                    'consumed' => $type === 'warning' ? 7.5 : 10.0,
                    'remaining' => $type === 'warning' ? 2.5 : 0.0,
                    'consumption_percentage' => $type === 'warning' ? 75.0 : 100.0
                ],
                'tickets' => [
                    'total_processed' => 15,
                    'successful' => 13,
                    'failed' => 2,
                    'success_rate' => 86.67
                ],
                'performance' => [
                    'avg_hours_per_ticket' => 0.5,
                    'estimated_remaining_tickets' => $type === 'warning' ? 5 : 0
                ],
                'status' => [
                    'current' => $type === 'warning' ? 'active' : 'expired',
                    'started_at' => now()->subHours(5)->toISOString(),
                    'expired_at' => $type === 'warning' ? null : now()->toISOString()
                ]
            ];

            if ($type === 'warning') {
                return $this->sendWarningEmail($to, 75, $mockReport);
            } else {
                return $this->sendExpiryEmail($to, $mockReport);
            }

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Senden der Test-Email', [
                'to' => $to,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
} 
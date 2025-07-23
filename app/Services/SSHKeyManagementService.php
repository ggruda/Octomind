<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\File;

class SSHKeyManagementService
{
    private ConfigService $config;
    private LogService $logger;
    private string $sshDir;
    private string $privateKeyPath;
    private string $publicKeyPath;

    public function __construct()
    {
        $this->config = ConfigService::getInstance();
        $this->logger = new LogService();
        
        // SSH-Verzeichnis im Bot-Storage
        $this->sshDir = storage_path('app/ssh');
        $this->privateKeyPath = $this->sshDir . '/octomind_bot_rsa';
        $this->publicKeyPath = $this->sshDir . '/octomind_bot_rsa.pub';
    }

    /**
     * Initialisiert SSH-Keys für den Bot
     */
    public function initializeSSHKeys(): array
    {
        $this->logger->info('Initialisiere SSH-Keys für Octomind Bot');

        try {
            // SSH-Verzeichnis erstellen
            $this->createSSHDirectory();
            
            // Prüfen ob Keys bereits existieren
            if ($this->keysExist()) {
                $this->logger->info('SSH-Keys bereits vorhanden, überspringe Generierung');
                return [
                    'success' => true,
                    'action' => 'existing',
                    'public_key' => $this->getPublicKey(),
                    'fingerprint' => $this->getKeyFingerprint()
                ];
            }

            // Neue SSH-Keys generieren
            $this->generateSSHKeys();
            
            // SSH-Konfiguration einrichten
            $this->configureSSH();
            
            // Berechtigungen setzen
            $this->setCorrectPermissions();

            $publicKey = $this->getPublicKey();
            $fingerprint = $this->getKeyFingerprint();

            $this->logger->info('SSH-Keys erfolgreich erstellt', [
                'public_key_path' => $this->publicKeyPath,
                'fingerprint' => $fingerprint
            ]);

            return [
                'success' => true,
                'action' => 'generated',
                'public_key' => $publicKey,
                'fingerprint' => $fingerprint,
                'instructions' => $this->getDeploymentInstructions()
            ];

        } catch (Exception $e) {
            $this->logger->error('SSH-Key-Initialisierung fehlgeschlagen', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Erstellt SSH-Verzeichnis mit korrekten Berechtigungen
     */
    private function createSSHDirectory(): void
    {
        if (!File::exists($this->sshDir)) {
            File::makeDirectory($this->sshDir, 0700, true);
            $this->logger->debug('SSH-Verzeichnis erstellt', ['path' => $this->sshDir]);
        }
    }

    /**
     * Prüft ob SSH-Keys bereits existieren
     */
    private function keysExist(): bool
    {
        return File::exists($this->privateKeyPath) && File::exists($this->publicKeyPath);
    }

    /**
     * Generiert neue SSH-Keys
     */
    private function generateSSHKeys(): void
    {
        $this->logger->info('Generiere neue SSH-Keys');

        $botEmail = $this->config->get('repository.commit_author_email', 'bot@octomind.com');
        $keyComment = "octomind-bot@" . gethostname();

        // SSH-Key generieren
        $command = sprintf(
            'ssh-keygen -t rsa -b 4096 -f "%s" -N "" -C "%s" 2>&1',
            $this->privateKeyPath,
            $keyComment
        );

        $output = shell_exec($command);
        
        if (!File::exists($this->privateKeyPath) || !File::exists($this->publicKeyPath)) {
            throw new Exception("SSH-Key-Generierung fehlgeschlagen: {$output}");
        }

        $this->logger->debug('SSH-Keys generiert', [
            'private_key' => $this->privateKeyPath,
            'public_key' => $this->publicKeyPath,
            'comment' => $keyComment
        ]);
    }

    /**
     * Konfiguriert SSH für Git-Operationen
     */
    private function configureSSH(): void
    {
        $sshConfigPath = $this->sshDir . '/config';
        
        $sshConfig = "# Octomind Bot SSH Configuration\n";
        $sshConfig .= "Host github.com\n";
        $sshConfig .= "    HostName github.com\n";
        $sshConfig .= "    User git\n";
        $sshConfig .= "    IdentityFile {$this->privateKeyPath}\n";
        $sshConfig .= "    IdentitiesOnly yes\n";
        $sshConfig .= "    StrictHostKeyChecking no\n";
        $sshConfig .= "    UserKnownHostsFile /dev/null\n\n";
        
        $sshConfig .= "Host gitlab.com\n";
        $sshConfig .= "    HostName gitlab.com\n";
        $sshConfig .= "    User git\n";
        $sshConfig .= "    IdentityFile {$this->privateKeyPath}\n";
        $sshConfig .= "    IdentitiesOnly yes\n";
        $sshConfig .= "    StrictHostKeyChecking no\n";
        $sshConfig .= "    UserKnownHostsFile /dev/null\n\n";
        
        $sshConfig .= "Host bitbucket.org\n";
        $sshConfig .= "    HostName bitbucket.org\n";
        $sshConfig .= "    User git\n";
        $sshConfig .= "    IdentityFile {$this->privateKeyPath}\n";
        $sshConfig .= "    IdentitiesOnly yes\n";
        $sshConfig .= "    StrictHostKeyChecking no\n";
        $sshConfig .= "    UserKnownHostsFile /dev/null\n";

        File::put($sshConfigPath, $sshConfig);
        chmod($sshConfigPath, 0600);

        $this->logger->debug('SSH-Konfiguration erstellt', ['path' => $sshConfigPath]);
    }

    /**
     * Setzt korrekte Dateiberechtigungen für SSH-Keys
     */
    private function setCorrectPermissions(): void
    {
        // Private Key: nur für Owner lesbar
        chmod($this->privateKeyPath, 0600);
        
        // Public Key: für alle lesbar
        chmod($this->publicKeyPath, 0644);
        
        // SSH-Verzeichnis: nur für Owner zugänglich
        chmod($this->sshDir, 0700);

        $this->logger->debug('SSH-Berechtigungen gesetzt');
    }

    /**
     * Holt den öffentlichen Schlüssel
     */
    public function getPublicKey(): string
    {
        if (!File::exists($this->publicKeyPath)) {
            throw new Exception('Öffentlicher SSH-Key nicht gefunden');
        }

        return trim(File::get($this->publicKeyPath));
    }

    /**
     * Berechnet Fingerprint des SSH-Keys
     */
    public function getKeyFingerprint(): string
    {
        if (!File::exists($this->publicKeyPath)) {
            throw new Exception('Öffentlicher SSH-Key nicht gefunden');
        }

        $command = "ssh-keygen -lf \"{$this->publicKeyPath}\" 2>&1";
        $output = shell_exec($command);
        
        if (preg_match('/SHA256:([^\s]+)/', $output, $matches)) {
            return $matches[1];
        }
        
        // Fallback für MD5
        if (preg_match('/([a-f0-9:]{47})/', $output, $matches)) {
            return $matches[1];
        }

        return 'unknown';
    }

    /**
     * Testet SSH-Verbindung zu Git-Providern
     */
    public function testSSHConnections(): array
    {
        $results = [];
        $providers = ['github.com', 'gitlab.com', 'bitbucket.org'];

        foreach ($providers as $provider) {
            $this->logger->debug("Teste SSH-Verbindung zu {$provider}");
            
            $command = "ssh -o ConnectTimeout=10 -o BatchMode=yes -i \"{$this->privateKeyPath}\" git@{$provider} 2>&1";
            $output = shell_exec($command);
            
            // GitHub: "Hi username! You've successfully authenticated"
            // GitLab: "Welcome to GitLab"
            // Bitbucket: "authenticated via a deploy key"
            $success = (
                str_contains($output, 'successfully authenticated') ||
                str_contains($output, 'Welcome to GitLab') ||
                str_contains($output, 'authenticated via') ||
                str_contains($output, 'Hi ') // GitHub greeting
            );

            $results[$provider] = [
                'success' => $success,
                'output' => trim($output),
                'message' => $success ? 'SSH-Verbindung erfolgreich' : 'SSH-Verbindung fehlgeschlagen'
            ];
        }

        return $results;
    }

    /**
     * Konfiguriert Git für SSH-Nutzung
     */
    public function configureGitForSSH(): void
    {
        // SSH-Umgebungsvariablen für Git setzen
        putenv("GIT_SSH_COMMAND=ssh -i {$this->privateKeyPath} -o StrictHostKeyChecking=no");
        putenv("SSH_AUTH_SOCK="); // Disable SSH agent
        
        $this->logger->debug('Git für SSH konfiguriert');
    }

    /**
     * Konvertiert HTTPS-URL zu SSH-URL
     */
    public function convertToSSHUrl(string $httpsUrl): string
    {
        // GitHub
        if (preg_match('/https:\/\/github\.com\/([^\/]+)\/([^\/]+)\.git/', $httpsUrl, $matches)) {
            return "git@github.com:{$matches[1]}/{$matches[2]}.git";
        }
        
        // GitLab
        if (preg_match('/https:\/\/gitlab\.com\/([^\/]+)\/([^\/]+)\.git/', $httpsUrl, $matches)) {
            return "git@gitlab.com:{$matches[1]}/{$matches[2]}.git";
        }
        
        // Bitbucket
        if (preg_match('/https:\/\/bitbucket\.org\/([^\/]+)\/([^\/]+)\.git/', $httpsUrl, $matches)) {
            return "git@bitbucket.org:{$matches[1]}/{$matches[2]}.git";
        }

        // Fallback: Original URL zurückgeben
        return $httpsUrl;
    }

    /**
     * Führt Git-Befehl mit SSH aus
     */
    public function runGitCommandWithSSH(string $workingDir, string $command): string
    {
        // SSH-Umgebung für diesen Befehl setzen
        $sshCommand = "ssh -i {$this->privateKeyPath} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null";
        $fullCommand = "cd \"{$workingDir}\" && GIT_SSH_COMMAND=\"{$sshCommand}\" git {$command}";
        
        $this->logger->debug('Führe Git-Befehl mit SSH aus', [
            'command' => $command,
            'working_dir' => $workingDir
        ]);

        $output = [];
        $returnCode = 0;
        
        exec($fullCommand . ' 2>&1', $output, $returnCode);
        
        $outputString = implode("\n", $output);
        
        if ($returnCode !== 0) {
            throw new Exception("Git-SSH-Befehl fehlgeschlagen: {$command}\nOutput: {$outputString}");
        }

        return $outputString;
    }

    /**
     * Generiert Deployment-Anweisungen für den öffentlichen Schlüssel
     */
    private function getDeploymentInstructions(): array
    {
        $publicKey = $this->getPublicKey();
        
        return [
            'github' => [
                'title' => 'GitHub Deploy Key hinzufügen',
                'steps' => [
                    '1. Gehe zu deinem Repository auf GitHub',
                    '2. Klicke auf Settings → Deploy keys',
                    '3. Klicke "Add deploy key"',
                    '4. Titel: "Octomind Bot"',
                    '5. Füge den Public Key ein (siehe unten)',
                    '6. Aktiviere "Allow write access"',
                    '7. Klicke "Add key"'
                ],
                'public_key' => $publicKey
            ],
            'gitlab' => [
                'title' => 'GitLab Deploy Key hinzufügen',
                'steps' => [
                    '1. Gehe zu deinem Repository auf GitLab',
                    '2. Klicke auf Settings → Repository → Deploy Keys',
                    '3. Titel: "Octomind Bot"',
                    '4. Füge den Public Key ein (siehe unten)',
                    '5. Aktiviere "Grant write permissions to this key"',
                    '6. Klicke "Add key"'
                ],
                'public_key' => $publicKey
            ],
            'bitbucket' => [
                'title' => 'Bitbucket Access Key hinzufügen',
                'steps' => [
                    '1. Gehe zu deinem Repository auf Bitbucket',
                    '2. Klicke auf Repository settings → Access keys',
                    '3. Klicke "Add key"',
                    '4. Label: "Octomind Bot"',
                    '5. Füge den Public Key ein (siehe unten)',
                    '6. Klicke "Add key"'
                ],
                'public_key' => $publicKey
            ]
        ];
    }

    /**
     * Prüft ob SSH-Keys konfiguriert und bereit sind
     */
    public function isConfigured(): bool
    {
        return $this->keysExist() && 
               File::exists($this->sshDir . '/config') &&
               is_readable($this->privateKeyPath);
    }

    /**
     * Rotiert SSH-Keys (generiert neue)
     */
    public function rotateKeys(): array
    {
        $this->logger->info('Rotiere SSH-Keys');

        try {
            // Alte Keys sichern
            if ($this->keysExist()) {
                $backupDir = $this->sshDir . '/backup_' . date('Y-m-d_H-i-s');
                File::makeDirectory($backupDir, 0700);
                
                File::move($this->privateKeyPath, $backupDir . '/octomind_bot_rsa');
                File::move($this->publicKeyPath, $backupDir . '/octomind_bot_rsa.pub');
                
                $this->logger->info('Alte Keys gesichert', ['backup_dir' => $backupDir]);
            }

            // Neue Keys generieren
            return $this->initializeSSHKeys();

        } catch (Exception $e) {
            $this->logger->error('Key-Rotation fehlgeschlagen', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Gibt SSH-Key-Status zurück
     */
    public function getStatus(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'keys_exist' => $this->keysExist(),
            'private_key_path' => $this->privateKeyPath,
            'public_key_path' => $this->publicKeyPath,
            'ssh_dir' => $this->sshDir,
            'public_key' => $this->keysExist() ? $this->getPublicKey() : null,
            'fingerprint' => $this->keysExist() ? $this->getKeyFingerprint() : null
        ];
    }
} 
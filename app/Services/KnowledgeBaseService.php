<?php

namespace App\Services;

use App\DTOs\TicketDTO;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Exception;

class KnowledgeBaseService
{
    private ConfigService $config;
    private LogService $logger;
    private string $projectPath;

    public function __construct()
    {
        $this->config = ConfigService::getInstance();
        $this->logger = new LogService();
        $this->projectPath = base_path();
    }

    /**
     * Aktualisiert die Knowledge-Base vor der Ticket-Verarbeitung
     */
    public function updateKnowledgeBase(TicketDTO $ticket): array
    {
        $this->logger->info('Aktualisiere Knowledge-Base für Ticket', [
            'ticket_key' => $ticket->key
        ]);

        try {
            // 1. Git-Status und aktuelle Änderungen prüfen
            $gitStatus = $this->getGitStatus();
            
            // 2. Projekt-Struktur analysieren
            $projectStructure = $this->analyzeProjectStructure();
            
            // 3. Aktuelle Konfiguration erfassen
            $currentConfig = $this->getCurrentConfiguration();
            
            // 4. Abhängigkeiten analysieren
            $dependencies = $this->analyzeDependencies();
            
            // 5. Code-Patterns und Konventionen extrahieren
            $codePatterns = $this->extractCodePatterns();
            
            // 6. Repository-spezifische Informationen
            $repoInfo = $this->getRepositorySpecificInfo($ticket);

            $knowledgeBase = [
                'updated_at' => now()->toISOString(),
                'ticket_key' => $ticket->key,
                'git_status' => $gitStatus,
                'project_structure' => $projectStructure,
                'configuration' => $currentConfig,
                'dependencies' => $dependencies,
                'code_patterns' => $codePatterns,
                'repository_info' => $repoInfo,
                'recent_changes' => $this->getRecentChanges()
            ];

            // Cache für Performance
            Cache::put("knowledge_base_{$ticket->key}", $knowledgeBase, 3600);

            $this->logger->info('Knowledge-Base erfolgreich aktualisiert', [
                'ticket_key' => $ticket->key,
                'sections' => array_keys($knowledgeBase)
            ]);

            return $knowledgeBase;

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Aktualisieren der Knowledge-Base', [
                'ticket_key' => $ticket->key,
                'error' => $e->getMessage()
            ]);

            return [
                'error' => $e->getMessage(),
                'updated_at' => now()->toISOString()
            ];
        }
    }

    /**
     * Holt aktuellen Git-Status mit Diff-Informationen
     */
    private function getGitStatus(): array
    {
        try {
            // Git Status
            $status = $this->runGitCommand('status --porcelain');
            $statusLines = array_filter(explode("\n", $status));

            // Aktuelle Branch
            $currentBranch = trim($this->runGitCommand('branch --show-current'));

            // Letzte Commits
            $recentCommits = $this->runGitCommand('log --oneline -10');

            // Uncommitted Changes
            $uncommittedChanges = [];
            if (!empty($statusLines)) {
                $diff = $this->runGitCommand('diff');
                $uncommittedChanges = [
                    'files' => $statusLines,
                    'diff' => $diff
                ];
            }

            // Remote Status
            $this->runGitCommand('fetch --dry-run', false); // Silent fetch
            $aheadBehind = $this->runGitCommand('rev-list --count --left-right @{upstream}...HEAD 2>/dev/null || echo "0	0"');
            list($behind, $ahead) = explode("\t", trim($aheadBehind));

            return [
                'current_branch' => $currentBranch,
                'clean_working_tree' => empty($statusLines),
                'uncommitted_changes' => $uncommittedChanges,
                'recent_commits' => explode("\n", trim($recentCommits)),
                'remote_status' => [
                    'ahead' => (int)$ahead,
                    'behind' => (int)$behind
                ]
            ];

        } catch (Exception $e) {
            $this->logger->warning('Git-Status konnte nicht abgerufen werden', [
                'error' => $e->getMessage()
            ]);

            return [
                'error' => $e->getMessage(),
                'current_branch' => 'unknown'
            ];
        }
    }

    /**
     * Analysiert die aktuelle Projekt-Struktur
     */
    private function analyzeProjectStructure(): array
    {
        try {
            $structure = [
                'framework' => $this->detectFramework(),
                'directories' => $this->getDirectoryStructure(),
                'important_files' => $this->getImportantFiles(),
                'file_counts' => $this->getFileTypeCounts()
            ];

            return $structure;

        } catch (Exception $e) {
            $this->logger->warning('Projekt-Struktur-Analyse fehlgeschlagen', [
                'error' => $e->getMessage()
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Erkennt das verwendete Framework
     */
    private function detectFramework(): array
    {
        $frameworks = [];

        // Laravel
        if (File::exists($this->projectPath . '/artisan') && 
            File::exists($this->projectPath . '/composer.json')) {
            $composer = json_decode(File::get($this->projectPath . '/composer.json'), true);
            if (isset($composer['require']['laravel/framework'])) {
                $frameworks['laravel'] = $composer['require']['laravel/framework'];
            }
        }

        // Node.js/NPM
        if (File::exists($this->projectPath . '/package.json')) {
            $package = json_decode(File::get($this->projectPath . '/package.json'), true);
            $frameworks['nodejs'] = [
                'name' => $package['name'] ?? 'unknown',
                'version' => $package['version'] ?? 'unknown',
                'dependencies' => array_keys($package['dependencies'] ?? [])
            ];
        }

        // Docker
        if (File::exists($this->projectPath . '/Dockerfile') || 
            File::exists($this->projectPath . '/docker-compose.yml')) {
            $frameworks['docker'] = true;
        }

        return $frameworks;
    }

    /**
     * Holt wichtige Verzeichnisse
     */
    private function getDirectoryStructure(): array
    {
        $importantDirs = ['app', 'config', 'database', 'resources', 'routes', 'tests', 'public'];
        $structure = [];

        foreach ($importantDirs as $dir) {
            $path = $this->projectPath . '/' . $dir;
            if (File::isDirectory($path)) {
                $structure[$dir] = [
                    'exists' => true,
                    'files_count' => count(File::allFiles($path)),
                    'subdirectories' => array_map('basename', File::directories($path))
                ];
            }
        }

        return $structure;
    }

    /**
     * Identifiziert wichtige Konfigurationsdateien
     */
    private function getImportantFiles(): array
    {
        $importantFiles = [
            '.env' => 'Environment Configuration',
            'composer.json' => 'PHP Dependencies',
            'package.json' => 'Node.js Dependencies',
            'Dockerfile' => 'Docker Configuration',
            'docker-compose.yml' => 'Docker Compose',
            'README.md' => 'Project Documentation',
            '.gitignore' => 'Git Ignore Rules'
        ];

        $files = [];
        foreach ($importantFiles as $file => $description) {
            $path = $this->projectPath . '/' . $file;
            if (File::exists($path)) {
                $files[$file] = [
                    'exists' => true,
                    'description' => $description,
                    'size' => File::size($path),
                    'modified' => File::lastModified($path)
                ];
            }
        }

        return $files;
    }

    /**
     * Zählt Dateitypen im Projekt
     */
    private function getFileTypeCounts(): array
    {
        $extensions = ['php', 'js', 'vue', 'blade.php', 'css', 'scss', 'json', 'md'];
        $counts = [];

        foreach ($extensions as $ext) {
            $pattern = $this->projectPath . '/**/*.' . $ext;
            $files = glob($pattern, GLOB_BRACE);
            $counts[$ext] = count($files);
        }

        return $counts;
    }

    /**
     * Holt aktuelle Konfiguration
     */
    private function getCurrentConfiguration(): array
    {
        try {
            $config = [
                'app' => [
                    'name' => config('app.name'),
                    'env' => config('app.env'),
                    'debug' => config('app.debug'),
                    'url' => config('app.url')
                ],
                'database' => [
                    'default' => config('database.default'),
                    'connections' => array_keys(config('database.connections', []))
                ],
                'octomind' => $this->config->getAllSettings()
            ];

            return $config;

        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Analysiert Projekt-Abhängigkeiten
     */
    private function analyzeDependencies(): array
    {
        $dependencies = [];

        // Composer Dependencies
        if (File::exists($this->projectPath . '/composer.json')) {
            $composer = json_decode(File::get($this->projectPath . '/composer.json'), true);
            $dependencies['php'] = [
                'require' => $composer['require'] ?? [],
                'require_dev' => $composer['require-dev'] ?? []
            ];
        }

        // NPM Dependencies
        if (File::exists($this->projectPath . '/package.json')) {
            $package = json_decode(File::get($this->projectPath . '/package.json'), true);
            $dependencies['nodejs'] = [
                'dependencies' => $package['dependencies'] ?? [],
                'devDependencies' => $package['devDependencies'] ?? []
            ];
        }

        return $dependencies;
    }

    /**
     * Extrahiert Code-Patterns und Konventionen
     */
    private function extractCodePatterns(): array
    {
        try {
            $patterns = [
                'naming_conventions' => $this->analyzeNamingConventions(),
                'architecture_patterns' => $this->detectArchitecturePatterns(),
                'code_style' => $this->analyzeCodeStyle()
            ];

            return $patterns;

        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Analysiert Naming-Konventionen
     */
    private function analyzeNamingConventions(): array
    {
        $conventions = [];

        // Controller Naming
        $controllers = glob($this->projectPath . '/app/Http/Controllers/*.php');
        if (!empty($controllers)) {
            $controllerNames = array_map(function($path) {
                return basename($path, '.php');
            }, $controllers);
            
            $conventions['controllers'] = [
                'count' => count($controllerNames),
                'pattern' => 'PascalCase with Controller suffix',
                'examples' => array_slice($controllerNames, 0, 3)
            ];
        }

        // Model Naming
        $models = glob($this->projectPath . '/app/Models/*.php');
        if (!empty($models)) {
            $modelNames = array_map(function($path) {
                return basename($path, '.php');
            }, $models);
            
            $conventions['models'] = [
                'count' => count($modelNames),
                'pattern' => 'PascalCase singular nouns',
                'examples' => array_slice($modelNames, 0, 3)
            ];
        }

        return $conventions;
    }

    /**
     * Erkennt Architektur-Patterns
     */
    private function detectArchitecturePatterns(): array
    {
        $patterns = [];

        // Service Layer Pattern
        if (File::isDirectory($this->projectPath . '/app/Services')) {
            $services = glob($this->projectPath . '/app/Services/*.php');
            $patterns['service_layer'] = [
                'detected' => true,
                'services_count' => count($services)
            ];
        }

        // Repository Pattern
        if (File::isDirectory($this->projectPath . '/app/Repositories')) {
            $patterns['repository'] = ['detected' => true];
        }

        // DTO Pattern
        if (File::isDirectory($this->projectPath . '/app/DTOs')) {
            $patterns['dto'] = ['detected' => true];
        }

        return $patterns;
    }

    /**
     * Analysiert Code-Style
     */
    private function analyzeCodeStyle(): array
    {
        $style = [];

        // PSR Standards
        if (File::exists($this->projectPath . '/composer.json')) {
            $composer = json_decode(File::get($this->projectPath . '/composer.json'), true);
            $style['psr_autoload'] = isset($composer['autoload']['psr-4']);
        }

        // Code Formatting Tools
        $style['formatting_tools'] = [
            'php_cs_fixer' => File::exists($this->projectPath . '/.php_cs_fixer.php'),
            'phpstan' => File::exists($this->projectPath . '/phpstan.neon'),
            'phpunit' => File::exists($this->projectPath . '/phpunit.xml')
        ];

        return $style;
    }

    /**
     * Holt Repository-spezifische Informationen
     */
    private function getRepositorySpecificInfo(TicketDTO $ticket): array
    {
        $repoInfo = $ticket->getRepositoryInfo();
        
        if (!$repoInfo) {
            return ['error' => 'Kein Repository verknüpft'];
        }

        try {
            // README-Inhalt
            $readme = '';
            $readmeFiles = ['README.md', 'readme.md', 'README.txt', 'readme.txt'];
            foreach ($readmeFiles as $file) {
                if (File::exists($this->projectPath . '/' . $file)) {
                    $readme = File::get($this->projectPath . '/' . $file);
                    break;
                }
            }

            return [
                'repository' => $repoInfo,
                'readme_content' => substr($readme, 0, 2000), // Erste 2000 Zeichen
                'has_documentation' => !empty($readme)
            ];

        } catch (Exception $e) {
            return [
                'repository' => $repoInfo,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Holt kürzliche Änderungen
     */
    private function getRecentChanges(): array
    {
        try {
            // Letzte 5 Commits mit Details
            $commits = $this->runGitCommand('log --oneline --stat -5');
            
            // Geänderte Dateien in letzten 24h
            $recentFiles = $this->runGitCommand('log --since="24 hours ago" --name-only --pretty=format: | sort | uniq');
            
            return [
                'recent_commits' => explode("\n", trim($commits)),
                'recently_modified_files' => array_filter(explode("\n", trim($recentFiles)))
            ];

        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Führt Git-Befehle aus
     */
    private function runGitCommand(string $command, bool $throwOnError = true): string
    {
        $fullCommand = "cd \"{$this->projectPath}\" && git {$command}";
        
        $output = [];
        $returnCode = 0;
        
        exec($fullCommand . ' 2>&1', $output, $returnCode);
        
        $outputString = implode("\n", $output);
        
        if ($returnCode !== 0 && $throwOnError) {
            throw new Exception("Git-Befehl fehlgeschlagen: {$command}\nOutput: {$outputString}");
        }

        return $outputString;
    }

    /**
     * Generiert Projekt-Kontext für Prompts
     */
    public function generateProjectContext(TicketDTO $ticket): string
    {
        $knowledgeBase = $this->updateKnowledgeBase($ticket);
        
        if (isset($knowledgeBase['error'])) {
            return "⚠️ Projekt-Kontext konnte nicht vollständig geladen werden: " . $knowledgeBase['error'];
        }

        $context = "# 📁 Projekt-Kontext für {$ticket->key}\n\n";
        
        // Git Status
        if (isset($knowledgeBase['git_status'])) {
            $git = $knowledgeBase['git_status'];
            $context .= "## 🔀 Git Status\n";
            $context .= "- **Aktueller Branch:** {$git['current_branch']}\n";
            $context .= "- **Working Tree:** " . ($git['clean_working_tree'] ? 'Sauber' : 'Uncommitted Changes') . "\n";
            
            if (!$git['clean_working_tree'] && isset($git['uncommitted_changes']['files'])) {
                $context .= "- **Geänderte Dateien:** " . implode(', ', array_slice($git['uncommitted_changes']['files'], 0, 5)) . "\n";
            }
            $context .= "\n";
        }

        // Framework Info
        if (isset($knowledgeBase['project_structure']['framework'])) {
            $frameworks = $knowledgeBase['project_structure']['framework'];
            $context .= "## 🛠️ Technologie-Stack\n";
            foreach ($frameworks as $name => $version) {
                $context .= "- **{$name}:** {$version}\n";
            }
            $context .= "\n";
        }

        // Architektur-Patterns
        if (isset($knowledgeBase['code_patterns']['architecture_patterns'])) {
            $patterns = $knowledgeBase['code_patterns']['architecture_patterns'];
            $context .= "## 🏗️ Architektur-Patterns\n";
            foreach ($patterns as $pattern => $info) {
                if ($info['detected'] ?? false) {
                    $context .= "- **{$pattern}:** Verwendet\n";
                }
            }
            $context .= "\n";
        }

        // Aktuelle Konfiguration
        if (isset($knowledgeBase['configuration']['octomind'])) {
            $context .= "## ⚙️ Octomind-Konfiguration\n";
            $config = $knowledgeBase['configuration']['octomind'];
            $context .= "- **AI Provider:** " . ($config['ai']['primary_provider'] ?? 'nicht konfiguriert') . "\n";
            $context .= "- **Jira Integration:** " . (isset($config['auth']['jira_base_url']) ? 'Aktiv' : 'Nicht konfiguriert') . "\n";
            $context .= "- **GitHub Integration:** " . (isset($config['auth']['github_token']) ? 'Aktiv' : 'Nicht konfiguriert') . "\n";
            $context .= "\n";
        }

        return $context;
    }
} 
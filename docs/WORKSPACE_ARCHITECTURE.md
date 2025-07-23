# 🏗️ Octomind Workspace-Architektur

## 📁 **Wo arbeitet der Bot?**

### **Basis-Verzeichnisstruktur:**

```
/var/www/octomind/storage/app/repositories/
├── github.com/
│   ├── user1/
│   │   ├── project-a/          # Workspace für user1/project-a
│   │   │   ├── .git/
│   │   │   ├── src/
│   │   │   ├── README.md
│   │   │   └── ...
│   │   └── project-b/          # Workspace für user1/project-b
│   │       ├── .git/
│   │       └── ...
│   └── company/
│       ├── frontend/           # Workspace für company/frontend
│       └── backend/            # Workspace für company/backend
└── gitlab.com/
    └── organization/
        └── service-x/          # Workspace für GitLab-Projekte
```

### **Konfiguration:**

**Docker:**
```yaml
# docker-compose.yml
volumes:
  - bot-repositories:/var/www/octomind/storage/app/repositories
```

**Lokale Installation:**
```env
# .env
BOT_REPOSITORY_STORAGE_PATH=/path/to/your/repositories
```

---

## 🔄 **Verhalten beim ersten Ticket**

### **1. Repository existiert noch nicht:**

```php
// RepositoryInitializationService.php
public function initializeRepository(TicketDTO $ticket): array
{
    // 1. Repository-URL aus Ticket validieren
    $repoInfo = $this->validateRepositoryUrl($ticket->repositoryUrl);
    
    // 2. Lokales Arbeitsverzeichnis erstellen
    $workspacePath = $this->createWorkspace($repoInfo);
    
    // 3. Versuche Repository zu klonen
    try {
        $cloneResult = $this->cloneExistingRepository($repoInfo, $workspacePath);
        return ['action' => 'cloned', 'result' => $cloneResult];
    } catch (Exception $e) {
        // Falls klonen fehlschlägt: Neues Repository initialisieren
        $initResult = $this->initializeNewRepository($workspacePath, $repoInfo);
        return ['action' => 'initialized', 'result' => $initResult];
    }
}
```

### **2. Ablauf beim ersten Ticket:**

#### **🔍 Schritt 1: Repository-URL Validierung**
```
Ticket: PROJ-123
Repository-URL: https://github.com/company/new-project
```

#### **📁 Schritt 2: Workspace erstellen**
```bash
mkdir -p /var/www/octomind/storage/app/repositories/company/new-project
```

#### **⬇️ Schritt 3a: Repository klonen (falls existiert)**
```bash
cd /var/www/octomind/storage/app/repositories/company/
git clone https://TOKEN@github.com/company/new-project.git new-project
```

#### **🆕 Schritt 3b: Neues Repository initialisieren (falls nicht existiert)**
```bash
cd /var/www/octomind/storage/app/repositories/company/new-project
git init
git branch -M main
git remote add origin https://github.com/company/new-project.git

# Initiale Dateien erstellen
echo "# new-project" > README.md
echo "node_modules/" > .gitignore
```

#### **⚙️ Schritt 4: Framework-Detection & Setup**
```php
// Laravel-Projekt erkannt
if (file_exists('composer.json') && contains('laravel/framework')) {
    if (file_exists('.env.example') && !file_exists('.env')) {
        copy('.env.example', '.env');
    }
}

// Node.js-Projekt erkannt
if (file_exists('package.json')) {
    // Node-spezifisches Setup
}
```

#### **🔧 Schritt 5: Git-Konfiguration**
```bash
git config user.name "Octomind Bot"
git config user.email "bot@octomind.com"
git config init.defaultBranch main
```

---

## 🌿 **Branch-Management pro Ticket**

### **Branch-Naming-Convention:**
```
octomind/PROJ-123-feature-description
octomind/PROJ-456-bugfix-login-issue
octomind/PROJ-789-refactor-user-service
```

### **Branch-Erstellung:**
```php
// Für jedes neue Ticket
private function ensureCleanBranch(TicketDTO $ticket): void
{
    $workspacePath = $this->repoInit->getWorkspacePath($ticket);
    $branchName = $ticket->generateBranchName(); // octomind/PROJ-123-...
    
    // 1. Auf main/master wechseln
    git checkout main
    
    // 2. Neueste Änderungen holen
    git pull origin main
    
    // 3. Alten Branch löschen falls vorhanden
    if (branch_exists($branchName)) {
        git branch -D $branchName
        git push origin --delete $branchName
    }
    
    // 4. Neuen sauberen Branch erstellen
    git checkout -b $branchName
}
```

---

## 🎯 **Ticket-Processing-Workflow**

### **Kompletter Ablauf:**

```mermaid
graph TD
    A[Ticket PROJ-123 erhalten] --> B{Repository existiert?}
    
    B -->|Nein| C[Repository initialisieren]
    B -->|Ja| D[Repository aktualisieren]
    
    C --> E[Workspace erstellen]
    E --> F[Git clone/init]
    F --> G[Framework-Setup]
    G --> H[Git konfigurieren]
    
    D --> I[Branch erstellen]
    H --> I
    
    I --> J[Knowledge-Base aktualisieren]
    J --> K[AI-Prompt generieren]
    K --> L[Code-Änderungen]
    L --> M[Code-Review]
    M --> N[Commit & Push]
    N --> O[Pull Request erstellen]
    O --> P[Jira aktualisieren]
```

### **Workspace-Pfade zur Laufzeit:**

```php
// Beispiel-Ticket-Verarbeitung
$ticket = new TicketDTO(
    key: 'PROJ-123',
    repositoryUrl: 'https://github.com/company/frontend'
);

// 1. Repository-Initialisierung prüfen
$repoInit = new RepositoryInitializationService();
if (!$repoInit->isRepositoryInitialized($ticket)) {
    $result = $repoInit->initializeRepository($ticket);
    // Workspace: /var/www/octomind/storage/app/repositories/company/frontend
}

// 2. Workspace-Pfad holen
$workspacePath = $repoInit->getWorkspacePath($ticket);
// -> /var/www/octomind/storage/app/repositories/company/frontend

// 3. Branch erstellen
// -> octomind/PROJ-123-implement-login-feature

// 4. Code-Änderungen in Workspace durchführen
// 5. Commit & Push von Workspace
// 6. Pull Request erstellen
```

---

## 🔒 **Sicherheit & Isolation**

### **Workspace-Isolation:**
- **Jedes Repository** hat seinen eigenen isolierten Workspace
- **Keine Konflikte** zwischen verschiedenen Projekten
- **Saubere Trennung** von Branches pro Ticket

### **Berechtigungen:**
```bash
# Docker-Container
USER octomind (UID 1000)
WORKDIR /var/www/octomind
Permissions: 755 für Verzeichnisse, 644 für Dateien
```

### **Git-Authentifizierung:**
```bash
# GitHub
https://TOKEN@github.com/user/repo.git

# GitLab  
https://oauth2:TOKEN@gitlab.com/user/repo.git
```

---

## 📊 **Monitoring & Debugging**

### **Logs:**
```bash
# Repository-Initialisierung
[INFO] Initialisiere Repository für erstes Ticket
[DEBUG] Erstelle Arbeitsverzeichnis: /var/www/octomind/storage/app/repositories/company/project
[INFO] Repository erfolgreich geklont
[DEBUG] Framework erkannt: laravel

# Branch-Management
[INFO] Stelle sauberen Branch sicher
[DEBUG] Branch octomind/PROJ-123-feature bereits vorhanden, lösche und erstelle neu
[INFO] Sauberer Branch erstellt: octomind/PROJ-123-feature
```

### **Workspace-Status prüfen:**
```bash
# Docker
docker-compose exec octomind-bot ls -la /var/www/octomind/storage/app/repositories

# Lokal
ls -la storage/app/repositories
```

### **Git-Status in Workspace:**
```bash
# Aktueller Branch und Status
docker-compose exec octomind-bot bash -c "cd /var/www/octomind/storage/app/repositories/company/project && git status"
```

---

## 🚀 **Performance-Optimierungen**

### **Workspace-Caching:**
- **Git-Objekte** werden zwischen Tickets wiederverwendet
- **Dependencies** (node_modules, vendor) bleiben erhalten wenn möglich
- **Framework-Setup** wird nur einmal durchgeführt

### **Cleanup-Strategien:**
```php
// Alte Branches automatisch löschen
private function cleanupOldBranches(string $workspacePath): void
{
    $branches = $this->runGitCommand($workspacePath, 'branch -a');
    $octomindBranches = array_filter($branches, fn($b) => str_contains($b, 'octomind/'));
    
    foreach ($octomindBranches as $branch) {
        if ($this->isBranchOlderThan($branch, '7 days')) {
            $this->runGitCommand($workspacePath, "branch -D {$branch}");
        }
    }
}
```

---

## ✅ **Zusammenfassung**

### **🎯 Antworten auf deine Fragen:**

**1. Was passiert beim allerersten Ticket?**
- ✅ **Repository-URL aus Ticket extrahieren**
- ✅ **Workspace-Verzeichnis erstellen** (`/storage/app/repositories/owner/repo`)
- ✅ **Repository klonen** (falls existiert) **ODER neues initialisieren**
- ✅ **Framework-Detection** und Setup (Laravel, Node.js, Python, etc.)
- ✅ **Git-Konfiguration** mit Bot-Credentials
- ✅ **Initiale Dateien** erstellen (README, .gitignore)

**2. Wo finden die Arbeiten statt?**
- ✅ **Docker:** `/var/www/octomind/storage/app/repositories/`
- ✅ **Lokal:** `storage/app/repositories/` (konfigurierbar)
- ✅ **Struktur:** `{provider}/{owner}/{repository}/`
- ✅ **Isolation:** Jedes Repository hat eigenen Workspace
- ✅ **Branch-Management:** `octomind/TICKET-KEY-description`

**Das System ist jetzt vollständig für den ersten Ticket-Einsatz vorbereitet!** 🎉 
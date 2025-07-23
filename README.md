# 🤖 Octomind AI Automation Bot

Ein vollautomatisiertes Laravel-basiertes CLI-System, das Jira-Tickets liest, mit OpenAI und Claude AI Lösungen generiert und automatisch Code-Änderungen in GitHub-Repositories durchführt.

## 🚀 Features

- **Jira-Integration**: Automatisches Abrufen und Verarbeiten von Jira-Tickets
- **Multi-AI-Support**: Integration mit OpenAI, Claude (Anthropic) und Cloud AI
- **Automatische Code-Generierung**: KI-gesteuerte Lösungserstellung und Code-Implementierung
- **GitHub-Integration**: Automatische PR-Erstellung und Repository-Management
- **Retry & Self-Healing**: Intelligente Fehlerbehandlung mit exponential backoff
- **Umfassendes Monitoring**: Gesundheitschecks, Metriken und Logging
- **Sicherheitsregeln**: Konfigurierbare Sicherheitsbeschränkungen
- **Simulation Mode**: Sicheres Testen ohne echte Änderungen

## 📋 Systemanforderungen

- PHP 8.2+
- Laravel 11.x
- SQLite/MySQL/PostgreSQL
- Git
- Composer

## 🛠️ Installation

### 🐳 Docker Installation (Empfohlen)

1. **Repository klonen**
```bash
git clone https://github.com/your-org/octomind.git
cd octomind
```

2. **Development Environment starten**
```bash
# Einfacher Start
./docker/scripts/start-dev.sh

# Oder mit Management-Script
./docker/scripts/manage.sh start-dev
```

3. **API-Keys konfigurieren**
```bash
# .env-Datei bearbeiten (wird automatisch erstellt)
nano .env

# Erforderliche Keys hinzufügen:
# GITHUB_TOKEN=ghp_your_token_here
# OPENAI_API_KEY=sk-your_openai_key_here
# JIRA_USERNAME=your.email@company.com
# JIRA_API_TOKEN=your_jira_api_token
# JIRA_BASE_URL=https://your-company.atlassian.net
# JIRA_PROJECT_KEY=PROJ
```

4. **Bot starten**
```bash
docker-compose exec octomind-bot php artisan octomind:start --debug
```

### 📦 Lokale Installation (Alternative)

1. **Repository klonen**
```bash
git clone https://github.com/your-org/octomind.git
cd octomind
```

2. **Dependencies installieren**
```bash
composer install
```

3. **Umgebungsvariablen konfigurieren**
```bash
cp .env.example .env
php artisan key:generate
```

4. **Datenbank einrichten**
```bash
# Für SQLite (empfohlen für den Start)
touch database/database.sqlite

# Migrationen ausführen
php artisan migrate
```

5. **Repository-Storage erstellen**
```bash
mkdir -p storage/app/repositories
```

## ⚙️ Konfiguration

### Erforderliche API-Keys

In der `.env`-Datei müssen folgende Werte konfiguriert werden:

```env
# GitHub Personal Access Token mit repo-Berechtigung
GITHUB_TOKEN=ghp_your_token_here

# OpenAI API Key
OPENAI_API_KEY=sk-your_openai_key_here

# Anthropic API Key (optional, als Fallback)
ANTHROPIC_API_KEY=sk-ant-your_anthropic_key_here

# Jira-Credentials
JIRA_USERNAME=your.email@company.com
JIRA_API_TOKEN=your_jira_api_token
JIRA_BASE_URL=https://your-company.atlassian.net
JIRA_PROJECT_KEY=PROJ
```

### Bot-Konfiguration

```env
# Bot aktivieren (standardmäßig deaktiviert)
BOT_ENABLED=true

# Simulation Mode (empfohlen für Tests)
BOT_SIMULATE_MODE=true

# Logging-Level
BOT_VERBOSE_LOGGING=true
BOT_LOG_LEVEL=debug

# Jira-Filter
BOT_JIRA_REQUIRED_LABEL=ai-bot
BOT_JIRA_REQUIRE_UNASSIGNED=true
```

## 🎯 Verwendung

### 🐳 Docker-Verwendung (Empfohlen)

```bash
# Management-Script verwenden
./docker/scripts/manage.sh [COMMAND]

# Verfügbare Befehle:
./docker/scripts/manage.sh start-dev     # Development starten
./docker/scripts/manage.sh start-prod    # Production starten
./docker/scripts/manage.sh logs          # Logs anzeigen
./docker/scripts/manage.sh status        # Service-Status
./docker/scripts/manage.sh shell         # Shell öffnen
./docker/scripts/manage.sh config-check  # Konfiguration prüfen
./docker/scripts/manage.sh backup        # Datenbank-Backup
./docker/scripts/manage.sh stop          # Services stoppen
```

### Bot starten

```bash
# Docker (Development)
docker-compose exec octomind-bot php artisan octomind:start --debug

# Docker (Production)
docker-compose -f docker-compose.prod.yml exec octomind-bot php artisan octomind:start

# Lokal
php artisan octomind:start --config-check  # Konfiguration überprüfen
php artisan octomind:start --simulate --debug  # Simulation-Modus
php artisan octomind:start  # Live-Modus
```

### 📊 Monitoring & Services

```bash
# Bot Dashboard (Neu!)
http://localhost:8000

# Monitoring Dashboard
http://localhost:3000 (admin/octomind_admin)

# Log-Viewer
http://localhost:8080

# Prometheus (Production)
http://localhost:9090

# Service-Logs
docker-compose logs -f octomind-bot
./docker/scripts/manage.sh logs octomind-bot
```

### Jira-Tickets vorbereiten

1. Tickets mit dem konfigurierten Label versehen (z.B. `ai-bot`)
2. Repository-Link in der Ticket-Beschreibung oder Custom Field hinterlegen
3. Ticket als "unassigned" belassen (falls `BOT_JIRA_REQUIRE_UNASSIGNED=true`)

### Monitoring

```bash
# Logs anzeigen
tail -f storage/logs/laravel.log

# Gesundheitscheck
php artisan octomind:start --config-check
```

## 🏗️ Architektur

### Core Services

- **ConfigService**: Zentrale Konfigurationsverwaltung
- **LogService**: Umfassendes Logging-System
- **JiraService**: Jira-API-Integration
- **PromptBuilderService**: AI-Prompt-Generierung
- **CloudAIService**: Multi-AI-Provider-Integration
- **GitHubService**: GitHub-Repository-Management
- **BotStatusService**: Monitoring und Gesundheitschecks

### Datenbank-Schema

- `tickets`: Jira-Ticket-Daten und Verarbeitungsstatus
- `prompts`: AI-Prompt-Verlauf und Antworten
- `executions`: Code-Ausführungs-Protokoll
- `retry_attempts`: Retry-Mechanismus-Tracking
- `bot_logs`: Detailliertes System-Logging

### Workflow

1. **Ticket-Abruf**: Regelmäßiges Polling von Jira-Tickets
2. **Analyse**: Ticket-Inhalt und Repository-Kontext analysieren
3. **Lösung generieren**: AI-basierte Lösungserstellung
4. **Code ausführen**: Automatische Code-Änderungen mit Retry-Logik
5. **PR erstellen**: GitHub-Pull-Request mit Änderungen
6. **Feedback**: Jira-Kommentar mit Ergebnis-Link

## 🔒 Sicherheit

### Konfigurierbare Sicherheitsregeln

```env
# Erlaubte Dateierweiterungen
BOT_ALLOWED_FILE_EXTENSIONS="php,js,ts,vue,blade.php,json"

# Verbotene Pfade
BOT_FORBIDDEN_PATHS=".env,.git,vendor,node_modules"

# Maximale Dateigröße (1MB)
BOT_MAX_FILE_SIZE=1048576

# Menschliche Überprüfung erforderlich
BOT_REQUIRE_HUMAN_REVIEW=true

# Gefährliche Operationen blockieren
BOT_DANGEROUS_OPERATIONS="delete,truncate,drop"
```

### Simulation Mode

Im Simulation-Modus werden alle Operationen protokolliert, aber keine echten Änderungen vorgenommen:

```bash
php artisan octomind:start --simulate
```

## 📊 Monitoring & Logging

### Gesundheitschecks

- Datenbankverbindung und -performance
- Speicher- und Festplattenspeicher-Nutzung
- Externe Service-Verfügbarkeit (Jira, GitHub, AI-Provider)
- Queue-Gesundheit und Backlog-Status

### Metriken

- Verarbeitete Tickets pro Tag
- Durchschnittliche Verarbeitungszeit
- Erfolgsrate und Fehlerstatistiken
- Ressourcenverbrauch

### Log-Levels

- `debug`: Detaillierte Entwicklungsinformationen
- `info`: Allgemeine Betriebsinformationen
- `warning`: Potenzielle Probleme
- `error`: Fehler, die Aufmerksamkeit erfordern
- `critical`: Kritische Systemfehler

## 🔄 Retry & Self-Healing

### Retry-Mechanismus

```env
BOT_RETRY_MAX_ATTEMPTS=3
BOT_RETRY_BACKOFF_MULTIPLIER=2
BOT_RETRY_INITIAL_DELAY=5
BOT_RETRY_MAX_DELAY=300
```

### Self-Healing

```env
BOT_SELF_HEALING_ENABLED=true
BOT_SELF_HEALING_MAX_ROUNDS=5
```

Das System kann automatisch Fehler analysieren und alternative Lösungsansätze versuchen.

## 🧪 Testing

```bash
# Unit Tests ausführen
php artisan test

# Mit Coverage
php artisan test --coverage

# Nur Bot-spezifische Tests
php artisan test --filter=Bot
```

## 📝 Entwicklung

### Service-Erweiterung

Neue Services können einfach hinzugefügt werden:

```php
<?php

namespace App\Services;

class CustomService
{
    private ConfigService $config;
    private LogService $logger;

    public function __construct()
    {
        $this->config = ConfigService::getInstance();
        $this->logger = new LogService();
    }
}
```

### Neue AI-Provider

AI-Provider können über das `AiProvider`-Enum und entsprechende Service-Implementierungen hinzugefügt werden.

## 🚨 Troubleshooting

### Häufige Probleme

1. **Konfigurationsfehler**
   ```bash
   php artisan octomind:start --config-check
   ```

2. **Datenbankverbindung**
   ```bash
   php artisan migrate:status
   ```

3. **API-Verbindungen**
   - GitHub Token-Berechtigung überprüfen
   - Jira-Credentials validieren
   - AI-Provider-Keys testen

4. **Speicherprobleme**
   ```env
   BOT_MEMORY_LIMIT=1024M
   ```

### Debug-Modus

```bash
# Lokal
php artisan octomind:start --debug --simulate

# Docker
docker-compose exec octomind-bot php artisan octomind:start --debug --simulate
```

## 🐳 Docker-Deployment

### Development Environment

```bash
# Schnellstart
./docker/scripts/start-dev.sh

# Services verwalten
./docker/scripts/manage.sh start-dev
./docker/scripts/manage.sh logs octomind-bot
./docker/scripts/manage.sh shell
./docker/scripts/manage.sh stop
```

### Production Environment

```bash
# Production starten
./docker/scripts/start-prod.sh

# Oder mit Management-Script
./docker/scripts/manage.sh start-prod

# Production-Services
docker-compose -f docker-compose.prod.yml up -d
docker-compose -f docker-compose.prod.yml logs -f octomind-bot
```

### Docker-Services

**Development (`docker-compose.yml`):**
- `octomind-bot`: Hauptbot-Container
- `web-interface`: Laravel Web-Dashboard (Port 8000)
- `database`: PostgreSQL-Datenbank
- `redis`: Caching und Queue-Management
- `queue-worker`: Background-Job-Verarbeitung (2 Replicas)
- `scheduler`: Cron-Job-Scheduler
- `monitoring`: Grafana-Dashboard
- `log-viewer`: Dozzle Log-Viewer

**Production (`docker-compose.prod.yml`):**
- Optimierte Container mit Multi-Stage-Builds
- Nginx Reverse Proxy
- Prometheus für Metriken
- Loki für Log-Aggregation
- Resource-Limits und Health-Checks
- Sichere Netzwerk-Konfiguration

### Backup & Restore

```bash
# Datenbank-Backup erstellen
./docker/scripts/manage.sh backup

# Backup wiederherstellen
./docker/scripts/manage.sh restore backup_20241223_120000.sql

# Production-Backup
docker-compose -f docker-compose.prod.yml exec database pg_dump -U octomind octomind > prod_backup.sql
```

### Monitoring

- **Bot Dashboard**: http://localhost:8000 (Hauptinterface)
- **Grafana**: http://localhost:3000 (admin/octomind_admin)
- **Prometheus**: http://localhost:9090 (Production)
- **Loki**: http://localhost:3100 (Production)
- **Log-Viewer**: http://localhost:8080 (Development)

## 📄 Lizenz

Dieses Projekt ist unter der MIT-Lizenz lizenziert - siehe [LICENSE](LICENSE) für Details.

## 🤝 Beitragen

1. Fork das Repository
2. Erstelle einen Feature-Branch (`git checkout -b feature/amazing-feature`)
3. Commit deine Änderungen (`git commit -m 'Add amazing feature'`)
4. Push zum Branch (`git push origin feature/amazing-feature`)
5. Öffne einen Pull Request

## 📞 Support

Bei Fragen oder Problemen:

1. Prüfe die [Issues](https://github.com/your-org/octomind/issues)
2. Erstelle ein neues Issue mit detaillierter Beschreibung
3. Nutze den `--verbose`-Modus für detaillierte Logs

---

**⚠️ Wichtiger Hinweis**: Dieses System führt automatische Code-Änderungen durch. Verwende immer den Simulation-Modus für Tests und stelle sicher, dass alle Sicherheitsregeln korrekt konfiguriert sind.

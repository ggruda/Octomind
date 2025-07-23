# 🤖 Octomind Bot Setup Anleitung

## 1. 🔧 Umgebungsvariablen konfigurieren

Bearbeite deine `.env`-Datei und passe folgende Werte an:

```env
# Jira-Konfiguration
JIRA_BASE_URL=https://DEINE-DOMAIN.atlassian.net
JIRA_USERNAME=deine-email@example.com
JIRA_API_TOKEN=dein-jira-api-token
JIRA_PROJECT_KEY=DEIN-PROJECT-KEY

# GitHub-Konfiguration
GITHUB_TOKEN=dein-github-personal-access-token
GITHUB_REPOSITORY=dein-username/dein-repository

# AI-Provider (optional)
OPENAI_API_KEY=dein-openai-api-key
CLAUDE_API_KEY=dein-claude-api-key
```

## 2. 🎫 Jira API Token erstellen

1. Gehe zu: https://id.atlassian.com/manage-profile/security/api-tokens
2. Klicke auf "Create API token"
3. Gib einen Namen ein (z.B. "Octomind Bot")
4. Kopiere den Token in deine `.env`-Datei

## 3. 🐙 GitHub Personal Access Token erstellen

1. Gehe zu: https://github.com/settings/tokens
2. Klicke auf "Generate new token (classic)"
3. Wähle folgende Scopes:
   - `repo` (Full control of private repositories)
   - `workflow` (Update GitHub Action workflows)
4. Kopiere den Token in deine `.env`-Datei

## 4. 🗄️ Projekt in der Datenbank erstellen

Führe folgende Artisan-Befehle aus:

```bash
# Projekt erstellen
docker run --rm --network octomind_octomind-network \
  -v $(pwd):/var/www/octomind -w /var/www/octomind \
  --env-file .env \
  php:8.3-cli-alpine sh -c "
    docker-php-ext-install pdo pdo_mysql && \
    php artisan octomind:create-project
  "

# Repository verknüpfen
docker run --rm --network octomind_octomind-network \
  -v $(pwd):/var/www/octomind -w /var/www/octomind \
  --env-file .env \
  php:8.3-cli-alpine sh -c "
    docker-php-ext-install pdo pdo_mysql && \
    php artisan octomind:add-repository
  "
```

## 5. 🎯 Bot-Session starten

```bash
# Bot-Session erstellen (z.B. 10 Stunden)
docker run --rm --network octomind_octomind-network \
  -v $(pwd):/var/www/octomind -w /var/www/octomind \
  --env-file .env \
  php:8.3-cli-alpine sh -c "
    docker-php-ext-install pdo pdo_mysql && \
    php artisan octomind:create-session --hours=10
  "
```

## 6. 🚀 Tickets laden und verarbeiten

```bash
# Tickets aus Jira laden
docker run --rm --network octomind_octomind-network \
  -v $(pwd):/var/www/octomind -w /var/www/octomind \
  --env-file .env \
  php:8.3-cli-alpine sh -c "
    docker-php-ext-install pdo pdo_mysql && \
    php artisan octomind:load-tickets
  "

# Tickets verarbeiten
docker run --rm --network octomind_octomind-network \
  -v $(pwd):/var/www/octomind -w /var/www/octomind \
  --env-file .env \
  php:8.3-cli-alpine sh -c "
    docker-php-ext-install pdo pdo_mysql && \
    php artisan octomind:process-tickets
  "
```

## 7. 📊 Status überwachen

- **PHPMyAdmin**: http://localhost:8081
- **Mailpit**: http://localhost:8027
- **Logs**: `docker-compose -f docker-compose-mysql.yml logs -f`

## 8. 🔄 Automatisierung (Cronjobs)

Der Bot läuft automatisch alle 5 Minuten, wenn:
- Eine aktive Bot-Session mit verbleibenden Stunden existiert
- Tickets mit dem Label `ai-bot` und Status `Open/In Progress/To Do` vorhanden sind

## 🔍 Ticket-Anforderungen

Tickets müssen folgende Kriterien erfüllen:
- ✅ Label: `ai-bot`
- ✅ Status: `Open`, `In Progress`, oder `To Do`
- ✅ Nicht zugewiesen (optional konfigurierbar)
- ✅ Beschreibung vorhanden

## 🛠️ Troubleshooting

1. **Jira-Verbindung testen**:
   ```bash
   curl -u "deine-email@example.com:dein-api-token" \
     "https://DEINE-DOMAIN.atlassian.net/rest/api/3/myself"
   ```

2. **GitHub-Verbindung testen**:
   ```bash
   curl -H "Authorization: token dein-github-token" \
     "https://api.github.com/user"
   ```

3. **Logs überprüfen**:
   ```bash
   docker-compose -f docker-compose-mysql.yml logs octomind-mysql
   ``` 
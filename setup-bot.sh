#!/bin/bash

# Octomind Bot Setup Script
echo "🤖 Octomind Bot Setup wird gestartet..."

# Basis-Kommando für Docker-Ausführung
DOCKER_CMD="docker run --rm --network octomind_octomind-network -v \$(pwd):/var/www/octomind -w /var/www/octomind --env-file .env php:8.3-cli-alpine sh -c \"docker-php-ext-install pdo pdo_mysql && php artisan"

echo "📋 1. Projekt aus Jira importieren..."
eval "\$DOCKER_CMD octomind:project import \$JIRA_PROJECT_KEY --jira-base-url=\$JIRA_BASE_URL\""

echo "🔗 2. Repository importieren..."
eval "\$DOCKER_CMD octomind:repository import \$GITHUB_REPOSITORY --provider=github --bot-enabled\""

echo "🔐 3. SSH-Keys initialisieren..."
eval "\$DOCKER_CMD octomind:ssh-keys init\""

echo "📁 4. Repository klonen..."
eval "\$DOCKER_CMD octomind:repository clone \$GITHUB_REPOSITORY\""

echo "🔗 5. Repository mit Projekt verknüpfen..."
eval "\$DOCKER_CMD octomind:project attach-repo \$JIRA_PROJECT_KEY --repository=\$GITHUB_REPOSITORY --default\""

echo "✅ 6. Bot für Projekt aktivieren..."
eval "\$DOCKER_CMD octomind:project update \$JIRA_PROJECT_KEY --bot-enabled\""

echo "🎯 7. Bot-Session erstellen (10 Stunden)..."
eval "\$DOCKER_CMD octomind:bot:session create --customer-email=\$JIRA_USERNAME --hours=10 --customer-name=\"Bot User\"\""

echo "✅ Setup abgeschlossen! Bot ist bereit."
echo "📊 Status prüfen: docker run --rm --network octomind_octomind-network -v \$(pwd):/var/www/octomind -w /var/www/octomind --env-file .env php:8.3-cli-alpine sh -c \"docker-php-ext-install pdo pdo_mysql && php artisan octomind:bot:status\""


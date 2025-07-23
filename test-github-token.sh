#!/bin/bash
# GitHub Token Test Script

echo "🧪 Teste GitHub Token..."

# Teste API-Zugriff
curl -H "Authorization: token $GITHUB_TOKEN" \
     -H "Accept: application/vnd.github.v3+json" \
     https://api.github.com/user

echo ""
echo "✅ Wenn du deine GitHub-Benutzerdaten siehst, funktioniert der Token!"

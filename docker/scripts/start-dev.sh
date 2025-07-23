#!/bin/bash

# Octomind Bot - Development Environment Startup Script
# This script starts the development environment with all necessary services

set -e

echo "🚀 Starting Octomind Bot Development Environment..."

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo "❌ Docker is not running. Please start Docker first."
    exit 1
fi

# Check if .env file exists
if [ ! -f ".env" ]; then
    echo "📋 Creating .env file from .env.example..."
    cp .env.example .env
    echo "⚠️  Please update the .env file with your API keys before continuing."
    echo "   Required: GITHUB_TOKEN, OPENAI_API_KEY (or ANTHROPIC_API_KEY), JIRA credentials"
    read -p "Press Enter to continue after updating .env file..."
fi

# Create necessary directories
echo "📁 Creating necessary directories..."
mkdir -p storage/app/repositories
mkdir -p storage/logs
mkdir -p storage/framework/{cache,sessions,views}
mkdir -p database

# Create SQLite database if it doesn't exist
if [ ! -f "database/database.sqlite" ]; then
    echo "🗄️  Creating SQLite database..."
    touch database/database.sqlite
fi

# Build and start services
echo "🔧 Building and starting Docker services..."
docker-compose up --build -d

# Wait for services to be healthy
echo "⏳ Waiting for services to be ready..."
sleep 10

# Run migrations
echo "🔄 Running database migrations..."
docker-compose exec octomind-bot php artisan migrate --force

# Check bot configuration
echo "🔍 Checking bot configuration..."
docker-compose exec octomind-bot php artisan octomind:start --config-check

echo ""
echo "✅ Development environment is ready!"
echo ""
echo "📊 Available services:"
echo "   • Bot: docker-compose logs -f octomind-bot"
echo "   • Database: localhost:5432 (postgres/octomind/octomind_password)"
echo "   • Redis: localhost:6379"
echo "   • Monitoring: http://localhost:3000 (admin/octomind_admin)"
echo "   • Logs: http://localhost:8080"
echo ""
echo "🛠️  Common commands:"
echo "   • Start bot: docker-compose exec octomind-bot php artisan octomind:start --debug"
echo "   • View logs: docker-compose logs -f octomind-bot"
echo "   • Stop all: docker-compose down"
echo "   • Restart bot: docker-compose restart octomind-bot"
echo "" 
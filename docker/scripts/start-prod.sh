#!/bin/bash

# Octomind Bot - Production Environment Startup Script
# This script starts the production environment with optimized settings

set -e

echo "🚀 Starting Octomind Bot Production Environment..."

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo "❌ Docker is not running. Please start Docker first."
    exit 1
fi

# Check if production env file exists
if [ ! -f "docker/env.docker.example" ]; then
    echo "❌ Production environment file not found: docker/env.docker.example"
    echo "   Please copy and configure this file with your production settings."
    exit 1
fi

# Create production .env from docker template
if [ ! -f ".env.production" ]; then
    echo "📋 Creating production .env file..."
    cp docker/env.docker.example .env.production
    echo "⚠️  Please update .env.production with your production API keys and settings."
    read -p "Press Enter to continue after updating .env.production..."
fi

# Create necessary directories with proper permissions
echo "📁 Creating production directories..."
mkdir -p storage/app/repositories
mkdir -p storage/logs
mkdir -p docker/ssl
mkdir -p docker/nginx/sites

# Generate application key if not set
echo "🔑 Checking application key..."
if ! grep -q "APP_KEY=base64:" .env.production; then
    echo "Generating new application key..."
    docker run --rm -v $(pwd):/app -w /app php:8.3-cli-alpine php artisan key:generate --env=production
fi

# Pull latest images
echo "📥 Pulling latest Docker images..."
docker-compose -f docker-compose.prod.yml pull

# Build and start production services
echo "🔧 Building and starting production services..."
docker-compose -f docker-compose.prod.yml up --build -d

# Wait for database to be ready
echo "⏳ Waiting for database to be ready..."
timeout=60
while ! docker-compose -f docker-compose.prod.yml exec -T database pg_isready -U octomind -d octomind > /dev/null 2>&1; do
    sleep 2
    timeout=$((timeout - 2))
    if [ $timeout -le 0 ]; then
        echo "❌ Database startup timeout"
        exit 1
    fi
done

# Run migrations
echo "🔄 Running database migrations..."
docker-compose -f docker-compose.prod.yml exec -T octomind-bot php artisan migrate --force

# Optimize Laravel for production
echo "⚡ Optimizing Laravel for production..."
docker-compose -f docker-compose.prod.yml exec -T octomind-bot php artisan config:cache
docker-compose -f docker-compose.prod.yml exec -T octomind-bot php artisan route:cache
docker-compose -f docker-compose.prod.yml exec -T octomind-bot php artisan view:cache

# Check bot configuration
echo "🔍 Checking bot configuration..."
docker-compose -f docker-compose.prod.yml exec -T octomind-bot php artisan octomind:start --config-check

echo ""
echo "✅ Production environment is ready!"
echo ""
echo "📊 Available services:"
echo "   • Bot: Running in production mode"
echo "   • Database: localhost:5432 (secure connection)"
echo "   • Redis: localhost:6379 (password protected)"
echo "   • Monitoring: http://localhost:3000"
echo "   • Prometheus: http://localhost:9090"
echo "   • Loki: http://localhost:3100"
echo ""
echo "🛠️  Production commands:"
echo "   • View logs: docker-compose -f docker-compose.prod.yml logs -f octomind-bot"
echo "   • Monitor health: docker-compose -f docker-compose.prod.yml ps"
echo "   • Stop all: docker-compose -f docker-compose.prod.yml down"
echo "   • Backup DB: docker-compose -f docker-compose.prod.yml exec database pg_dump -U octomind octomind > backup.sql"
echo ""
echo "⚠️  Security reminders:"
echo "   • Change default passwords in .env.production"
echo "   • Configure SSL certificates in docker/ssl/"
echo "   • Set up proper firewall rules"
echo "   • Enable log rotation"
echo "" 
#!/bin/bash

# Octomind Bot - Docker Management Script
# Provides easy commands for managing the bot environment

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$PROJECT_DIR"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Helper functions
log_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

log_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

log_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

log_error() {
    echo -e "${RED}❌ $1${NC}"
}

show_help() {
    cat << EOF
🤖 Octomind Bot Docker Management

Usage: $0 [COMMAND] [OPTIONS]

COMMANDS:
  start-dev         Start development environment
  start-prod        Start production environment
  stop              Stop all services
  restart           Restart all services
  logs [service]    Show logs (default: octomind-bot)
  status            Show service status
  shell             Open shell in bot container
  migrate           Run database migrations
  config-check      Check bot configuration
  backup            Create database backup
  restore [file]    Restore database from backup
  clean             Clean unused Docker resources
  update            Update all services
  monitor           Open monitoring dashboard

DEVELOPMENT COMMANDS:
  dev-reset         Reset development environment
  dev-seed          Seed development database
  dev-test          Run tests in container

PRODUCTION COMMANDS:
  prod-deploy       Deploy to production
  prod-backup       Create production backup
  prod-monitor      Check production health

OPTIONS:
  -h, --help        Show this help message
  -v, --verbose     Verbose output

EXAMPLES:
  $0 start-dev              # Start development environment
  $0 logs octomind-bot      # Show bot logs
  $0 shell                  # Open shell in bot container
  $0 backup                 # Create database backup

EOF
}

check_docker() {
    if ! docker info > /dev/null 2>&1; then
        log_error "Docker is not running. Please start Docker first."
        exit 1
    fi
}

get_compose_file() {
    if [ "$1" = "prod" ]; then
        echo "docker-compose.prod.yml"
    else
        echo "docker-compose.yml"
    fi
}

start_dev() {
    log_info "Starting development environment..."
    check_docker
    
    if [ ! -f ".env" ]; then
        log_info "Creating .env file from example..."
        cp .env.example .env
        log_warning "Please update .env file with your API keys"
    fi
    
    mkdir -p storage/{app/repositories,logs,framework/{cache,sessions,views}} database
    [ ! -f "database/database.sqlite" ] && touch database/database.sqlite
    
    docker-compose up --build -d
    sleep 10
    
    log_info "Running migrations..."
    docker-compose exec octomind-bot php artisan migrate --force
    
    log_success "Development environment started!"
    show_services "dev"
}

start_prod() {
    log_info "Starting production environment..."
    check_docker
    
    if [ ! -f ".env.production" ]; then
        log_error "Production .env file not found. Please create .env.production"
        exit 1
    fi
    
    docker-compose -f docker-compose.prod.yml up --build -d
    sleep 15
    
    log_info "Running migrations..."
    docker-compose -f docker-compose.prod.yml exec -T octomind-bot php artisan migrate --force
    
    log_info "Optimizing for production..."
    docker-compose -f docker-compose.prod.yml exec -T octomind-bot php artisan config:cache
    docker-compose -f docker-compose.prod.yml exec -T octomind-bot php artisan route:cache
    
    log_success "Production environment started!"
    show_services "prod"
}

stop_services() {
    log_info "Stopping all services..."
    docker-compose down
    docker-compose -f docker-compose.prod.yml down 2>/dev/null || true
    log_success "All services stopped"
}

restart_services() {
    log_info "Restarting services..."
    docker-compose restart
    log_success "Services restarted"
}

show_logs() {
    local service=${1:-octomind-bot}
    log_info "Showing logs for $service..."
    docker-compose logs -f "$service"
}

show_status() {
    log_info "Service status:"
    docker-compose ps
    echo ""
    log_info "Resource usage:"
    docker stats --no-stream --format "table {{.Container}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.NetIO}}\t{{.BlockIO}}"
}

open_shell() {
    log_info "Opening shell in bot container..."
    docker-compose exec octomind-bot /bin/sh
}

run_migrations() {
    log_info "Running database migrations..."
    docker-compose exec octomind-bot php artisan migrate
    log_success "Migrations completed"
}

check_config() {
    log_info "Checking bot configuration..."
    docker-compose exec octomind-bot php artisan octomind:start --config-check
}

create_backup() {
    local backup_file="backup_$(date +%Y%m%d_%H%M%S).sql"
    log_info "Creating database backup: $backup_file"
    
    if docker-compose ps | grep -q octomind-db; then
        docker-compose exec database pg_dump -U octomind octomind > "$backup_file"
        log_success "Backup created: $backup_file"
    else
        log_error "Database container not running"
        exit 1
    fi
}

restore_backup() {
    local backup_file="$1"
    if [ -z "$backup_file" ]; then
        log_error "Please specify backup file"
        exit 1
    fi
    
    if [ ! -f "$backup_file" ]; then
        log_error "Backup file not found: $backup_file"
        exit 1
    fi
    
    log_warning "This will overwrite the current database!"
    read -p "Are you sure? (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        log_info "Restoring database from $backup_file..."
        docker-compose exec -T database psql -U octomind -d octomind < "$backup_file"
        log_success "Database restored"
    else
        log_info "Restore cancelled"
    fi
}

clean_docker() {
    log_info "Cleaning unused Docker resources..."
    docker system prune -f
    docker volume prune -f
    log_success "Docker cleanup completed"
}

update_services() {
    log_info "Updating all services..."
    docker-compose pull
    docker-compose up --build -d
    log_success "Services updated"
}

show_services() {
    local env=${1:-dev}
    echo ""
    log_info "Available services:"
    if [ "$env" = "prod" ]; then
        echo "   • Monitoring: http://localhost:3000"
        echo "   • Prometheus: http://localhost:9090"
        echo "   • Loki: http://localhost:3100"
    else
        echo "   • Monitoring: http://localhost:3000 (admin/octomind_admin)"
        echo "   • Logs: http://localhost:8080"
        echo "   • Database: localhost:5432"
        echo "   • Redis: localhost:6379"
    fi
    echo ""
}

# Main command processing
case "${1:-}" in
    start-dev)
        start_dev
        ;;
    start-prod)
        start_prod
        ;;
    stop)
        stop_services
        ;;
    restart)
        restart_services
        ;;
    logs)
        show_logs "$2"
        ;;
    status)
        show_status
        ;;
    shell)
        open_shell
        ;;
    migrate)
        run_migrations
        ;;
    config-check)
        check_config
        ;;
    backup)
        create_backup
        ;;
    restore)
        restore_backup "$2"
        ;;
    clean)
        clean_docker
        ;;
    update)
        update_services
        ;;
    monitor)
        log_info "Opening monitoring dashboard..."
        open "http://localhost:3000" 2>/dev/null || xdg-open "http://localhost:3000" 2>/dev/null || echo "Please open http://localhost:3000"
        ;;
    dev-reset)
        log_warning "This will destroy all development data!"
        read -p "Are you sure? (y/N): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            docker-compose down -v
            start_dev
        fi
        ;;
    -h|--help|help)
        show_help
        ;;
    "")
        log_error "No command specified"
        show_help
        exit 1
        ;;
    *)
        log_error "Unknown command: $1"
        show_help
        exit 1
        ;;
esac 
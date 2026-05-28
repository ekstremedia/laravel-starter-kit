ifneq (,$(wildcard ./.env))
include .env
export
endif

APP_SERVICE ?= app
DB_SERVICE ?= postgres

# Build display URL: use APP_URL as-is when it already contains a port or
# when APP_HOST_PORT is empty/80. Only append the port when needed.
HAS_PORT := $(shell echo '$(APP_URL)' | grep -cE ':[0-9]+$$')
ifeq ($(APP_HOST_PORT),)
DISPLAY_URL := $(APP_URL)
else ifeq ($(APP_HOST_PORT),80)
DISPLAY_URL := $(APP_URL)
else ifeq ($(HAS_PORT),1)
DISPLAY_URL := $(APP_URL)
else
DISPLAY_URL := $(APP_URL):$(APP_HOST_PORT)
endif

.PHONY: help init build up down restart destroy shell test test-parallel test-js test-all migrate seed fresh rebuild logs tinker pint queue vite npm-install composer-install cache-clear reverb-restart _require-local

# Default target
help: ## Show this help
	@echo "Laravel Starter Kit - Available commands:"
	@echo ""
	@grep -hE '^[a-zA-Z_-]+:.*## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":[^#]*## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

init: ## Initialize .env for a new project
	@[ -f .env ] || cp .env.example .env
	@bash scripts/init-starter.sh

# Docker commands
build: ## Build and start all containers (creates .env if missing)
	@[ -f .env ] || { cp .env.example .env; echo "  → created .env from .env.example"; }
	docker compose up -d --build --renew-anon-volumes
	@echo ""
	@echo "  App:     $(DISPLAY_URL)"
	@echo "  Mailpit: http://localhost:$(MAILPIT_HOST_PORT)"
	@echo "  Reverb:  ws://$(VITE_REVERB_HOST):$(REVERB_HOST_PORT)"
	@echo ""

up: ## Start all containers (creates .env if missing)
	@[ -f .env ] || { cp .env.example .env; echo "  → created .env from .env.example"; }
	docker compose up -d
	@echo ""
	@echo "  App:     $(DISPLAY_URL)"
	@echo "  Mailpit: http://localhost:$(MAILPIT_HOST_PORT)"
	@echo "  Reverb:  ws://$(VITE_REVERB_HOST):$(REVERB_HOST_PORT)"
	@echo ""

down: ## Stop all containers
	docker compose down

restart: ## Restart all containers
	docker compose restart

# Guard for destructive targets. Makefiles include .env at the top, so APP_ENV
# is read straight from your local config. Anything that drops data or volumes
# chains on this prerequisite — if APP_ENV isn't `local`, the guard aborts
# before the destructive recipe runs.
_require-local:
	@if [ "$(APP_ENV)" != "local" ]; then \
		echo ""; \
		echo "  ✗ Refusing: APP_ENV is '$(APP_ENV)', not 'local'."; \
		echo "    Destructive targets (destroy/fresh/rebuild) are local-only."; \
		echo "    If you really need this, temporarily set APP_ENV=local in .env."; \
		echo ""; \
		exit 1; \
	fi

destroy: _require-local ## Stop containers and remove volumes (local only)
	docker compose down -v

# App commands
shell: ## Open a shell in the app container
	docker compose exec $(APP_SERVICE) bash

test: ## Run Pest tests
	docker compose exec $(APP_SERVICE) php artisan test

test-parallel: ## Run Pest tests in parallel (paratest; one DB per worker)
	docker compose exec $(APP_SERVICE) php artisan test --parallel

test-js: ## Run Vitest frontend tests
	docker compose exec $(APP_SERVICE) npm test

test-all: ## Run full CI locally (Pint, Larastan, Pest parallel, tsc, Vitest)
	docker compose exec $(APP_SERVICE) vendor/bin/pint --test
	docker compose exec $(APP_SERVICE) vendor/bin/phpstan analyse --memory-limit=1G --no-progress
	docker compose exec $(APP_SERVICE) php artisan test --parallel --compact
	docker compose exec $(APP_SERVICE) npm run typecheck
	docker compose exec $(APP_SERVICE) npm test

migrate: ## Run database migrations
	docker compose exec $(APP_SERVICE) php artisan migrate

seed: ## Run database seeders
	docker compose exec $(APP_SERVICE) php artisan db:seed

fresh: _require-local ## Fresh migrate and seed (local only)
	docker compose exec $(APP_SERVICE) php artisan migrate:fresh --seed

rebuild: _require-local ## Reset starter kit to a clean slate: drop tenant schemas + migrate:fresh --seed + clear caches (local only)
	@echo ""
	@echo "  → Dropping leftover tenant<id> Postgres schemas"
	docker compose exec $(APP_SERVICE) php artisan db:drop-tenant-schemas
	@echo ""
	@echo "  → Running migrate:fresh --seed"
	docker compose exec $(APP_SERVICE) php artisan migrate:fresh --seed
	@echo ""
	@echo "  → Clearing caches"
	docker compose exec $(APP_SERVICE) php artisan optimize:clear
	@echo ""
	@echo "  ✓ Starter kit reset complete."
	@echo ""
	@echo "  App:     $(DISPLAY_URL)"
	@echo "  Mailpit: http://localhost:$(MAILPIT_HOST_PORT)"
	@echo "  Reverb:  ws://$(VITE_REVERB_HOST):$(REVERB_HOST_PORT)"
	@echo ""

rollback: ## Rollback last migration
	docker compose exec $(APP_SERVICE) php artisan migrate:rollback

# Development commands
tinker: ## Open Laravel Tinker
	docker compose exec $(APP_SERVICE) php artisan tinker

pint: ## Run Laravel Pint (code formatter)
	docker compose exec $(APP_SERVICE) ./vendor/bin/pint

stan: ## Run Larastan static analysis
	docker compose exec $(APP_SERVICE) ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress

ide-helper: ## Regenerate IDE helper files (facades, models, meta) for PhpStorm
	docker compose exec $(APP_SERVICE) php artisan ide-helper:generate
	docker compose exec $(APP_SERVICE) php artisan ide-helper:meta
	docker compose exec $(APP_SERVICE) php artisan ide-helper:models --nowrite --reset

backup: ## Run a manual backup
	docker compose exec $(APP_SERVICE) php artisan backup:run

backup-clean: ## Clean old backups
	docker compose exec $(APP_SERVICE) php artisan backup:clean

logs: ## Show app container logs
	docker compose logs -f $(APP_SERVICE)

logs-all: ## Show all container logs
	docker compose logs -f

queue: ## Start queue worker
	docker compose exec $(APP_SERVICE) php artisan queue:work

reverb-restart: ## Restart Laravel Reverb
	docker compose exec $(APP_SERVICE) php artisan reverb:restart

cache-clear: ## Clear all caches
	docker compose exec $(APP_SERVICE) php artisan optimize:clear

# Install commands
composer-install: ## Run composer install in container
	docker compose exec $(APP_SERVICE) composer install

npm-install: ## Run npm install in container
	docker compose exec $(APP_SERVICE) npm install

npm-build: ## Build frontend assets
	docker compose exec $(APP_SERVICE) npm run build

vite: ## Run Vite dev server
	docker compose exec $(APP_SERVICE) npm run dev

# Database commands
db-shell: ## Open PostgreSQL shell
	docker compose exec $(DB_SERVICE) psql -U $(DB_USERNAME) -d $(DB_DATABASE)

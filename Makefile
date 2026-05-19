# Arovolife platform — common dev tasks.
#
# All targets are idempotent. Targets that mutate the docker-running dev DB
# stop and warn before proceeding (per CLAUDE.md global rule on destructive
# actions); the underlying commands surface their own prompts.

.PHONY: help build dev reset test pint stan migrate up down logs sh tinker

DOCKER_APP := docker exec arovolife-app
NPM_DIR    := app

help: ## Show this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# ── Frontend assets ────────────────────────────────────────────────────────

build: ## Rebuild Tailwind/Vite assets (run after Blade class changes)
	@cd $(NPM_DIR) && npm run build

dev: ## Start Vite dev server with HMR (auto-rebuild on file change)
	@cd $(NPM_DIR) && npm run dev

# ── Platform reset / DB ────────────────────────────────────────────────────

reset: ## Wipe transactional data + rebuild the 31 reserved distributors (interactive)
	@$(DOCKER_APP) php artisan platform:reset

reset-force: ## Same as reset but skip the y/n prompt (use with care)
	@$(DOCKER_APP) php artisan platform:reset --force

migrate: ## Run pending Laravel migrations against the dev MySQL
	@$(DOCKER_APP) php artisan migrate

# ── Tests / static analysis ────────────────────────────────────────────────

test: ## Run the full Pest suite (SQLite :memory:, dev DB untouched)
	@$(DOCKER_APP) php artisan test

pint: ## Lint PHP files (Laravel Pint)
	@$(DOCKER_APP) ./vendor/bin/pint

stan: ## Run Larastan at level 7
	@$(DOCKER_APP) ./vendor/bin/phpstan analyse --level=7

# ── Docker convenience ─────────────────────────────────────────────────────

up: ## Start the docker stack in the background
	@docker compose -f docker/docker-compose.yml up -d

down: ## Stop the docker stack (volumes preserved)
	@docker compose -f docker/docker-compose.yml down

logs: ## Tail the app container logs
	@docker compose -f docker/docker-compose.yml logs -f app

sh: ## Open a shell inside the app container
	@$(DOCKER_APP) sh

tinker: ## Open a Laravel tinker REPL against the dev DB
	@$(DOCKER_APP) php artisan tinker

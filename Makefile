DOCKER_COMPOSE = docker compose
PHP = $(DOCKER_COMPOSE) exec app php
COMPOSER = $(DOCKER_COMPOSE) exec app composer
CONSOLE = $(PHP) bin/console

PHP_LOCAL = php
CONSOLE_LOCAL = $(PHP_LOCAL) bin/console

.DEFAULT_GOAL = help
.PHONY: help build up down restart logs shell \
        install cc migrate diff fixtures \
        test test-db lint stan

##@ Docker
build: ## Build images
	$(DOCKER_COMPOSE) build

up: ## Start containers
	$(DOCKER_COMPOSE) up -d
	
ps: ## List containers
	$(DOCKER_COMPOSE) ps

down: ## Stop containers
	$(DOCKER_COMPOSE) down

restart: down up ## Restart containers

logs: ## Follow logs
	$(DOCKER_COMPOSE) logs -f

shell: ## Open a shell in app container
	$(DOCKER_COMPOSE) exec app sh

##@ Symfony
install: ## Install PHP dependencies
	$(COMPOSER) install

cc: ## Clear cache
	$(CONSOLE) cache:clear

##@ Database
migrate: ## Run migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

diff: ## Generate a migration from entity diff
	$(CONSOLE) doctrine:migrations:diff

fixtures: ## Load fixtures
	$(CONSOLE) doctrine:fixtures:load --no-interaction

##@ Quality
test: ## Run tests (crée/met à jour le schéma de test si besoin + phpunit)
	$(CONSOLE_LOCAL) --env=test doctrine:database:create --if-not-exists
	$(CONSOLE_LOCAL) --env=test doctrine:schema:update --force --complete
	$(PHP_LOCAL) bin/phpunit

test-db: ## Recréer la BDD de test from scratch
	$(CONSOLE_LOCAL) --env=test doctrine:database:drop --force --if-exists
	$(CONSOLE_LOCAL) --env=test doctrine:database:create
	$(CONSOLE_LOCAL) --env=test doctrine:schema:create

lint: ## Lint twig & yaml
	$(CONSOLE) lint:twig templates/
	$(CONSOLE) lint:yaml config/

##@ Help
help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} \
	/^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 } \
	/^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) }' $(MAKEFILE_LIST)

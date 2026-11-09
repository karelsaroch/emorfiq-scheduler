export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

DC  := docker compose
PHP := $(DC) exec -T php

.PHONY: help up install test test-unit test-integration test-laravel e2e e2e-up e2e-logs e2e-report e2e-assert e2e-down shell down

help: ## Přehled příkazů
	@grep -E '^[a-z0-9-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

up: ## Build image + start PHP, MySQL, PostgreSQL, Redis
	$(DC) up -d --build php

install: up ## composer install
	$(PHP) composer install --no-interaction --no-progress

test: install ## Všechny PHPUnit testy (unit + integration + laravel)
	$(PHP) vendor/bin/phpunit

test-unit: ## Unit testy (bez externích služeb)
	$(PHP) vendor/bin/phpunit --testsuite unit

test-integration: ## Mutex proti MySQL/PostgreSQL/Redis/flock + souběžné procesy
	$(PHP) vendor/bin/phpunit --testsuite integration

test-laravel: ## Laravel bridge (Orchestra Testbench)
	$(PHP) vendor/bin/phpunit --testsuite laravel

e2e: install ## E2E: 2 uzly, reálné minutové ticky, ověření invariantů (~E2E_MINUTES+1 min)
	./docker/e2e.sh

e2e-up: install ## Spustí e2e cluster a nechá ho běžet
	$(DC) --profile e2e up -d --force-recreate e2e-init node1 node2

e2e-logs: ## Živé logy uzlů
	$(DC) --profile e2e logs -f node1 node2

e2e-report: ## Tabulka: co kde v které minutě běželo
	$(DC) --profile e2e exec -T -w /app/e2e/app node1 php artisan e2e:report

e2e-assert: ## Ověření invariantů nad běžícím clusterem
	$(DC) --profile e2e exec -T -w /app/e2e/app node1 php artisan e2e:assert

e2e-down: ## Zastaví e2e uzly
	$(DC) --profile e2e stop node1 node2

shell: ## Shell v PHP kontejneru
	$(DC) exec php bash

down: ## Zastaví a smaže vše (DB běží v tmpfs – data se zahodí)
	$(DC) --profile e2e down --remove-orphans

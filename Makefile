DC = docker compose
APP = $(DC) exec app

.PHONY: setup up down logs shell migrate fresh seed test

setup:            ## First run: build, install, migrate
	cp -n .env.example .env || true
	$(DC) up -d --build
	$(APP) composer install
	$(APP) php artisan key:generate
	$(APP) php artisan migrate --force

up:
	$(DC) up -d

down:
	$(DC) down

logs:
	$(DC) logs -f app nginx

shell:
	$(APP) bash

migrate:
	$(APP) php artisan migrate

fresh:            ## Rebuild the database with demo data
	$(APP) php artisan migrate:fresh --seed

seed:
	$(APP) php artisan db:seed

test:             ## Run the test suite against the daylist_test MySQL database
	$(DC) exec -e DB_DATABASE=daylist_test app php artisan test

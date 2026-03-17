.PHONY: build up test composer-install shell

build:
	docker compose up -d --build

up:
	docker compose up -d

shell:
	docker compose exec workspace bash

composer-install:
	docker compose exec workspace composer install

test:
	docker compose exec workspace vendor/bin/phpunit
